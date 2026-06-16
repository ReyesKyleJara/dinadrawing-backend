<?php

namespace App\Http\Controllers;

use App\Mail\EmailVerificationMail;
use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Services\EmailReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'string',
                'min:3',
                'max:20',
                'regex:/^[A-Za-z0-9._]+$/',
                'unique:users,username',
            ],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = DB::transaction(function () use ($validated): User {
            return User::create([
                'name' => trim($validated['name']),
                'username' => mb_strtolower(ltrim(trim($validated['username']), '@')),
                'email' => mb_strtolower(trim($validated['email'])),
                'password' => Hash::make($validated['password']),
            ]);
        });

        $emailSent = $this->issueVerificationCode($user);
        $token = $user->createToken('dinadrawing-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => $emailSent
                ? 'Registration successful. Check your email for the verification code.'
                : 'Registration successful, but the verification email could not be sent yet.',
            'requires_email_verification' => true,
            'email_sent' => $emailSent,
            'user' => $this->serializeUser($user),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $login = trim($validated['login']);

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($login)])
            ->orWhereRaw('LOWER(username) = ?', [mb_strtolower(ltrim($login, '@'))])
            ->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['Invalid username/email or password.'],
            ]);
        }

        $token = $user->createToken('dinadrawing-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'requires_email_verification' => $user->email_verified_at === null,
            'user' => $this->serializeUser($user),
            'token' => $token,
        ]);
    }

    /**
     * Accept a Firebase ID token obtained by the Flutter client after
     * Google sign-in, validate it using the Firebase Auth REST API,
     * then issue the app's normal Sanctum token.
     */
    public function googleLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_token' => ['required', 'string'],
            'username' => [
                'nullable',
                'string',
                'min:3',
                'max:20',
                'regex:/^[A-Za-z0-9._]+$/',
            ],
        ]);

        $firebaseKey = trim((string) config('services.firebase.web_api_key'));

        if ($firebaseKey === '') {
            return response()->json([
                'success' => false,
                'message' => 'Firebase login is not configured on the backend.',
            ], 503);
        }

        $response = Http::asJson()
            ->timeout(15)
            ->post(
                'https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=' .
                    urlencode($firebaseKey),
                ['idToken' => $validated['id_token']]
            );

        if (!$response->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'Google sign-in could not be verified. Please sign in again.',
            ], 401);
        }

        $firebaseUser = $response->json('users.0');

        if (!is_array($firebaseUser)) {
            return response()->json([
                'success' => false,
                'message' => 'Firebase did not return a valid user account.',
            ], 401);
        }

        $providerIds = collect($firebaseUser['providerUserInfo'] ?? [])
            ->pluck('providerId')
            ->filter()
            ->all();

        if (!in_array('google.com', $providerIds, true)) {
            return response()->json([
                'success' => false,
                'message' => 'This Firebase account is not linked to Google.',
            ], 422);
        }

        $firebaseUid = trim((string) ($firebaseUser['localId'] ?? ''));
        $email = mb_strtolower(trim((string) ($firebaseUser['email'] ?? '')));

        if ($firebaseUid === '' || $email === '') {
            return response()->json([
                'success' => false,
                'message' => 'Google did not provide the required account information.',
            ], 422);
        }

        $requestedUsername = isset($validated['username'])
            ? mb_strtolower(ltrim(trim((string) $validated['username']), '@'))
            : null;

        $user = DB::transaction(function () use (
            $firebaseUid,
            $firebaseUser,
            $email,
            $requestedUsername
        ): User {
            $user = User::query()
                ->where(function ($query) use ($firebaseUid, $email): void {
                    $query->where(function ($oauthQuery) use ($firebaseUid): void {
                        $oauthQuery
                            ->where('oauth_provider', 'google')
                            ->where('oauth_uid', $firebaseUid);
                    })->orWhereRaw('LOWER(email) = ?', [$email]);
                })
                ->first();

            if ($requestedUsername !== null) {
                $usernameTaken = User::query()
                    ->whereRaw('LOWER(username) = ?', [$requestedUsername])
                    ->when($user !== null, fn ($query) => $query->where('id', '!=', $user->id))
                    ->exists();

                if ($usernameTaken) {
                    throw ValidationException::withMessages([
                        'username' => ['Username already exists. Try another.'],
                    ]);
                }
            }

            $name = trim((string) ($firebaseUser['displayName'] ?? ''));
            if ($name === '') {
                $name = Str::headline(Str::before($email, '@'));
            }

            $username = $requestedUsername;
            if ($username === null || $username === '') {
                $username = $user?->username ?? $this->generateAvailableUsername(
                    Str::before($email, '@')
                );
            }

            if ($user === null) {
                $user = User::create([
                    'name' => $name,
                    'username' => $username,
                    'email' => $email,
                    'password' => Hash::make(Str::random(64)),
                    'email_verified_at' => now(),
                    'oauth_provider' => 'google',
                    'oauth_uid' => $firebaseUid,
                    'oauth_avatar_url' => $firebaseUser['photoUrl'] ?? null,
                ]);
            } else {
                $user->forceFill([
                    'name' => $user->name ?: $name,
                    'username' => $username,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                    'oauth_provider' => 'google',
                    'oauth_uid' => $firebaseUid,
                    'oauth_avatar_url' => $firebaseUser['photoUrl'] ?? $user->oauth_avatar_url,
                ])->save();
            }

            return $user->fresh();
        });

        $token = $user->createToken('dinadrawing-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Google sign-in successful.',
            'requires_email_verification' => false,
            'user' => $this->serializeUser($user),
            'token' => $token,
        ]);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'No account was found for this email.',
            ], 404);
        }

        if ($user->email_verified_at !== null) {
            return response()->json([
                'success' => true,
                'message' => 'Your email is already verified.',
                'user' => $this->serializeUser($user),
            ]);
        }

        $verification = EmailVerificationCode::query()
            ->where('user_id', $user->id)
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if (!$verification || $verification->expires_at->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'The verification code has expired. Request a new one.',
            ], 422);
        }

        if ($verification->attempts >= 5) {
            return response()->json([
                'success' => false,
                'message' => 'Too many incorrect attempts. Request a new code.',
            ], 429);
        }

        if (!Hash::check($validated['code'], $verification->code_hash)) {
            $verification->increment('attempts');

            return response()->json([
                'success' => false,
                'message' => 'Incorrect verification code.',
                'attempts_remaining' => max(0, 4 - $verification->attempts),
            ], 422);
        }

        DB::transaction(function () use ($user, $verification): void {
            $verification->update(['verified_at' => now()]);
            $user->forceFill(['email_verified_at' => now()])->save();
        });

        return response()->json([
            'success' => true,
            'message' => 'Email verified successfully.',
            'user' => $this->serializeUser($user->fresh()),
        ]);
    }

    public function resendVerificationCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'No account was found for this email.',
            ], 404);
        }

        if ($user->email_verified_at !== null) {
            return response()->json([
                'success' => true,
                'message' => 'Your email is already verified.',
            ]);
        }

        $latest = EmailVerificationCode::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        if ($latest && $latest->created_at->gt(now()->subMinute())) {
            return response()->json([
                'success' => false,
                'message' => 'Please wait one minute before requesting another code.',
            ], 429);
        }

        $sent = $this->issueVerificationCode($user);

        return response()->json([
            'success' => $sent,
            'message' => $sent
                ? 'A new verification code was sent.'
                : 'The verification email could not be sent. Check the mail configuration.',
        ], $sent ? 200 : 500);
    }

    public function enableEmailReminders(Request $request): JsonResponse
    {
        $user = $request->user();

        if (
            $user->email_verified_at === null &&
            trim((string) $user->oauth_provider) === ''
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Verify your email before enabling email reminders.',
            ], 422);
        }

        EmailReminderService::enableEmailReminders($user);

        return response()->json([
            'success' => true,
            'message' => 'Email reminders enabled.',
            'email_reminders' => true,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout successful',
        ]);
    }

    private function issueVerificationCode(User $user): bool
    {
        if ($user->email_verified_at !== null) {
            return true;
        }

        $plainCode = (string) random_int(100000, 999999);

        EmailVerificationCode::query()
            ->where('user_id', $user->id)
            ->whereNull('verified_at')
            ->delete();

        EmailVerificationCode::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'code_hash' => Hash::make($plainCode),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
        ]);

        try {
            Mail::to($user->email)->send(
                new EmailVerificationMail(
                    $plainCode,
                    $user->name,
                    trim((string) config('app.frontend_url'))
                )
            );

            return true;
        } catch (Throwable $error) {
            report($error);
            return false;
        }
    }

    private function generateAvailableUsername(string $seed): string
    {
        $base = mb_strtolower((string) preg_replace(
            '/[^A-Za-z0-9._]/',
            '',
            $seed
        ));

        if (mb_strlen($base) < 3) {
            $base = 'user';
        }

        $base = mb_substr($base, 0, 15);
        $candidate = $base;
        $suffix = 0;

        while (User::query()->whereRaw('LOWER(username) = ?', [$candidate])->exists()) {
            $suffix++;
            $candidate = mb_substr($base, 0, 15) . $suffix;
        }

        return $candidate;
    }

    private function serializeUser(User $user): array
    {
        $photoUrl = null;

        if (
            is_string($user->profile_photo_path) &&
            trim($user->profile_photo_path) !== ''
        ) {
            $photoUrl = url(Storage::url($user->profile_photo_path));
        } elseif (
            is_string($user->oauth_avatar_url) &&
            trim($user->oauth_avatar_url) !== ''
        ) {
            $photoUrl = $user->oauth_avatar_url;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'email_verified_at' => $user->email_verified_at,
            'profile_photo_path' => $user->profile_photo_path,
            'profile_photo_url' => $photoUrl,
            'oauth_provider' => $user->oauth_provider,
            'email_reminders' => (bool) $user->email_reminders,
            'push_notifications' => (bool) $user->push_notifications,
            'in_app_alerts' => (bool) $user->in_app_alerts,
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;

class UserSettingsController extends Controller
{
    /**
     * Return the current user's profile and settings.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'user' => $this->formatUser($user),
        ]);
    }

    /**
     * Update the current user's profile.
     *
     * This endpoint updates:
     * - Name
     * - Username
     * - Profile photo
     *
     * The username is checked against the database before saving.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'min:2',
                'max:100',
            ],

            'username' => [
                'required',
                'string',
                'min:3',
                'max:20',
                'regex:/^[A-Za-z0-9._]+$/',
            ],

            'photo' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],

            'remove_photo' => [
                'nullable',
                'boolean',
            ],
        ], [
            'name.required' => 'Please enter your name.',
            'name.min' => 'Name must contain at least 2 characters.',

            'username.required' => 'Please enter a username.',
            'username.min' => 'Username must contain at least 3 characters.',
            'username.max' => 'Username may not exceed 20 characters.',
            'username.regex' =>
                'Username may only contain letters, numbers, periods, and underscores.',

            'photo.image' => 'The selected file must be an image.',
            'photo.max' => 'The profile photo must not exceed 5 MB.',
        ]);

        $user = $request->user();

        $username = $this->normalizeUsername(
            $validated['username'],
        );

        if ($this->usernameExistsForAnotherUser(
            username: $username,
            userId: $user->id,
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Username already exists. Try another.',
                'errors' => [
                    'username' => [
                        'Username already exists. Try another.',
                    ],
                ],
            ], 422);
        }

        $currentUsername = $this->normalizeUsername(
            $user->username ?? '',
        );

        $usernameChanged =
            mb_strtolower($username) !==
            mb_strtolower($currentUsername);

        $user->name = trim($validated['name']);
        $user->username = $username;

        if ($usernameChanged) {
            $user->username_changed_at = now();
        }

        if ($request->boolean('remove_photo')) {
            $this->deleteOldProfilePhoto($user);

            $user->profile_photo_path = null;
        }

        if ($request->hasFile('photo')) {
            $this->deleteOldProfilePhoto($user);

            $user->profile_photo_path = $request
                ->file('photo')
                ->store('profile-photos', 'public');
        }

        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'user' => $this->formatUser($user->fresh()),
        ]);
    }

    /**
     * Check whether a username is available.
     *
     * This route may be used by:
     * - Sign Up
     * - Profile editing
     * - Google account setup
     */
    public function checkUsername(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => [
                'required',
                'string',
                'min:3',
                'max:20',
                'regex:/^[A-Za-z0-9._]+$/',
            ],
        ], [
            'username.required' => 'Please enter a username.',
            'username.min' => 'Username must contain at least 3 characters.',
            'username.max' => 'Username may not exceed 20 characters.',
            'username.regex' =>
                'Username may only contain letters, numbers, periods, and underscores.',
        ]);

        $username = $this->normalizeUsername(
            $validated['username'],
        );

        /*
         * The endpoint is public because Sign Up also uses it.
         *
         * When an authenticated user sends a Sanctum token,
         * exclude their own account from the username check.
         */
        $authenticatedUser = Auth::guard('sanctum')->user();

        $userId = $authenticatedUser?->id;

        $exists = $this->usernameExistsForAnotherUser(
            username: $username,
            userId: $userId,
        );

        return response()->json([
            'success' => true,
            'available' => !$exists,
            'username' => $username,
            'message' => $exists
                ? 'Username already exists. Try another.'
                : 'Username is available.',
        ]);
    }

    /**
     * Kept temporarily for backward compatibility.
     *
     * Settings will now update the username through updateProfile().
     */
    public function updateUsername(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => [
                'required',
                'string',
                'min:3',
                'max:20',
                'regex:/^[A-Za-z0-9._]+$/',
            ],
        ], [
            'username.required' => 'Please enter a username.',
            'username.min' => 'Username must contain at least 3 characters.',
            'username.max' => 'Username may not exceed 20 characters.',
            'username.regex' =>
                'Username may only contain letters, numbers, periods, and underscores.',
        ]);

        $user = $request->user();

        $username = $this->normalizeUsername(
            $validated['username'],
        );

        if ($this->usernameExistsForAnotherUser(
            username: $username,
            userId: $user->id,
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Username already exists. Try another.',
                'errors' => [
                    'username' => [
                        'Username already exists. Try another.',
                    ],
                ],
            ], 422);
        }

        $currentUsername = $this->normalizeUsername(
            $user->username ?? '',
        );

        if (
            mb_strtolower($username) ===
            mb_strtolower($currentUsername)
        ) {
            return response()->json([
                'success' => true,
                'message' => 'Username is unchanged.',
                'user' => $this->formatUser($user),
            ]);
        }

        $user->username = $username;
        $user->username_changed_at = now();
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Username updated successfully.',
            'user' => $this->formatUser($user->fresh()),
        ]);
    }

    /**
     * Save the current user's notification preferences.
     */
    public function updateNotifications(
        Request $request,
    ): JsonResponse {
        $validated = $request->validate([
            'email_reminders' => [
                'required',
                'boolean',
            ],

            'push_notifications' => [
                'required',
                'boolean',
            ],

            'in_app_alerts' => [
                'required',
                'boolean',
            ],
        ]);

        $user = $request->user();

        if (
            $validated['email_reminders'] === true &&
            $user->email_verified_at === null
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Verify your email to enable email reminders.',
                'errors' => [
                    'email_reminders' => [
                        'Verify your email to enable email reminders.',
                    ],
                ],
            ], 422);
        }

        $user->email_reminders =
            $validated['email_reminders'];

        $user->push_notifications =
            $validated['push_notifications'];

        $user->in_app_alerts =
            $validated['in_app_alerts'];

        $user->save();

        return response()->json([
            'success' => true,
            'message' =>
                'Notification preferences updated successfully.',
            'notifications' => [
                'email_reminders' =>
                    (bool) $user->email_reminders,

                'push_notifications' =>
                    (bool) $user->push_notifications,

                'in_app_alerts' =>
                    (bool) $user->in_app_alerts,
            ],
        ]);
    }

    /**
     * Change the current user's password.
     */
    public function changePassword(
        Request $request,
    ): JsonResponse {
        $validated = $request->validate([
            'current_password' => [
                'required',
                'string',
            ],

            'password' => [
                'required',
                'confirmed',
                'max:20',
                Password::min(8)
                    ->letters()
                    ->numbers()
                    ->symbols(),
            ],
        ], [
            'current_password.required' =>
                'Please enter your current password.',

            'password.required' =>
                'Please enter a new password.',

            'password.confirmed' =>
                'The new password confirmation does not match.',

            'password.max' =>
                'The password may not exceed 20 characters.',
        ]);

        $user = $request->user();

        if (!Hash::check(
            $validated['current_password'],
            $user->password,
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect.',
                'errors' => [
                    'current_password' => [
                        'Current password is incorrect.',
                    ],
                ],
            ], 422);
        }

        if (Hash::check(
            $validated['password'],
            $user->password,
        )) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Your new password must be different from your current password.',
                'errors' => [
                    'password' => [
                        'Enter a different password.',
                    ],
                ],
            ], 422);
        }

        $user->password = Hash::make(
            $validated['password'],
        );

        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully.',
        ]);
    }

    /**
     * Check whether a username belongs to another user.
     */
    private function usernameExistsForAnotherUser(
        string $username,
        ?int $userId = null,
    ): bool {
        $query = User::query()
            ->whereRaw(
                'LOWER(username) = ?',
                [mb_strtolower($username)],
            );

        if ($userId !== null) {
            $query->where('id', '!=', $userId);
        }

        return $query->exists();
    }

    /**
     * Remove the user's previously stored profile photo.
     */
    private function deleteOldProfilePhoto(User $user): void
    {
        $path = $user->profile_photo_path;

        if (
            is_string($path) &&
            trim($path) !== '' &&
            Storage::disk('public')->exists($path)
        ) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * Normalize usernames before checking or saving them.
     */
    private function normalizeUsername(
        ?string $username,
    ): string {
        return mb_strtolower(
            ltrim(
                trim($username ?? ''),
                '@',
            ),
        );
    }

    /**
     * Return a consistent user object for the frontend.
     */
    private function formatUser(User $user): array
    {
        $photoUrl = null;

        if (
            is_string($user->profile_photo_path) &&
            trim($user->profile_photo_path) !== ''
        ) {
            $photoUrl = url(
                Storage::url(
                    $user->profile_photo_path,
                ),
            );
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,

            'email_verified' =>
                $user->email_verified_at !== null,

            'email_verified_at' =>
                $user->email_verified_at,

            'profile_photo_path' =>
                $user->profile_photo_path,

            'profile_photo_url' =>
                $photoUrl,

            'username_changed_at' =>
                $user->username_changed_at,

            'email_reminders' =>
                (bool) $user->email_reminders,

            'push_notifications' =>
                (bool) $user->push_notifications,

            'in_app_alerts' =>
                (bool) $user->in_app_alerts,
        ];
    }
}
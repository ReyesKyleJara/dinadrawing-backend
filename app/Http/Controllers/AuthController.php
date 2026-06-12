<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $token = $user->createToken('dinadrawing-token')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful',
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['login'])
            ->orWhere('username', $validated['login'])
            ->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['Invalid username/email or password.'],
            ]);
        }

        $token = $user->createToken('dinadrawing-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function updateProfile(Request $request)
    {
        Log::info('Profile update started');

        $user = $request->user();

        try {
            $photoFile = $request->file('photo') ?? $request->file('profile_picture');

            $hasPhotoFile = $request->hasFile('photo') || $request->hasFile('profile_picture');

            $validated = $request->validate([
                'name' => ['nullable', 'string', 'max:255'],
                'username' => ['nullable', 'string', 'max:255', 'unique:users,username,' . $user->id],
                'profile_picture' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
                'photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            ]);

            $name = trim((string) ($validated['name'] ?? $user->name));
            $username = trim(str_replace('@', '', (string) ($validated['username'] ?? $user->username)));

            if ($name === '' && $username === '' && !$hasPhotoFile) {
                throw ValidationException::withMessages([
                    'name' => ['At least one of name, username, or photo is required.'],
                ]);
            }


            $user->fill([
                'name' => $name,
                'username' => $username,
            ]);

            if ($photoFile instanceof \Illuminate\Http\UploadedFile) {
                $path = Storage::disk('public')->putFile('profile_pictures', $photoFile);
                $user->photo_path = $path;
                $user->photo_url = Storage::disk('public')->url($path);
                Log::info('Profile update image upload path', [
                    'stored_path' => $path,
                    'public_url' => $user->photo_url,
                ]);
            }

            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'user' => $user->fresh(),
            ], 200);
        } catch (ValidationException $e) {
            Log::warning('Profile update validation failed', [
                'errors' => $e->errors(),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => $e->errors(),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Profile update failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Profile update failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        return response()->json([
            'message' => 'Password changed successfully',
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout successful',
        ]);
    }
}
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuthProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_update_allows_username_only_update_and_keeps_existing_name(): void
    {
        $user = User::create([
            'name' => 'Old Name',
            'username' => 'olduser',
            'email' => 'old@example.com',
            'password' => bcrypt('secret1234'),
        ]);

        $response = $this->actingAs($user, 'sanctum')->json('PUT', '/api/user/profile', [
            'username' => 'newuser',
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $user->refresh();

        $this->assertSame('Old Name', $user->name);
        $this->assertSame('newuser', $user->username);
    }

    public function test_profile_update_accepts_photo_only_and_preserves_existing_profile_fields(): void
    {
        $user = User::create([
            'name' => 'Old Name',
            'username' => 'olduser',
            'email' => 'old@example.com',
            'password' => bcrypt('secret1234'),
        ]);

        $response = $this->actingAs($user, 'sanctum')->call(
            'PUT',
            '/api/user/profile',
            [],
            [],
            [
                'photo' => UploadedFile::fake()->create('avatar.jpg', 1, 'image/jpeg'),
            ],
            [
                'Accept' => 'application/json',
            ]
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $user->refresh();

        $this->assertSame('Old Name', $user->name);
        $this->assertSame('olduser', $user->username);
        $this->assertNotNull($user->photo_path);
        $this->assertNotNull($user->photo_url);
        $this->assertTrue(Storage::disk('public')->exists($user->photo_path));
    }

    public function test_profile_update_accepts_photo_and_persists_photo_fields(): void
    {
        $user = User::create([
            'name' => 'Old Name',
            'username' => 'olduser',
            'email' => 'old@example.com',
            'password' => bcrypt('secret1234'),
        ]);

        $response = $this->actingAs($user, 'sanctum')->call(
            'PUT',
            '/api/user/profile',
            [
                'name' => 'New Name',
                'username' => 'newuser',
            ],
            [],
            [
                'photo' => UploadedFile::fake()->create('avatar.jpg', 1, 'image/jpeg'),
            ],
            [
                'Accept' => 'application/json',
            ]
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $user->refresh();

        $this->assertSame('New Name', $user->name);
        $this->assertSame('newuser', $user->username);
        $this->assertNotNull($user->photo_path);
        $this->assertNotNull($user->photo_url);
        $this->assertTrue(Storage::disk('public')->exists($user->photo_path));
        $this->assertStringContainsString('/storage/profile_pictures/', $user->photo_url);
    }
}

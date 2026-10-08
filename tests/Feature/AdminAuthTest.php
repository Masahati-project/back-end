<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['role' => 'admin', 'status' => 'active', 'verified_at' => now()], $overrides));
    }

    private function createCustomer(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['role' => 'customer', 'status' => 'active'], $overrides));
    }

    private function token(User $user): string
    {
        return $user->createToken('auth_token')->plainTextToken;
    }

    // -------------------------------------------------------------------------
    // POST /api/admin/profile/picture
    // -------------------------------------------------------------------------

    public function test_an_admin_can_upload_a_profile_picture(): void
    {
        Storage::fake('cloudinary');

        $admin = $this->createAdmin();
        $token = $this->token($admin);

        $file = UploadedFile::fake()->image('photo.jpg', 200, 200);

        $response = $this->withToken($token)
            ->postJson('/api/admin/profile/picture', ['profile_picture' => $file]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'email',
                    'whatsapp',
                    'role',
                    'profile_picture_url'
                ]
            ]);

        $this->assertSame('admin', $response->json('data.role'));
        $this->assertNotNull($response->json('data.profile_picture_url'));
        $this->assertNotNull($admin->fresh()->profile_picture_url);
        Storage::disk('cloudinary')->assertExists($admin->fresh()->profile_picture_url);
    }

    public function test_admin_upload_picture_replaces_existing(): void
    {
        Storage::fake('cloudinary');

        $oldFile = UploadedFile::fake()->image('old.jpg');
        $oldPath = $oldFile->store('profile-pictures', 'cloudinary');

        $admin = $this->createAdmin(['profile_picture_url' => $oldPath]);
        $token = $this->token($admin);

        Storage::disk('cloudinary')->assertExists($oldPath);

        $newFile = UploadedFile::fake()->image('new.jpg', 300, 300);

        $response = $this->withToken($token)
            ->postJson('/api/admin/profile/picture', ['profile_picture' => $newFile]);

        $response->assertStatus(200);

        Storage::disk('cloudinary')->assertMissing($oldPath);
        $this->assertNotEquals($oldPath, $admin->fresh()->profile_picture_url);
    }

    public function test_admin_upload_picture_works_when_no_previous_picture(): void
    {
        Storage::fake('cloudinary');

        $admin = $this->createAdmin(['profile_picture_url' => null]);
        $token = $this->token($admin);

        $file = UploadedFile::fake()->image('avatar.png', 200, 200);

        $response = $this->withToken($token)
            ->postJson('/api/admin/profile/picture', ['profile_picture' => $file]);

        $response->assertStatus(200);
        $this->assertNotNull($admin->fresh()->profile_picture_url);
    }

    public function test_admin_upload_picture_fails_without_file(): void
    {
        Storage::fake('cloudinary');

        $admin = $this->createAdmin();
        $token = $this->token($admin);

        $this->withToken($token)
            ->postJson('/api/admin/profile/picture', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['profile_picture']);
    }

    public function test_admin_upload_picture_rejects_non_image_file(): void
    {
        Storage::fake('cloudinary');

        $admin = $this->createAdmin();
        $token = $this->token($admin);

        $file = UploadedFile::fake()->create('document.pdf', 500, 'application/pdf');

        $this->withToken($token)
            ->postJson('/api/admin/profile/picture', ['profile_picture' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['profile_picture']);
    }

    public function test_admin_upload_picture_rejects_file_over_2mb(): void
    {
        Storage::fake('cloudinary');

        $admin = $this->createAdmin();
        $token = $this->token($admin);

        $file = UploadedFile::fake()->image('large.jpg')->size(3000); // 3 MB

        $this->withToken($token)
            ->postJson('/api/admin/profile/picture', ['profile_picture' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['profile_picture']);
    }

    public function test_admin_upload_picture_requires_authentication(): void
    {
        $this->postJson('/api/admin/profile/picture', [])->assertStatus(401);
    }

    public function test_admin_upload_picture_requires_admin_role(): void
    {
        Storage::fake('cloudinary');

        $customer = $this->createCustomer();
        $token = $this->token($customer);

        $file = UploadedFile::fake()->image('photo.jpg', 200, 200);

        $this->withToken($token)
            ->postJson('/api/admin/profile/picture', ['profile_picture' => $file])
            ->assertStatus(403);
    }
}
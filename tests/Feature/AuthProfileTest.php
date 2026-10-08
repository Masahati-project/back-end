<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuthProfileTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(array $overrides = []): User
    {
        return User::factory()->create($overrides);
    }

    // -------------------------------------------------------------------------
    // deleteAccount
    // -------------------------------------------------------------------------

    public function test_delete_account_removes_user_and_tokens(): void
    {
        Storage::fake('cloudinary');

        $user = $this->createUser();
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withToken($token)
            ->deleteJson('/api/delete-user');

        $response->assertStatus(200)
            ->assertJson(['message' => 'تم حذف الحساب بنجاح']);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    public function test_delete_account_deletes_profile_picture_from_storage(): void
    {
        Storage::fake('cloudinary');

        $fakePicture = UploadedFile::fake()->image('avatar.jpg');
        $picturePath = $fakePicture->store('profile-pictures', 'cloudinary');

        $user = $this->createUser(['profile_picture_url' => $picturePath]);
        $token = $user->createToken('auth_token')->plainTextToken;

        Storage::disk('cloudinary')->assertExists($picturePath);

        $this->withToken($token)->deleteJson('/api/delete-user')->assertStatus(200);

        Storage::disk('cloudinary')->assertMissing($picturePath);
    }

    public function test_delete_account_deletes_proof_document_from_storage(): void
    {
        Storage::fake('cloudinary');

        $fakeDoc = UploadedFile::fake()->create('doc.pdf', 100);
        $docPath = $fakeDoc->store('documents', 'cloudinary');

        $user = $this->createUser(['proof_document_url' => $docPath]);
        $token = $user->createToken('auth_token')->plainTextToken;

        Storage::disk('cloudinary')->assertExists($docPath);

        $this->withToken($token)->deleteJson('/api/delete-user')->assertStatus(200);

        Storage::disk('cloudinary')->assertMissing($docPath);
    }

    public function test_delete_account_requires_authentication(): void
    {
        $this->deleteJson('/api/delete-user')->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // uploadPicture
    // -------------------------------------------------------------------------

    public function test_upload_picture_stores_image_and_returns_url(): void
    {
        Storage::fake('cloudinary');

        $user  = $this->createUser();
        $token = $user->createToken('auth_token')->plainTextToken;

        $file = UploadedFile::fake()->image('photo.jpg', 200, 200);

        $response = $this->withToken($token)
            ->postJson('/api/uploadPicture', ['profile_picture' => $file]);

        $response->assertStatus(200)
            ->assertJsonStructure(['message', 'profile_picture_url', 'user']);

        $this->assertNotNull($user->fresh()->profile_picture_url);
        Storage::disk('cloudinary')->assertExists($user->fresh()->profile_picture_url);
    }

    public function test_upload_picture_fails_without_file(): void
    {
        Storage::fake('cloudinary');

        $user  = $this->createUser();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/uploadPicture', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['profile_picture']);
    }

    public function test_upload_picture_rejects_non_image_file(): void
    {
        Storage::fake('cloudinary');

        $user  = $this->createUser();
        $token = $user->createToken('auth_token')->plainTextToken;

        $file = UploadedFile::fake()->create('document.pdf', 500, 'application/pdf');

        $this->withToken($token)
            ->postJson('/api/uploadPicture', ['profile_picture' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['profile_picture']);
    }

    public function test_upload_picture_rejects_file_over_2mb(): void
    {
        Storage::fake('cloudinary');

        $user  = $this->createUser();
        $token = $user->createToken('auth_token')->plainTextToken;

        $file = UploadedFile::fake()->image('large.jpg')->size(3000); // 3 MB

        $this->withToken($token)
            ->postJson('/api/uploadPicture', ['profile_picture' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['profile_picture']);
    }

    public function test_upload_picture_requires_authentication(): void
    {
        $this->postJson('/api/uploadPicture', [])->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // updateProfilePicture
    // -------------------------------------------------------------------------

    public function test_update_profile_picture_replaces_old_image(): void
    {
        Storage::fake('cloudinary');

        $oldFile = UploadedFile::fake()->image('old.jpg');
        $oldPath = $oldFile->store('profile-pictures', 'cloudinary');

        $user  = $this->createUser(['profile_picture_url' => $oldPath]);
        $token = $user->createToken('auth_token')->plainTextToken;

        Storage::disk('cloudinary')->assertExists($oldPath);

        $newFile = UploadedFile::fake()->image('new.jpg', 300, 300);

        $response = $this->withToken($token)
            ->patchJson('/api/profile/picture', ['profile_picture' => $newFile]);

        $response->assertStatus(200)
            ->assertJsonStructure(['message', 'profile_picture_url', 'user']);

        Storage::disk('cloudinary')->assertMissing($oldPath);
        $this->assertNotEquals($oldPath, $user->fresh()->profile_picture_url);
    }

    public function test_update_profile_picture_works_when_no_previous_picture(): void
    {
        Storage::fake('cloudinary');

        $user  = $this->createUser(['profile_picture_url' => null]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $file = UploadedFile::fake()->image('avatar.png', 200, 200);

        $this->withToken($token)
            ->patchJson('/api/profile/picture', ['profile_picture' => $file])
            ->assertStatus(200);

        $this->assertNotNull($user->fresh()->profile_picture_url);
    }

    public function test_update_profile_picture_fails_without_file(): void
    {
        Storage::fake('cloudinary');

        $user  = $this->createUser();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withToken($token)
            ->patchJson('/api/profile/picture', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['profile_picture']);
    }

    public function test_update_profile_picture_rejects_non_image(): void
    {
        Storage::fake('cloudinary');

        $user  = $this->createUser();
        $token = $user->createToken('auth_token')->plainTextToken;

        $file = UploadedFile::fake()->create('doc.pdf', 500, 'application/pdf');

        $this->withToken($token)
            ->patchJson('/api/profile/picture', ['profile_picture' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['profile_picture']);
    }

    public function test_update_profile_picture_requires_authentication(): void
    {
        $this->patchJson('/api/profile/picture', [])->assertStatus(401);
    }
}

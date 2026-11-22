<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\GoogleAuthController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Google sign-in shares one user row with email/password sign-in.
 *
 * The ID token is stubbed by swapping the controller for an anonymous subclass,
 * so these tests exercise the account-linking and picture-persistence rules
 * without a network call to Google.
 */
class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The profile response resolves a stored Cloudinary public id through
        // the disk; fake it so the URL builder never calls the real API.
        Storage::fake('cloudinary');
    }

    private function fakeGoogle(array $claims): void
    {
        $this->instance(GoogleAuthController::class, new class($claims) extends GoogleAuthController
        {
            public function __construct(private array $claimsForTest)
            {
            }

            protected function verifyIdToken(string $idToken)
            {
                return $this->claimsForTest;
            }
        });
    }

    public function test_a_google_login_links_an_existing_password_account_instead_of_duplicating(): void
    {
        $existing = User::factory()->create([
            'email' => 'karam@example.com',   // stored lower-case by registration
            'profile_picture_url' => null,
            'google_id' => null,
        ]);

        $before = User::count();

        $this->fakeGoogle([
            'sub' => 'google-sub-1',
            'email' => 'Karam@Example.com',   // Google may return any casing
            'email_verified' => true,
            'name' => 'Karam',
            'picture' => 'https://lh3.googleusercontent.com/a/photo',
        ]);

        $response = $this->postJson('/api/auth/google', ['id_token' => 'stub']);

        $response->assertStatus(200)
            ->assertJsonPath('user.id', $existing->id)
            ->assertJsonPath('user.picture', 'https://lh3.googleusercontent.com/a/photo');

        $this->assertSame($before, User::count());

        $existing->refresh();
        $this->assertSame('google-sub-1', $existing->google_id);
        $this->assertSame('google', $existing->provider);
        $this->assertSame('https://lh3.googleusercontent.com/a/photo', $existing->profile_picture_url);
    }

    public function test_a_google_login_never_overwrites_an_existing_picture(): void
    {
        $existing = User::factory()->create([
            'email' => 'karam@example.com',
            'profile_picture_url' => 'profile-pictures/uploaded.jpg',
        ]);

        $this->fakeGoogle([
            'sub' => 'google-sub-2',
            'email' => 'karam@example.com',
            'email_verified' => true,
            'name' => 'Karam',
            'picture' => 'https://lh3.googleusercontent.com/a/google-photo',
        ]);

        $this->postJson('/api/auth/google', ['id_token' => 'stub'])->assertStatus(200);

        $this->assertSame('profile-pictures/uploaded.jpg', $existing->fresh()->profile_picture_url);
    }

    public function test_an_unverified_google_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'karam@example.com']);

        $before = User::count();

        $this->fakeGoogle([
            'sub' => 'google-sub-3',
            'email' => 'karam@example.com',
            'email_verified' => false,
            'name' => 'Karam',
        ]);

        $this->postJson('/api/auth/google', ['id_token' => 'stub'])->assertStatus(403);

        $this->assertSame($before, User::count());
    }

    public function test_a_new_google_user_is_created_when_a_role_is_supplied(): void
    {
        $before = User::count();

        $this->fakeGoogle([
            'sub' => 'google-sub-4',
            'email' => 'New@Example.com',
            'email_verified' => true,
            'name' => 'New Person',
            'picture' => 'https://lh3.googleusercontent.com/a/new',
        ]);

        $this->postJson('/api/auth/google', [
            'id_token' => 'stub',
            'role' => 'customer',
        ])
            ->assertStatus(200)
            ->assertJsonPath('user.email', 'new@example.com')
            ->assertJsonPath('user.picture', 'https://lh3.googleusercontent.com/a/new');

        $this->assertSame($before + 1, User::count());

        $this->assertDatabaseHas('users', [
            'email' => 'new@example.com',
            'google_id' => 'google-sub-4',
            'role' => 'customer',
        ]);
    }

    public function test_an_unknown_google_user_without_a_role_is_not_registered(): void
    {
        $before = User::count();

        $this->fakeGoogle([
            'sub' => 'google-sub-5',
            'email' => 'stranger@example.com',
            'email_verified' => true,
            'name' => 'Stranger',
        ]);

        $this->postJson('/api/auth/google', ['id_token' => 'stub'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'NOT_REGISTERED');

        $this->assertSame($before, User::count());
    }

    public function test_an_invalid_google_token_is_rejected(): void
    {
        $this->fakeGoogle([]);

        $this->postJson('/api/auth/google', ['id_token' => 'bad'])->assertStatus(401);
    }
}

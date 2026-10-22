<?php

namespace Tests\Feature;

use App\Models\SpaceOwnerVerification;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A listing's proof file is uploaded like a space image: the file goes to the
 * Cloudinary disk, only the path is stored, and every read path turns that path
 * into a delivery URL.
 *
 * The owner sees their files through the per-space document endpoint; the admin
 * sees the same files on the space detail and the space-scoped document feed.
 */
class SpaceDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => 'active',
            'verified_at' => now(),
        ]);
    }

    private function token(User $user): string
    {
        return $user->createToken('auth_token')->plainTextToken;
    }

    private function space(User $owner, array $overrides = []): Workspace
    {
        return Workspace::create(array_merge([
            'owner_id' => $owner->id,
            'title' => 'قاعة المعرض',
            'description' => 'قاعة للمعارض',
            'location' => 'رام الله',
            'latitude' => 31.9038,
            'longitude' => 35.2034,
            'contact_phone' => '0599123456',
            'status' => 'pending',
            'open_time' => '09:00',
            'close_time' => '17:00',
            'is_closed' => false,
            'is_active' => false,
            'space_document_url' => null,
        ], $overrides));
    }

    private function createPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'مساحة الاختبار',
            'description' => 'وصف مختصر',
            'location' => 'رام الله',
            'lat' => 31.9038,
            'lng' => 35.2034,
            'price_per_hour' => 50,
            'capacity' => 4,
            'amenities' => ['wifi', 'power'],
            'internet' => true,
            'power' => true,
            'open_time' => '08:00',
            'close_time' => '22:00',
            'contact_phone' => '0599123456',
        ], $overrides);
    }

    public function test_the_document_sent_with_create_is_visible_to_the_owner_and_the_admin(): void
    {
        Storage::fake('cloudinary');
        config(['filesystems.disks.cloudinary.cloud' => 'test-cloud']);

        $owner = $this->user('space_owner');
        $admin = $this->user('admin');

        $this->withToken($this->token($owner))
            ->post('/api/owner/spaces', $this->createPayload([
                'docs' => UploadedFile::fake()->create('lease.pdf', 120, 'application/pdf'),
            ]))
            ->assertStatus(201);

        $space = Workspace::firstWhere('title', 'مساحة الاختبار');
        $this->assertNotNull($space);
        $this->assertNotNull($space->space_document_url);
        Storage::disk('cloudinary')->assertExists($space->space_document_url);

        // The create form also produced a document row, not just the column.
        $this->assertDatabaseHas('space_owner_verification', [
            'workspace_id' => $space->id,
            'user_id' => $owner->id,
            'proof_document_url' => $space->space_document_url,
            'status' => 'pending',
        ]);

        $ownerDocuments = $this->withToken($this->token($owner))
            ->getJson('/api/owner/spaces/'.$space->id.'/documents')
            ->assertStatus(200)
            ->assertJsonCount(1, 'documents')
            ->json('documents.0');

        $this->assertSame($space->space_document_url, $ownerDocuments['path']);
        $this->assertSame(
            'https://res.cloudinary.com/test-cloud/raw/upload/'.$space->space_document_url,
            $ownerDocuments['url']
        );

        Auth::forgetGuards();

        $this->withToken($this->token($admin))
            ->getJson('/api/admin/spaces/'.$space->id)
            ->assertStatus(200)
            ->assertJsonCount(1, 'documents')
            ->assertJsonCount(1, 'data.documents')
            ->assertJsonPath('documents.0.path', $space->space_document_url)
            ->assertJsonPath('data.documents.0.path', $space->space_document_url)
            ->assertJsonPath(
                'data.documents.0.url',
                'https://res.cloudinary.com/test-cloud/raw/upload/'.$space->space_document_url
            );

        $this->withToken($this->token($admin))
            ->getJson('/api/admin/documents?space_id='.$space->id)
            ->assertStatus(200)
            ->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.path', $space->space_document_url);
    }

    public function test_uploading_again_keeps_the_previous_file_and_the_admin_sees_both(): void
    {
        Storage::fake('cloudinary');
        config(['filesystems.disks.cloudinary.cloud' => 'test-cloud']);

        $owner = $this->user('space_owner');
        $admin = $this->user('admin');
        $space = $this->space($owner);

        $first = $this->withToken($this->token($owner))
            ->post('/api/owner/spaces/'.$space->id.'/documents', [
                'proof' => UploadedFile::fake()->create('deed.pdf', 120, 'application/pdf'),
            ])
            ->assertStatus(201)
            ->json('documents.0');

        $second = $this->withToken($this->token($owner))
            ->post('/api/owner/spaces/'.$space->id.'/documents', [
                'proof' => UploadedFile::fake()->create('lease.png', 120, 'image/png'),
            ])
            ->assertStatus(201)
            ->json('documents.0');

        $this->assertNotSame($first['path'], $second['path']);
        Storage::disk('cloudinary')->assertExists($first['path']);
        Storage::disk('cloudinary')->assertExists($second['path']);

        $this->assertSame(2, SpaceOwnerVerification::where('workspace_id', $space->id)->count());
        $this->assertSame($second['path'], $space->fresh()->space_document_url);

        $this->assertSame(
            'https://res.cloudinary.com/test-cloud/raw/upload/'.$first['path'],
            $first['url']
        );
        $this->assertSame(
            'https://res.cloudinary.com/test-cloud/image/upload/'.$second['path'],
            $second['url']
        );

        $this->withToken($this->token($owner))
            ->getJson('/api/owner/spaces/'.$space->id.'/documents')
            ->assertStatus(200)
            ->assertJsonCount(2, 'documents');

        Auth::forgetGuards();

        $adminDocuments = $this->withToken($this->token($admin))
            ->getJson('/api/admin/documents?space_id='.$space->id)
            ->assertStatus(200)
            ->assertJsonCount(2, 'documents')
            ->json('documents');

        $this->assertEqualsCanonicalizing(
            [$first['path'], $second['path']],
            array_column($adminDocuments, 'path')
        );
    }

    public function test_an_owner_cannot_upload_to_someone_elses_space(): void
    {
        Storage::fake('cloudinary');

        $owner = $this->user('space_owner');
        $other = $this->user('space_owner');
        $space = $this->space($owner);

        $this->withToken($this->token($other))
            ->post('/api/owner/spaces/'.$space->id.'/documents', [
                'proof' => UploadedFile::fake()->create('deed.pdf', 120, 'application/pdf'),
            ])
            ->assertStatus(403);

        $this->assertSame(0, SpaceOwnerVerification::count());
    }

    public function test_the_admin_decision_marks_the_latest_submission_only(): void
    {
        Storage::fake('cloudinary');

        $owner = $this->user('space_owner');
        $admin = $this->user('admin');
        $space = $this->space($owner);

        $older = SpaceOwnerVerification::create([
            'user_id' => $owner->id,
            'workspace_id' => $space->id,
            'proof_document_url' => 'space_documents/'.$space->id.'/older.pdf',
            'status' => 'pending',
        ]);

        $latest = SpaceOwnerVerification::create([
            'user_id' => $owner->id,
            'workspace_id' => $space->id,
            'proof_document_url' => 'space_documents/'.$space->id.'/latest.pdf',
            'status' => 'pending',
        ]);

        $this->withToken($this->token($admin))
            ->patchJson('/api/admin/spaces/'.$space->id.'/status', ['status' => 'active'])
            ->assertStatus(200);

        $this->assertSame('pending', $older->fresh()->status);
        $this->assertSame('approved', $latest->fresh()->status);
        $this->assertNotNull($latest->fresh()->verified_at);
    }
}

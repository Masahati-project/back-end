<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\SpaceOwnerVerification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The admin approval screen reads two things: the listing's image (a full URL,
 * never the stored path) and the proof documents behind the listing.
 *
 * These tests pin both the space-scoped document contract
 * (`GET /api/admin/documents?space_id=…`) and the unchanged owner-queue shape
 * that the same endpoint still serves when no space is selected.
 */
class AdminSpaceDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'verified_at' => now(),
        ]);
    }

    private function owner(): User
    {
        return User::factory()->create([
            'role' => 'space_owner',
            'status' => 'active',
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
            'description' => 'قاعة للمعارض والفعاليات',
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

    private function verification(Workspace $space, array $overrides = []): SpaceOwnerVerification
    {
        return SpaceOwnerVerification::create(array_merge([
            'user_id' => $space->owner_id,
            'workspace_id' => $space->id,
            'proof_document_url' => 'space_documents/'.$space->id.'/proof.pdf',
            'status' => 'pending',
        ], $overrides));
    }

    /**
     * A local disk is enough to prove the response carries a full URL; the
     * production disk uses the same `url` config for the Cloudinary delivery
     * domain.
     */
    private function configureCloudinaryUrl(): void
    {
        Storage::forgetDisk('cloudinary');

        config([
            'filesystems.disks.cloudinary' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/cloudinary'),
                'url' => 'https://cdn.example.com',
                'throw' => false,
            ],
        ]);
    }

    public function test_space_scoped_documents_are_flat_and_carry_full_urls(): void
    {
        $this->configureCloudinaryUrl();

        $admin = $this->admin();
        $owner = $this->owner();
        $space = $this->space($owner);

        $this->verification($space, [
            'proof_document_url' => 'space_documents/'.$space->id.'/deed.pdf',
            'status' => 'approved',
        ]);

        $this->verification($space, [
            'proof_document_url' => 'space_documents/'.$space->id.'/lease.pdf',
            'status' => 'pending',
        ]);

        $response = $this->withToken($this->token($admin))
            ->getJson('/api/admin/documents?space_id='.$space->id);

        $response->assertStatus(200)
            ->assertJsonPath('space_id', $space->id)
            ->assertJsonPath('space_title', $space->title)
            ->assertJsonPath('owner_id', $owner->id)
            ->assertJsonPath('owner_name', $owner->full_name)
            ->assertJsonCount(2, 'data')
            ->assertJsonCount(2, 'documents')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'kind',
                        'label',
                        'name',
                        'url',
                        'path',
                        'space_id',
                        'space_title',
                        'owner_id',
                        'owner_name',
                        'status',
                        'uploaded_at',
                    ],
                ],
            ]);

        $documents = collect($response->json('data'))->keyBy('path');

        $this->assertSame(
            'https://cdn.example.com/space_documents/'.$space->id.'/deed.pdf',
            $documents['space_documents/'.$space->id.'/deed.pdf']['url']
        );
        $this->assertSame('deed.pdf', $documents['space_documents/'.$space->id.'/deed.pdf']['name']);
        $this->assertSame('proof', $documents['space_documents/'.$space->id.'/deed.pdf']['kind']);
        $this->assertSame('approved', $documents['space_documents/'.$space->id.'/deed.pdf']['status']);
        $this->assertSame($space->id, $documents['space_documents/'.$space->id.'/deed.pdf']['space_id']);
        $this->assertSame($owner->full_name, $documents['space_documents/'.$space->id.'/deed.pdf']['owner_name']);
    }

    public function test_space_scoped_documents_include_the_legacy_column_once(): void
    {
        $this->configureCloudinaryUrl();

        $admin = $this->admin();
        $owner = $this->owner();
        $space = $this->space($owner, [
            'space_document_url' => 'documents/legacy_deed.pdf',
        ]);

        $this->verification($space, [
            'proof_document_url' => 'documents/legacy_deed.pdf',
            'status' => 'pending',
        ]);

        $this->verification($space, [
            'proof_document_url' => 'space_documents/'.$space->id.'/newer.pdf',
            'status' => 'approved',
        ]);

        $response = $this->withToken($this->token($admin))
            ->getJson('/api/admin/documents?space_id='.$space->id);

        $response->assertStatus(200)->assertJsonCount(2, 'data');

        $paths = collect($response->json('data'))->pluck('path');
        $this->assertSame(1, $paths->filter(fn ($path) => $path === 'documents/legacy_deed.pdf')->count());
    }

    public function test_space_scoped_documents_can_be_filtered_by_status(): void
    {
        $this->configureCloudinaryUrl();

        $admin = $this->admin();
        $space = $this->space($this->owner());

        $this->verification($space, [
            'proof_document_url' => 'space_documents/'.$space->id.'/approved.pdf',
            'status' => 'approved',
        ]);

        $this->verification($space, [
            'proof_document_url' => 'space_documents/'.$space->id.'/pending.pdf',
            'status' => 'pending',
        ]);

        $response = $this->withToken($this->token($admin))
            ->getJson('/api/admin/documents?space_id='.$space->id.'&status=pending');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.path', 'space_documents/'.$space->id.'/pending.pdf');
    }

    public function test_space_scoped_documents_404_for_an_unknown_space(): void
    {
        $admin = $this->admin();

        $this->withToken($this->token($admin))
            ->getJson('/api/admin/documents?space_id=999999')
            ->assertStatus(404)
            ->assertJsonPath('documents', []);
    }

    public function test_the_unscoped_endpoint_keeps_the_owner_verification_queue_shape(): void
    {
        $this->configureCloudinaryUrl();

        $admin = $this->admin();
        $owner = $this->owner();

        $document = Document::create([
            'user_id' => $owner->id,
            'status' => 'pending',
            'submitted_at' => now(),
        ]);

        DocumentFile::create([
            'document_id' => $document->id,
            'slot_id' => 'identity',
            'name' => 'identity.pdf',
            'path' => 'documents/identity.pdf',
            'size' => 1234,
            'mime_type' => 'application/pdf',
        ]);

        $response = $this->withToken($this->token($admin))
            ->getJson('/api/admin/documents');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.files.0.name', 'identity.pdf')
            ->assertJsonPath('data.0.owner_name', $owner->full_name);
    }

    public function test_admin_space_responses_return_full_image_urls(): void
    {
        config(['filesystems.disks.cloudinary.cloud' => 'test-cloud']);

        $admin = $this->admin();
        $space = $this->space($this->owner());

        WorkspaceImage::create([
            'workspace_id' => $space->id,
            'image_url' => 'spaces/photo.jpg',
        ]);

        $list = $this->withToken($this->token($admin))
            ->getJson('/api/admin/spaces');

        $list->assertStatus(200);

        $row = collect($list->json('data'))->firstWhere('id', $space->id);

        $this->assertSame(
            'https://res.cloudinary.com/test-cloud/image/upload/spaces/photo.jpg',
            $row['image']
        );

        $this->withToken($this->token($admin))
            ->getJson('/api/admin/spaces/'.$space->id)
            ->assertStatus(200)
            ->assertJsonPath(
                'data.image',
                'https://res.cloudinary.com/test-cloud/image/upload/spaces/photo.jpg'
            );
    }
}

<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminUsersTest extends TestCase
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

    private function createOwner(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['role' => 'space_owner', 'status' => 'active'], $overrides));
    }

    private function token(User $user): string
    {
        return $user->createToken('auth_token')->plainTextToken;
    }

    // -------------------------------------------------------------------------
    // A4.3: documents on user detail
    // -------------------------------------------------------------------------

    public function test_admin_user_detail_returns_documents_for_space_owner_with_document_files(): void
    {
        $admin = $this->createAdmin();
        $adminToken = $this->token($admin);

        $owner = $this->createOwner();

        // Create a Document with files
        $document = Document::create([
            'user_id' => $owner->id,
            'status' => 'pending',
            'submitted_at' => now(),
        ]);

        DocumentFile::create([
            'document_id' => $document->id,
            'slot_id' => 'assets',
            'name' => 'assets.pdf',
            'path' => 'documents/abc_assets.pdf',
            'size' => 1234,
            'mime_type' => 'application/pdf',
        ]);

        DocumentFile::create([
            'document_id' => $document->id,
            'slot_id' => 'cert',
            'name' => 'cert.jpg',
            'path' => 'documents/def_cert.jpg',
            'size' => 5678,
            'mime_type' => 'image/jpeg',
        ]);

        $response = $this->withToken($adminToken)
            ->getJson("/api/admin/users/{$owner->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'documents' => [
                        '*' => ['url', 'label', 'name', 'kind']
                    ]
                ]
            ]);

        $docs = $response->json('data.documents');
        $this->assertCount(2, $docs);
        $this->assertSame('documents/abc_assets.pdf', $docs[0]['url']);
        $this->assertSame('assets', $docs[0]['label']);
        $this->assertSame('assets.pdf', $docs[0]['name']);
        $this->assertSame('application/pdf', $docs[0]['kind']);
        $this->assertSame('cert', $docs[1]['label']);
    }

    public function test_admin_user_detail_returns_legacy_proof_document(): void
    {
        $admin = $this->createAdmin();
        $adminToken = $this->token($admin);

        $owner = $this->createOwner([
            'proof_document_url' => 'documents/legacy_proof.pdf',
        ]);

        $response = $this->withToken($adminToken)
            ->getJson("/api/admin/users/{$owner->id}");

        $response->assertStatus(200);

        $docs = $response->json('data.documents');
        $this->assertCount(1, $docs);
        $this->assertSame('documents/legacy_proof.pdf', $docs[0]['url']);
        $this->assertSame('legacy', $docs[0]['label']);
        $this->assertSame('proof_document', $docs[0]['name']);
        $this->assertSame('application/octet-stream', $docs[0]['kind']);
    }

    public function test_admin_user_detail_returns_empty_documents_for_customer(): void
    {
        $admin = $this->createAdmin();
        $adminToken = $this->token($admin);

        $customer = $this->createCustomer();

        $response = $this->withToken($adminToken)
            ->getJson("/api/admin/users/{$customer->id}");

        $response->assertStatus(200);

        $docs = $response->json('data.documents');
        $this->assertIsArray($docs);
        $this->assertEmpty($docs);
    }

    public function test_admin_user_detail_spaces_name_is_title_not_null(): void
    {
        $admin = $this->createAdmin();
        $adminToken = $this->token($admin);

        $owner = $this->createOwner();
        $workspace = Workspace::create([
            'owner_id' => $owner->id,
            'title' => 'My Test Space',
            'location' => 'Ramallah',
            'status' => 'approved',
            'is_active' => true,
            'open_time' => '08:00:00',
            'close_time' => '22:00:00',
        ]);

        $response = $this->withToken($adminToken)
            ->getJson("/api/admin/users/{$owner->id}");

        $response->assertStatus(200);

        $spaces = $response->json('data.spaces');
        $this->assertCount(1, $spaces);
        $this->assertSame('My Test Space', $spaces[0]['name']);
        $this->assertNotNull($spaces[0]['name']);
    }

    public function test_admin_user_detail_never_leaks_admin_note(): void
    {
        $admin = $this->createAdmin();
        $adminToken = $this->token($admin);

        $owner = $this->createOwner();
        $document = Document::create([
            'user_id' => $owner->id,
            'status' => 'rejected',
            'note' => 'Internal admin note',
        ]);

        DocumentFile::create([
            'document_id' => $document->id,
            'slot_id' => 'assets',
            'name' => 'assets.pdf',
            'path' => 'documents/abc_assets.pdf',
            'size' => 1234,
            'mime_type' => 'application/pdf',
        ]);

        $response = $this->withToken($adminToken)
            ->getJson("/api/admin/users/{$owner->id}");

        $response->assertStatus(200);

        $docs = $response->json('data.documents');
        foreach ($docs as $doc) {
            $this->assertArrayNotHasKey('admin_note', $doc);
            $this->assertArrayNotHasKey('note', $doc);
        }
    }

    // -------------------------------------------------------------------------
    // A4.5: two-way verify
    // -------------------------------------------------------------------------

    public function test_admin_verify_with_empty_body_on_unverified_user(): void
    {
        $admin = $this->createAdmin();
        $adminToken = $this->token($admin);

        $owner = $this->createOwner(['verified_at' => null, 'status' => 'pending']);

        $response = $this->withToken($adminToken)
            ->patchJson("/api/admin/users/{$owner->id}/verify", []);

        $response->assertStatus(200)
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.status', 'active');

        $this->assertNotNull($owner->fresh()->verified_at);
        $this->assertSame('active', $owner->fresh()->status);
    }

    public function test_admin_verify_twice_returns_409(): void
    {
        $admin = $this->createAdmin();
        $adminToken = $this->token($admin);

        $owner = $this->createOwner(['verified_at' => null, 'status' => 'pending']);

        $this->withToken($adminToken)
            ->patchJson("/api/admin/users/{$owner->id}/verify", [])
            ->assertStatus(200);

        $response = $this->withToken($adminToken)
            ->patchJson("/api/admin/users/{$owner->id}/verify", []);

        $response->assertStatus(409);
    }

    public function test_admin_unverify_verified_user(): void
    {
        $admin = $this->createAdmin();
        $adminToken = $this->token($admin);

        $owner = $this->createOwner(['verified_at' => now(), 'status' => 'active']);

        $response = $this->withToken($adminToken)
            ->patchJson("/api/admin/users/{$owner->id}/verify", ['verified' => false]);

        $response->assertStatus(200)
            ->assertJsonPath('data.verified', false)
            ->assertJsonPath('data.status', 'pending');

        $this->assertNull($owner->fresh()->verified_at);
        $this->assertSame('pending', $owner->fresh()->status);
    }

    public function test_admin_unverify_unverified_user_returns_409(): void
    {
        $admin = $this->createAdmin();
        $adminToken = $this->token($admin);

        $owner = $this->createOwner(['verified_at' => null, 'status' => 'pending']);

        $response = $this->withToken($adminToken)
            ->patchJson("/api/admin/users/{$owner->id}/verify", ['verified' => false]);

        $response->assertStatus(409);
    }

    public function test_admin_verify_invalid_boolean_returns_422(): void
    {
        $admin = $this->createAdmin();
        $adminToken = $this->token($admin);

        $owner = $this->createOwner(['verified_at' => null, 'status' => 'pending']);

        $response = $this->withToken($adminToken)
            ->patchJson("/api/admin/users/{$owner->id}/verify", ['verified' => 'banana']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['verified']);
    }

    // -------------------------------------------------------------------------
    // A4.9: export
    // -------------------------------------------------------------------------

    public function test_admin_users_export_unfiltered(): void
    {
        $admin = $this->createAdmin();
        $adminToken = $this->token($admin);

        $this->createCustomer(['full_name' => 'User One', 'email' => 'one@test.com']);
        $this->createOwner(['full_name' => 'Owner One', 'email' => 'owner@test.com']);

        $response = $this->withToken($adminToken)
            ->get('/api/admin/users/export');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));

        $body = $response->getContent();
        // Starts with UTF-8 BOM
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);

        // Header row exactly
        $lines = explode("\r\n", $body);
        $this->assertSame('name,email,phone,role,status,verified,bookings,joined', $lines[0]);

        // Two data rows + header = at least 3 lines
        $this->assertGreaterThanOrEqual(3, count($lines));
    }

    public function test_admin_users_export_filtered_by_role(): void
    {
        $admin = $this->createAdmin();
        $adminToken = $this->token($admin);

        $this->createCustomer(['full_name' => 'User One', 'email' => 'one@test.com']);
        $this->createOwner(['full_name' => 'Owner One', 'email' => 'owner@test.com']);

        $response = $this->withToken($adminToken)
            ->get('/api/admin/users/export?role=space_owner');

        $response->assertStatus(200);

        $body = $response->getContent();
        $lines = explode("\r\n", $body);

        // Header + 1 owner row
        $this->assertCount(3, $lines); // header, 1 data, empty trailing
        $this->assertStringContainsString('Owner One', $lines[1]);
        $this->assertStringContainsString('space_owner', $lines[1]);
    }

    public function test_admin_users_export_filtered_by_verified_pending(): void
    {
        $admin = $this->createAdmin();
        $adminToken = $this->token($admin);

        $this->createCustomer(['full_name' => 'Verified', 'email' => 'v@test.com', 'verified_at' => now()]);
        $this->createOwner(['full_name' => 'Unverified', 'email' => 'u@test.com', 'verified_at' => null]);

        $response = $this->withToken($adminToken)
            ->get('/api/admin/users/export?verified=pending');

        $response->assertStatus(200);

        $body = $response->getContent();
        $lines = explode("\r\n", $body);

        // Header + 1 unverified row
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('Unverified', $lines[1]);
        $this->assertStringContainsString(',0,', $lines[1]); // verified=0
    }

    public function test_admin_users_export_filtered_by_ids(): void
    {
        $admin = $this->createAdmin();
        $adminToken = $this->token($admin);

        $u1 = $this->createCustomer(['full_name' => 'User One', 'email' => 'one@test.com']);
        $u2 = $this->createCustomer(['full_name' => 'User Two', 'email' => 'two@test.com']);
        $u3 = $this->createCustomer(['full_name' => 'User Three', 'email' => 'three@test.com']);

        $response = $this->withToken($adminToken)
            ->get("/api/admin/users/export?ids={$u1->id},{$u3->id}");

        $response->assertStatus(200);

        $body = $response->getContent();
        $lines = explode("\r\n", $body);

        // Header + 2 data rows
        $this->assertCount(4, $lines);
        $this->assertStringContainsString('User One', $lines[1]);
        $this->assertStringContainsString('User Three', $lines[2]);
        $this->assertStringNotContainsString('User Two', $body);
    }

    public function test_admin_users_export_escapes_comma_and_quote(): void
    {
        $admin = $this->createAdmin();
        $adminToken = $this->token($admin);

        $user = $this->createCustomer([
            'full_name' => 'Ali, "Bob"',
            'email' => 'ali@test.com',
        ]);

        $response = $this->withToken($adminToken)
            ->get("/api/admin/users/export?ids={$user->id}");

        $response->assertStatus(200);

        $body = $response->getContent();
        $lines = explode("\r\n", $body);

        // The name field should be quoted and internal quotes doubled
        $this->assertStringContainsString('"Ali, ""Bob"""', $lines[1]);
    }

    public function test_admin_users_export_formula_injection_guard(): void
    {
        $admin = $this->createAdmin();
        $adminToken = $this->token($admin);

        $user = $this->createCustomer([
            'full_name' => '=SUM(1,2)',
            'email' => 'malicious@test.com',
        ]);

        $response = $this->withToken($adminToken)
            ->get("/api/admin/users/export?ids={$user->id}");

        $response->assertStatus(200);

        $body = $response->getContent();
        $lines = explode("\r\n", $body);

        // Should be prefixed with single quote
        $this->assertStringContainsString("'=SUM(1,2)", $lines[1]);
    }
}
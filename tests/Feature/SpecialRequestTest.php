<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\Notification;
use App\Models\Offer;
use App\Models\SpecialRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpecialRequestTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return $user->createToken('auth_token')->plainTextToken;
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'space_owner']);
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => 'customer']);
    }

    private function workspaceFor(User $owner): Workspace
    {
        return Workspace::create([
            'owner_id' => $owner->id,
            'title' => 'مساحة الاختبار',
            'space_document_url' => 'documents/space.pdf',
            'location' => 'الرياض',
            'latitude' => 24.7136,
            'longitude' => 46.6753,
            'contact_phone' => '0500000000',
            'status' => 'approved',
            'open_time' => '08:00',
            'close_time' => '22:00',
            'is_closed' => false,
        ]);
    }

    private function requestFrom(User $customer, array $overrides = []): SpecialRequest
    {
        return SpecialRequest::create(array_merge([
            'user_id' => $customer->id,
            'title' => 'طلب تجريبي',
            'description' => 'وصف',
            'space_type' => 'room',
            'capacity' => 5,
            'status' => 'open',
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // GET /api/special-requests/open
    // -------------------------------------------------------------------------

    public function test_open_requests_returns_200_json_list_for_authenticated_owner(): void
    {
        $owner = $this->owner();
        $customer = $this->customer();
        $specialRequest = $this->requestFrom($customer);

        // This is the regression that broke the owner's Market tab: the literal
        // segment "open" used to be captured by /{requestId} and 404'd.
        $response = $this->withToken($this->token($owner))
            ->getJson('/api/special-requests/open');

        $response->assertStatus(200)
            ->assertJsonStructure(['requests', 'message'])
            ->assertJsonPath('requests.0.id', $specialRequest->id);
    }

    public function test_open_requests_omits_the_callers_own_requests(): void
    {
        $owner = $this->owner();
        $this->requestFrom($owner);
        $otherRequest = $this->requestFrom($this->customer());

        $response = $this->withToken($this->token($owner))
            ->getJson('/api/special-requests/open');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'requests')
            ->assertJsonPath('requests.0.id', $otherRequest->id);
    }

    public function test_open_requests_excludes_non_open_statuses(): void
    {
        $owner = $this->owner();
        $this->requestFrom($this->customer(), ['status' => 'closed']);

        $this->withToken($this->token($owner))
            ->getJson('/api/special-requests/open')
            ->assertStatus(200)
            ->assertJsonCount(0, 'requests');
    }

    public function test_open_requests_requires_authentication(): void
    {
        $this->getJson('/api/special-requests/open')->assertStatus(401);
    }

    public function test_literal_open_does_not_collide_with_show_route(): void
    {
        // A non-numeric id must never reach the show() lookup.
        $this->withToken($this->token($this->owner()))
            ->getJson('/api/special-requests/not-a-number')
            ->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // POST /api/special-requests/{requestId}/offers
    // -------------------------------------------------------------------------

    public function test_owner_can_submit_an_offer(): void
    {
        $owner = $this->owner();
        $specialRequest = $this->requestFrom($this->customer());
        $workspace = $this->workspaceFor($owner);

        $response = $this->withToken($this->token($owner))
            ->postJson("/api/special-requests/{$specialRequest->id}/offers", [
                'space_id' => $workspace->id,
                'price_per_hour' => 50,
                'duration_hours' => 3,
                'notes' => 'ملاحظات اختيارية',
                'currency' => 'ش.ج',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'offer']);

        $this->assertDatabaseHas('offers', [
            'special_request_id' => $specialRequest->id,
            'workspace_id' => $workspace->id,
            'price_per_hour' => 50,
            'duration_hours' => 3,
            'status' => 'pending',
        ]);
    }

    public function test_offer_notifies_the_request_owner(): void
    {
        $owner = $this->owner();
        $customer = $this->customer();
        $specialRequest = $this->requestFrom($customer);
        $workspace = $this->workspaceFor($owner);

        $this->withToken($this->token($owner))
            ->postJson("/api/special-requests/{$specialRequest->id}/offers", [
                'space_id' => $workspace->id,
                'price_per_hour' => 50,
                'duration_hours' => 3,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $customer->id,
            'type' => 'offer',
        ]);
    }

    public function test_resubmitting_from_the_same_space_revises_the_offer(): void
    {
        $owner = $this->owner();
        $specialRequest = $this->requestFrom($this->customer());
        $workspace = $this->workspaceFor($owner);

        $payload = [
            'space_id' => $workspace->id,
            'price_per_hour' => 50,
            'duration_hours' => 3,
        ];

        $this->withToken($this->token($owner))
            ->postJson("/api/special-requests/{$specialRequest->id}/offers", $payload)
            ->assertStatus(201);

        $this->withToken($this->token($owner))
            ->postJson("/api/special-requests/{$specialRequest->id}/offers", [
                'space_id' => $workspace->id,
                'price_per_hour' => 75,
                'duration_hours' => 4,
            ])
            ->assertStatus(201);

        $this->assertDatabaseCount('offers', 1);
        $this->assertDatabaseHas('offers', [
            'special_request_id' => $specialRequest->id,
            'price_per_hour' => 75,
            'duration_hours' => 4,
        ]);
    }

    public function test_cannot_offer_on_your_own_request(): void
    {
        $owner = $this->owner();
        $specialRequest = $this->requestFrom($owner);
        $workspace = $this->workspaceFor($owner);

        $this->withToken($this->token($owner))
            ->postJson("/api/special-requests/{$specialRequest->id}/offers", [
                'space_id' => $workspace->id,
                'price_per_hour' => 50,
                'duration_hours' => 3,
            ])
            ->assertStatus(403);

        $this->assertDatabaseCount('offers', 0);
    }

    public function test_cannot_offer_on_someone_elses_space(): void
    {
        $owner = $this->owner();
        $specialRequest = $this->requestFrom($this->customer());
        $foreignWorkspace = $this->workspaceFor($this->owner());

        $this->withToken($this->token($owner))
            ->postJson("/api/special-requests/{$specialRequest->id}/offers", [
                'space_id' => $foreignWorkspace->id,
                'price_per_hour' => 50,
                'duration_hours' => 3,
            ])
            ->assertStatus(403);

        $this->assertDatabaseCount('offers', 0);
    }

    public function test_cannot_offer_on_a_closed_request(): void
    {
        $owner = $this->owner();
        $specialRequest = $this->requestFrom($this->customer(), ['status' => 'closed']);
        $workspace = $this->workspaceFor($owner);

        $this->withToken($this->token($owner))
            ->postJson("/api/special-requests/{$specialRequest->id}/offers", [
                'space_id' => $workspace->id,
                'price_per_hour' => 50,
                'duration_hours' => 3,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('offers', 0);
    }

    public function test_customers_cannot_submit_offers(): void
    {
        $customer = $this->customer();
        $specialRequest = $this->requestFrom($this->customer());
        $workspace = $this->workspaceFor($this->owner());

        $this->withToken($this->token($customer))
            ->postJson("/api/special-requests/{$specialRequest->id}/offers", [
                'space_id' => $workspace->id,
                'price_per_hour' => 50,
                'duration_hours' => 3,
            ])
            ->assertStatus(403);
    }

    public function test_offer_validates_its_payload(): void
    {
        $owner = $this->owner();
        $specialRequest = $this->requestFrom($this->customer());

        $this->withToken($this->token($owner))
            ->postJson("/api/special-requests/{$specialRequest->id}/offers", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['space_id', 'price_per_hour', 'duration_hours']);
    }

    public function test_offer_requires_authentication(): void
    {
        $this->postJson('/api/special-requests/1/offers', [])->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // Accept / reject
    // -------------------------------------------------------------------------

    public function test_accept_offer_updates_the_offer_and_the_request(): void
    {
        $customer = $this->customer();
        $owner = $this->owner();
        $specialRequest = $this->requestFrom($customer);
        $workspace = $this->workspaceFor($owner);

        $offer = Offer::create([
            'special_request_id' => $specialRequest->id,
            'workspace_id' => $workspace->id,
            'price_per_hour' => 50,
            'duration_hours' => 3,
            'status' => 'pending',
        ]);

        $this->withToken($this->token($customer))
            ->postJson("/api/special-requests/{$specialRequest->id}/offers/{$offer->id}/accept")
            ->assertStatus(200);

        $this->assertDatabaseHas('offers', ['id' => $offer->id, 'status' => 'accepted']);
        $this->assertDatabaseHas('special_requests', ['id' => $specialRequest->id, 'status' => 'accepted']);
    }

    public function test_accept_offer_rejects_the_remaining_offers(): void
    {
        $customer = $this->customer();
        $specialRequest = $this->requestFrom($customer);

        $winning = Offer::create([
            'special_request_id' => $specialRequest->id,
            'workspace_id' => $this->workspaceFor($this->owner())->id,
            'price_per_hour' => 50,
            'duration_hours' => 3,
            'status' => 'pending',
        ]);

        $losing = Offer::create([
            'special_request_id' => $specialRequest->id,
            'workspace_id' => $this->workspaceFor($this->owner())->id,
            'price_per_hour' => 20,
            'duration_hours' => 1,
            'status' => 'pending',
        ]);

        $this->withToken($this->token($customer))
            ->postJson("/api/special-requests/{$specialRequest->id}/offers/{$winning->id}/accept")
            ->assertStatus(200);

        $this->assertDatabaseHas('offers', ['id' => $losing->id, 'status' => 'rejected']);
    }

    public function test_accept_offer_notifies_the_space_owner_not_the_customer(): void
    {
        $customer = $this->customer();
        $owner = $this->owner();
        $specialRequest = $this->requestFrom($customer);
        $workspace = $this->workspaceFor($owner);

        $offer = Offer::create([
            'special_request_id' => $specialRequest->id,
            'workspace_id' => $workspace->id,
            'price_per_hour' => 50,
            'duration_hours' => 3,
            'status' => 'pending',
        ]);

        $this->withToken($this->token($customer))
            ->postJson("/api/special-requests/{$specialRequest->id}/offers/{$offer->id}/accept")
            ->assertStatus(200);

        $this->assertTrue(
            Notification::where('user_id', $owner->id)->where('type', 'offer')->exists()
        );
        $this->assertFalse(
            Notification::where('user_id', $customer->id)->where('type', 'offer')->exists()
        );
    }

    public function test_reject_offer_leaves_the_request_open(): void
    {
        // Previously this wrote status="rejected" into an enum that only allowed
        // open/accepted/closed, which is a hard SQL error on MySQL.
        $customer = $this->customer();
        $owner = $this->owner();
        $specialRequest = $this->requestFrom($customer);
        $workspace = $this->workspaceFor($owner);

        $offer = Offer::create([
            'special_request_id' => $specialRequest->id,
            'workspace_id' => $workspace->id,
            'price_per_hour' => 50,
            'duration_hours' => 3,
            'status' => 'pending',
        ]);

        $this->withToken($this->token($customer))
            ->postJson("/api/special-requests/{$specialRequest->id}/offers/{$offer->id}/reject")
            ->assertStatus(200);

        $this->assertDatabaseHas('offers', ['id' => $offer->id, 'status' => 'rejected']);
        $this->assertDatabaseHas('special_requests', ['id' => $specialRequest->id, 'status' => 'open']);
    }

    public function test_cannot_accept_an_offer_from_a_different_request(): void
    {
        $customer = $this->customer();
        $specialRequestA = $this->requestFrom($customer);
        $specialRequestB = $this->requestFrom($customer);

        $offer = Offer::create([
            'special_request_id' => $specialRequestB->id,
            'workspace_id' => $this->workspaceFor($this->owner())->id,
            'price_per_hour' => 50,
            'duration_hours' => 3,
            'status' => 'pending',
        ]);

        $this->withToken($this->token($customer))
            ->postJson("/api/special-requests/{$specialRequestA->id}/offers/{$offer->id}/accept")
            ->assertStatus(404);

        $this->assertDatabaseHas('offers', ['id' => $offer->id, 'status' => 'pending']);
    }

    public function test_cannot_manage_offers_on_someone_elses_request(): void
    {
        $owner = $this->owner();
        $specialRequest = $this->requestFrom($this->customer());
        $offer = Offer::create([
            'special_request_id' => $specialRequest->id,
            'workspace_id' => $this->workspaceFor($owner)->id,
            'price_per_hour' => 50,
            'duration_hours' => 3,
            'status' => 'pending',
        ]);

        $this->withToken($this->token($owner))
            ->postJson("/api/special-requests/{$specialRequest->id}/offers/{$offer->id}/accept")
            ->assertStatus(404);

        $this->assertDatabaseHas('special_requests', ['id' => $specialRequest->id, 'status' => 'open']);
    }

    public function test_close_request_still_works(): void
    {
        $customer = $this->customer();
        $specialRequest = $this->requestFrom($customer);

        $this->withToken($this->token($customer))
            ->postJson("/api/special-requests/{$specialRequest->id}/close")
            ->assertStatus(200);

        $this->assertDatabaseHas('special_requests', ['id' => $specialRequest->id, 'status' => 'closed']);
    }

    // -------------------------------------------------------------------------
    // Notifications
    // -------------------------------------------------------------------------

    public function test_mark_all_as_read_accepts_patch(): void
    {
        $user = $this->customer();
        Notification::create([
            'user_id' => $user->id,
            'type' => 'offer',
            'message' => 'رسالة',
            'is_read' => false,
        ]);

        $this->withToken($this->token($user))
            ->patchJson('/api/notifications/read')
            ->assertStatus(200);

        $this->assertDatabaseMissing('notifications', ['user_id' => $user->id, 'is_read' => false]);
    }

    public function test_mark_all_as_read_still_accepts_post(): void
    {
        $user = $this->customer();

        $this->withToken($this->token($user))
            ->postJson('/api/notifications/read')
            ->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // Status codes and document shape
    // -------------------------------------------------------------------------

    public function test_get_profile_returns_200(): void
    {
        $this->withToken($this->token($this->customer()))
            ->getJson('/api/profile')
            ->assertStatus(200);
    }

    public function test_get_dashboard_collections_return_200(): void
    {
        $token = $this->token($this->customer());

        $this->withToken($token)->getJson('/api/dashboard/bookings')->assertStatus(200);
        $this->withToken($token)->getJson('/api/dashboard/favorites')->assertStatus(200);
    }

    public function test_login_returns_the_user_with_a_role(): void
    {
        $user = $this->owner();

        $response = $this->postJson('/api/login', [
            'login' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('user.role', 'space_owner')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonStructure(['message', 'token', 'name', 'user']);
    }

    public function test_owner_documents_files_is_an_object_when_empty(): void
    {
        $owner = $this->owner();

        $response = $this->withToken($this->token($owner))->getJson('/api/owner/documents');

        $response->assertStatus(200);

        // Asserted on the raw body: decoding turns both {} and [] into an empty
        // PHP array, so json() cannot tell the two apart.
        $this->assertStringContainsString('"files":{}', $response->getContent());
    }

    public function test_owner_documents_files_is_keyed_by_slot(): void
    {
        $owner = $this->owner();

        $document = Document::create(['user_id' => $owner->id, 'status' => 'pending']);

        foreach (['assets', 'cert'] as $slot) {
            DocumentFile::create([
                'document_id' => $document->id,
                'slot_id' => $slot,
                'name' => "{$slot}.pdf",
                'path' => "documents/{$slot}.pdf",
                'size' => 1234,
                'mime_type' => 'application/pdf',
            ]);
        }

        $response = $this->withToken($this->token($owner))->getJson('/api/owner/documents');

        $response->assertStatus(200)
            ->assertJsonPath('files.assets.name', 'assets.pdf')
            ->assertJsonPath('files.assets.size', 1234)
            ->assertJsonPath('files.assets.type', 'application/pdf')
            ->assertJsonPath('files.cert.name', 'cert.pdf');
    }

    public function test_owner_documents_does_not_mirror_note_into_admin_note(): void
    {
        $owner = $this->owner();

        Document::create([
            'user_id' => $owner->id,
            'status' => 'rejected',
            'note' => 'الصورة غير واضحة',
        ]);

        $payload = $this->withToken($this->token($owner))->getJson('/api/owner/documents')->json();

        $this->assertSame('الصورة غير واضحة', $payload['review_note']);
        $this->assertNull($payload['admin_note']);
    }
}

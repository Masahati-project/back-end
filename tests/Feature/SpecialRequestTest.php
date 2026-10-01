<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Dispute;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\Notification;
use App\Models\Offer;
use App\Models\SpecialRequest;
use App\Models\Unit;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
        $workspace = Workspace::create([
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

        // Accepting an offer books the workspace's first unit, so every test
        // workspace needs one.
        Unit::create([
            'workspace_id' => $workspace->id,
            'type' => 'desk',
            'capacity' => '4',
            'has_wifi' => true,
            'has_power' => true,
            'status' => 'available',
        ]);

        return $workspace;
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
            'type' => 'special_request_offer',
        ]);
    }

    public function test_same_owner_cannot_offer_twice_on_the_same_request(): void
    {
        $owner = $this->owner();
        $specialRequest = $this->requestFrom($this->customer());
        $workspace = $this->workspaceFor($owner);

        $this->withToken($this->token($owner))
            ->postJson("/api/special-requests/{$specialRequest->id}/offers", [
                'space_id' => $workspace->id,
                'price_per_hour' => 50,
                'duration_hours' => 3,
            ])
            ->assertStatus(201);

        // The frontend hides the button after the first attempt; the backend
        // rule is the source of truth.
        $this->withToken($this->token($owner))
            ->postJson("/api/special-requests/{$specialRequest->id}/offers", [
                'space_id' => $workspace->id,
                'price_per_hour' => 75,
                'duration_hours' => 4,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'سبق أن قدّمت عرضاً لهذا الطلب.');

        $this->assertDatabaseCount('offers', 1);
        $this->assertDatabaseHas('offers', [
            'special_request_id' => $specialRequest->id,
            'price_per_hour' => 50,
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
            Notification::where('user_id', $owner->id)
                ->where('type', 'special_request_offer_accepted')->exists()
        );
        $this->assertFalse(
            Notification::where('user_id', $customer->id)
                ->where('type', 'special_request_offer_accepted')->exists()
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

    // -------------------------------------------------------------------------
    // SPECIAL REQUESTS — contract from SPECIAL_REQUESTS_API_REQUIREMENTS.md
    // -------------------------------------------------------------------------

    public function test_accept_offer_creates_a_booking_and_returns_it(): void
    {
        // The frontend reads a top-level `booking` key; without it the booking
        // never shows up in the customer's list.
        $customer = $this->customer();
        $owner = $this->owner();
        $specialRequest = $this->requestFrom($customer);
        $workspace = $this->workspaceFor($owner);

        $offer = Offer::create([
            'special_request_id' => $specialRequest->id,
            'workspace_id' => $workspace->id,
            'price_per_hour' => 150,
            'duration_hours' => 3,
            'status' => 'pending',
        ]);

        $response = $this->withToken($this->token($customer))
            ->postJson("/api/special-requests/{$specialRequest->id}/offers/{$offer->id}/accept");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'booking' => ['booking_id', 'space_name', 'date', 'time', 'hours', 'price', 'status'],
                'request' => ['request_id', 'status'],
            ]);

        $this->assertDatabaseHas('bookings', [
            'user_id' => $customer->id,
            'unit_id' => $workspace->units()->first()->id,
            'status' => 'pending',
            'total_price' => 450,
        ]);
    }

    public function test_the_booking_from_an_accepted_offer_gets_a_reference(): void
    {
        // GET /api/admin/bookings/{ref} looks the column up directly, so a
        // booking saved without a ref can never be opened from the admin panel.
        $customer = $this->customer();
        $owner = $this->owner();
        $specialRequest = $this->requestFrom($customer);
        $workspace = $this->workspaceFor($owner);

        $offer = Offer::create([
            'special_request_id' => $specialRequest->id,
            'workspace_id' => $workspace->id,
            'price_per_hour' => 150,
            'duration_hours' => 3,
            'status' => 'pending',
        ]);

        $this->withToken($this->token($customer))
            ->postJson("/api/special-requests/{$specialRequest->id}/offers/{$offer->id}/accept")
            ->assertStatus(200);

        $booking = Booking::where('user_id', $customer->id)->firstOrFail();

        $this->assertSame('BK-' . $booking->id, $booking->ref);
    }

    public function test_an_admin_can_open_a_booking_by_its_reference(): void
    {
        // Split from the accept test on purpose: withToken() cannot replace a
        // bearer token that is already set on the test case.
        $admin = User::factory()->create(['role' => 'admin']);
        $unit = $this->workspaceFor($this->owner())->units()->first();
        $user = $this->customer();

        $booking = Booking::create([
            'ref' => 'BK-4242',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'start_datetime' => now()->addDay()->setTime(10, 0),
            'end_datetime' => now()->addDay()->setTime(13, 0),
            'status' => 'pending',
            'total_price' => 450,
        ]);

        $token = $this->token($admin);

        $this->withToken($token)
            ->getJson('/api/admin/bookings/' . $booking->ref)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $booking->id)
            ->assertJsonPath('data.ref', 'BK-4242');

        // Rows written while refs still carried a "#" stay reachable.
        $legacy = Booking::create([
            'ref' => '#BK-7777',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'start_datetime' => now()->addDay()->setTime(10, 0),
            'end_datetime' => now()->addDay()->setTime(13, 0),
            'status' => 'pending',
            'total_price' => 450,
        ]);

        $this->withToken($token)
            ->getJson('/api/admin/bookings/BK-7777')
            ->assertStatus(200)
            ->assertJsonPath('data.id', $legacy->id);
    }

    // -------------------------------------------------------------------------
    // Backfill of historic refs — see the migration that these tests drive:
    // database/migrations/2026_10_01_130000_backfill_missing_booking_and_dispute_refs.php
    // -------------------------------------------------------------------------

    /**
     * Run the backfill migration the way `php artisan migrate` would.
     *
     * RefreshDatabase already applied it once for this test run, against an
     * empty bookings/disputes table, so it was a no-op. Re-running it here is
     * what proves it does something on the rows the historic data left behind.
     */
    private function runRefBackfill(): void
    {
        $migration = require database_path(
            'migrations/2026_10_01_130000_backfill_missing_booking_and_dispute_refs.php'
        );

        $migration->up();
    }

    /**
     * disputes.ref is declared NOT NULL by the migration that created the
     * table, yet production carries NULL refs on the rows written before the
     * reference convention was settled. Relax the constraint so a test can
     * reproduce the shape the backfill actually has to repair; without this the
     * insert of a NULL ref is rejected outright and the backfill is untested.
     */
    private function allowNullDisputeRefs(): void
    {
        Schema::table('disputes', fn (Blueprint $table) => $table->string('ref')->nullable()->change());
    }

    private function aBooking(?string $ref, User $user, Unit $unit): Booking
    {
        return Booking::create([
            'ref' => $ref,
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'start_datetime' => now()->addDay()->setTime(10, 0),
            'end_datetime' => now()->addDay()->setTime(13, 0),
            'status' => 'pending',
            'total_price' => 450,
        ]);
    }

    private function aDispute(?string $ref, Booking $booking, User $user, string $status = 'open'): Dispute
    {
        return Dispute::create([
            'ref' => $ref,
            'booking_id' => $booking->id,
            'user_id' => $user->id,
            'issue' => 'نزاع على الحجز',
            'status' => $status,
            'opened_at' => now(),
        ]);
    }

    public function test_the_backfill_fills_missing_booking_and_dispute_references(): void
    {
        // The admin detail endpoints resolve a ref straight out of the URL, so
        // a NULL ref means the row can never be opened from the panel.
        $this->allowNullDisputeRefs();

        $user = $this->customer();
        $unit = $this->workspaceFor($this->owner())->units()->first();

        $nullBooking = $this->aBooking(null, $user, $unit);
        $blankBooking = $this->aBooking('', $user, $unit);
        $nullDispute = $this->aDispute(null, $nullBooking, $user);
        $blankDispute = $this->aDispute('', $nullBooking, $user);

        $this->runRefBackfill();

        // No "#" prefix: a "#" in a path segment is read as the start of the
        // fragment and stripped by the client before the request is sent.
        $this->assertSame('BK-' . $nullBooking->id, $nullBooking->fresh()->ref);
        $this->assertSame('BK-' . $blankBooking->id, $blankBooking->fresh()->ref);

        // Dispute ids are padded to three digits.
        $this->assertSame('DIS-' . str_pad((string) $nullDispute->id, 3, '0', STR_PAD_LEFT), $nullDispute->fresh()->ref);
        $this->assertSame('DIS-' . str_pad((string) $blankDispute->id, 3, '0', STR_PAD_LEFT), $blankDispute->fresh()->ref);

        $this->assertMatchesRegularExpression('/^BK-\d+$/', $nullBooking->fresh()->ref);
        $this->assertMatchesRegularExpression('/^DIS-\d{3,}$/', $nullDispute->fresh()->ref);
    }

    public function test_the_backfill_pads_dispute_ids_to_three_digits(): void
    {
        // id 1 must become DIS-001 and id 11 must become DIS-011 — not DIS-1,
        // not DIS-11, not DIS-00011.
        $this->allowNullDisputeRefs();

        $user = $this->customer();
        $booking = $this->aBooking(null, $user, $this->workspaceFor($this->owner())->units()->first());

        $disputes = collect(range(1, 11))->map(fn () => $this->aDispute(null, $booking, $user));

        $this->runRefBackfill();

        $this->assertSame('DIS-001', $disputes->first()->fresh()->ref);
        $this->assertSame('DIS-011', $disputes->last()->fresh()->ref);

        $refs = $disputes->map(fn (Dispute $d) => $d->fresh()->ref)->all();

        $this->assertCount(11, array_unique($refs), 'Backfilled dispute refs collided.');
    }

    public function test_running_the_backfill_twice_changes_nothing(): void
    {
        $this->allowNullDisputeRefs();

        $user = $this->customer();
        $unit = $this->workspaceFor($this->owner())->units()->first();

        $booking = $this->aBooking(null, $user, $unit);
        $alreadyFilled = $this->aBooking('BK-4242', $user, $unit);
        $dispute = $this->aDispute(null, $booking, $user);

        $this->runRefBackfill();

        $afterFirstRun = [
            'bookings' => Booking::orderBy('id')->pluck('ref', 'id')->all(),
            'disputes' => Dispute::orderBy('id')->pluck('ref', 'id')->all(),
        ];

        $this->runRefBackfill();

        // A second pass finds no NULL / '' row left to write, so every ref —
        // backfilled or pre-existing — is byte for byte what it was.
        $this->assertSame($afterFirstRun['bookings'], Booking::orderBy('id')->pluck('ref', 'id')->all());
        $this->assertSame($afterFirstRun['disputes'], Dispute::orderBy('id')->pluck('ref', 'id')->all());

        $this->assertSame('BK-4242', $alreadyFilled->fresh()->ref);
        $this->assertSame('BK-' . $booking->id, $booking->fresh()->ref);
        $this->assertSame('DIS-' . str_pad((string) $dispute->id, 3, '0', STR_PAD_LEFT), $dispute->fresh()->ref);
    }

    public function test_the_backfill_leaves_a_legacy_hash_prefixed_reference_alone(): void
    {
        // Rows written while refs still carried a "#" must keep it: the panel
        // already links to them, and the controllers accept both spellings.
        $admin = User::factory()->create(['role' => 'admin']);
        $user = $this->customer();
        $unit = $this->workspaceFor($this->owner())->units()->first();

        $legacy = $this->aBooking('#BK-7777', $user, $unit);
        $missing = $this->aBooking(null, $user, $unit);

        $this->runRefBackfill();

        $this->assertSame('#BK-7777', $legacy->fresh()->ref);
        $this->assertSame('BK-' . $missing->id, $missing->fresh()->ref);

        // Still resolvable through the endpoint, under both spellings.
        $token = $this->token($admin);

        $this->withToken($token)
            ->getJson('/api/admin/bookings/BK-7777')
            ->assertStatus(200)
            ->assertJsonPath('data.id', $legacy->id)
            ->assertJsonPath('data.ref', '#BK-7777');

        $this->withToken($token)
            ->getJson('/api/admin/bookings/BK-' . $missing->id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $missing->id)
            ->assertJsonPath('data.ref', 'BK-' . $missing->id);
    }

    public function test_accept_offer_rejects_an_already_rejected_offer(): void
    {
        $customer = $this->customer();
        $specialRequest = $this->requestFrom($customer);
        $workspace = $this->workspaceFor($this->owner());

        $offer = Offer::create([
            'special_request_id' => $specialRequest->id,
            'workspace_id' => $workspace->id,
            'price_per_hour' => 50,
            'duration_hours' => 1,
            'status' => 'rejected',
        ]);

        $this->withToken($this->token($customer))
            ->postJson("/api/special-requests/{$specialRequest->id}/offers/{$offer->id}/accept")
            ->assertStatus(422)
            ->assertJsonPath('message', 'العرض غير متاح.');
    }

    public function test_close_request_closes_pending_offers(): void
    {
        $customer = $this->customer();
        $specialRequest = $this->requestFrom($customer);
        $workspace = $this->workspaceFor($this->owner());

        $offer = Offer::create([
            'special_request_id' => $specialRequest->id,
            'workspace_id' => $workspace->id,
            'price_per_hour' => 50,
            'duration_hours' => 1,
            'status' => 'pending',
        ]);

        $this->withToken($this->token($customer))
            ->postJson("/api/special-requests/{$specialRequest->id}/close")
            ->assertStatus(200)
            ->assertJsonPath('request.status', 'closed');

        $this->assertDatabaseHas('offers', ['id' => $offer->id, 'status' => 'closed']);
    }

    public function test_list_returns_the_frontend_shape(): void
    {
        $customer = $this->customer();
        $specialRequest = $this->requestFrom($customer, [
            'schedule_preset' => 'weekly',
            'schedule_count' => 8,
            'amenities' => ['internet', 'projector'],
        ]);
        $this->workspaceFor($this->owner());

        $response = $this->withToken($this->token($customer))
            ->getJson('/api/special-requests');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['requests'],
                'pagination' => ['current_page', 'last_page', 'total'],
            ])
            ->assertJsonPath('data.requests.0.request_id', $specialRequest->id)
            ->assertJsonPath('data.requests.0.offers_count', 0)
            ->assertJsonPath('data.requests.0.schedule_label', 'أسبوعي × 8');
    }

    public function test_show_returns_the_offers_array(): void
    {
        // Without a populated offers array the customer sees an empty list with
        // no error to explain it.
        $customer = $this->customer();
        $specialRequest = $this->requestFrom($customer);
        $workspace = $this->workspaceFor($this->owner());

        Offer::create([
            'special_request_id' => $specialRequest->id,
            'workspace_id' => $workspace->id,
            'price_per_hour' => 150,
            'duration_hours' => 3,
            'currency' => 'ش.ج',
            'notes' => 'مجهزة بشاشة عرض',
            'status' => 'pending',
        ]);

        $response = $this->withToken($this->token($customer))
            ->getJson("/api/special-requests/{$specialRequest->id}");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'offers')
            ->assertJsonPath('offers.0.space_name', 'مساحة الاختبار')
            ->assertJsonPath('offers.0.price_per_hour', 150)
            ->assertJsonPath('offers.0.currency', 'ش.ج')
            ->assertJsonPath('offers.0.status', 'pending');
    }

    public function test_store_offer_returns_the_formatted_offer(): void
    {
        $owner = $this->owner();
        $specialRequest = $this->requestFrom($this->customer());
        $workspace = $this->workspaceFor($owner);

        $this->withToken($this->token($owner))
            ->postJson("/api/special-requests/{$specialRequest->id}/offers", [
                'space_id' => $workspace->id,
                'price_per_hour' => 150,
                'duration_hours' => 3,
                'notes' => 'شاشة عرض 120 بوصة',
                'currency' => 'ش.ج',
            ])
            ->assertStatus(201)
            ->assertJsonPath('offer.space_name', 'مساحة الاختبار')
            ->assertJsonPath('offer.price_per_hour', 150)
            ->assertJsonPath('offer.status', 'pending')
            ->assertJsonPath('offer.location', 'الرياض');
    }

    public function test_open_feed_excludes_expired_requests(): void
    {
        $owner = $this->owner();
        $this->requestFrom($this->customer());
        $this->requestFrom($this->customer(), [
            'expires_at' => now()->subDay(),
        ]);

        $this->withToken($this->token($owner))
            ->getJson('/api/special-requests/open')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.requests');
    }

    public function test_store_requires_schedule_count_for_recurring_presets(): void
    {
        $customer = $this->customer();

        $this->withToken($this->token($customer))
            ->postJson('/api/special-requests', [
                'title' => 'طلب',
                'description' => 'وصف',
                'space_type' => 'room',
                'capacity' => 5,
                'schedule_preset' => 'weekly',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('schedule_count');
    }

    public function test_store_accepts_a_human_readable_preferred_time(): void
    {
        // The docs show "10:00 ص – 1:00 م", which a date_format:H:i rule rejects.
        $response = $this->withToken($this->token($this->customer()))
            ->postJson('/api/special-requests', [
                'title' => 'قاعة محاضرات',
                'description' => 'أبحث عن قاعة تتسع لـ 40 متدرباً',
                'space_type' => 'whole',
                'capacity' => 40,
                'schedule_preset' => 'weekly',
                'schedule_count' => 8,
                'preferred_time' => '10:00 ص – 1:00 م',
                'area' => 'وسط المدينة',
                'amenities' => ['internet', 'projector', 'ac'],
                'budget' => 180,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'request' => ['request_id', 'status', 'offers_count']]);
    }

    public function test_notifications_expose_text_and_read(): void
    {
        $customer = $this->customer();
        Notification::create([
            'user_id' => $customer->id,
            'type' => 'special_request_offer',
            'message' => 'عرض جديد على طلبك',
            'is_read' => false,
        ]);

        $this->withToken($this->token($customer))
            ->getJson('/api/notifications')
            ->assertStatus(200)
            ->assertJsonPath('data.notifications.0.text', 'عرض جديد على طلبك')
            ->assertJsonPath('data.notifications.0.read', false);
    }

    public function test_owner_offers_list_uses_the_frontend_shape(): void
    {
        $owner = $this->owner();
        $customer = $this->customer();
        $specialRequest = $this->requestFrom($customer, ['title' => 'قاعة محاضرات']);
        $workspace = $this->workspaceFor($owner);

        Offer::create([
            'special_request_id' => $specialRequest->id,
            'workspace_id' => $workspace->id,
            'price_per_hour' => 150,
            'duration_hours' => 3,
            'status' => 'pending',
        ]);

        $this->withToken($this->token($owner))
            ->getJson('/api/owner/offers')
            ->assertStatus(200)
            ->assertJsonPath('data.0.request_id', $specialRequest->id)
            ->assertJsonPath('data.0.request_title', 'قاعة محاضرات')
            ->assertJsonPath('data.0.status', 'pending');
    }
}

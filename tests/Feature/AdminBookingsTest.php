<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Dispute;
use App\Models\Pricing;
use App\Models\Unit;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBookingsTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return $user->createToken('auth_token')->plainTextToken;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active', 'verified_at' => now()]);
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => 'customer']);
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'space_owner']);
    }

    private function workspaceFor(User $owner, array $overrides = []): Workspace
    {
        return Workspace::create(array_merge([
            'owner_id' => $owner->id,
            'title' => 'Test Space',
            'location' => 'Gaza',
            'latitude' => 31.5017,
            'longitude' => 34.4668,
            'contact_phone' => '0500000000',
            'status' => 'approved',
            'open_time' => '08:00',
            'close_time' => '22:00',
            'is_closed' => false,
            'is_active' => true,
        ], $overrides));
    }

    private function unitFor(Workspace $workspace, array $overrides = []): Unit
    {
        return Unit::create(array_merge([
            'workspace_id' => $workspace->id,
            'type' => 'desk',
            'capacity' => '4',
            'has_wifi' => true,
            'has_power' => true,
            'status' => 'available',
        ], $overrides));
    }

    private function hourlyPricingFor(Unit $unit, string $price = '50'): Pricing
    {
        return Pricing::create([
            'unit_id' => $unit->id,
            'price_type' => 'hourly',
            'price' => $price,
            'currency' => 'ILS',
        ]);
    }

    private function createBooking(User $customer, Unit $unit, array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'user_id' => $customer->id,
            'unit_id' => $unit->id,
            'start_datetime' => now()->addDays(3)->setTime(10, 0),
            'end_datetime' => now()->addDays(3)->setTime(13, 0),
            'status' => 'pending',
            'total_price' => 150,
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // PATCH /api/admin/bookings/{id}/status
    // -------------------------------------------------------------------------

    public function test_admin_can_set_booking_to_confirmed(): void
    {
        $admin = $this->admin();
        $adminToken = $this->token($admin);

        $customer = $this->customer();
        $owner = $this->owner();
        $workspace = $this->workspaceFor($owner);
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $booking = $this->createBooking($customer, $unit, ['status' => 'pending', 'ref' => 'BK-1001']);

        $response = $this->withToken($adminToken)
            ->patchJson("/api/admin/bookings/{$booking->id}/status", ['status' => 'confirmed']);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $booking->id)
            ->assertJsonPath('data.ref', 'BK-1001')
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    public function test_admin_can_set_booking_to_completed(): void
    {
        $admin = $this->admin();
        $adminToken = $this->token($admin);

        $customer = $this->customer();
        $owner = $this->owner();
        $workspace = $this->workspaceFor($owner);
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $booking = $this->createBooking($customer, $unit, ['status' => 'confirmed', 'ref' => 'BK-1002']);

        $response = $this->withToken($adminToken)
            ->patchJson("/api/admin/bookings/{$booking->id}/status", ['status' => 'completed']);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame('completed', $booking->fresh()->status);
    }

    public function test_admin_can_set_booking_to_disputed(): void
    {
        $admin = $this->admin();
        $adminToken = $this->token($admin);

        $customer = $this->customer();
        $owner = $this->owner();
        $workspace = $this->workspaceFor($owner);
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $booking = $this->createBooking($customer, $unit, ['status' => 'confirmed', 'ref' => 'BK-1003']);

        $response = $this->withToken($adminToken)
            ->patchJson("/api/admin/bookings/{$booking->id}/status", ['status' => 'disputed']);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'disputed');

        $this->assertSame('disputed', $booking->fresh()->status);
    }

    public function test_admin_cannot_update_status_when_dispute_is_open(): void
    {
        $admin = $this->admin();
        $adminToken = $this->token($admin);

        $customer = $this->customer();
        $owner = $this->owner();
        $workspace = $this->workspaceFor($owner);
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $booking = $this->createBooking($customer, $unit, ['status' => 'confirmed', 'ref' => 'BK-1004']);

        // Create an OPEN dispute on this booking
        Dispute::create([
            'booking_id' => $booking->id,
            'user_id' => $customer->id,
            'ref' => 'DIS-001',
            'issue' => 'Test dispute',
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $originalStatus = $booking->status;

        $response = $this->withToken($adminToken)
            ->patchJson("/api/admin/bookings/{$booking->id}/status", ['status' => 'completed']);

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Cannot update booking status while dispute is open');

        // Verify the booking status was NOT changed
        $this->assertSame($originalStatus, $booking->fresh()->status);
    }

    public function test_admin_booking_status_unknown_id_returns_404(): void
    {
        $admin = $this->admin();
        $adminToken = $this->token($admin);

        $response = $this->withToken($adminToken)
            ->patchJson('/api/admin/bookings/99999/status', ['status' => 'confirmed']);

        $response->assertStatus(404);
    }

    public function test_admin_booking_status_invalid_value_returns_422(): void
    {
        $admin = $this->admin();
        $adminToken = $this->token($admin);

        $customer = $this->customer();
        $owner = $this->owner();
        $workspace = $this->workspaceFor($owner);
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $booking = $this->createBooking($customer, $unit, ['ref' => 'BK-1005']);

        // 'cancelled' is NOT in the allowed enum per A6.3 spec
        $response = $this->withToken($adminToken)
            ->patchJson("/api/admin/bookings/{$booking->id}/status", ['status' => 'cancelled']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        // 'banana' also invalid
        $response = $this->withToken($adminToken)
            ->patchJson("/api/admin/bookings/{$booking->id}/status", ['status' => 'banana']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_admin_booking_status_missing_field_returns_422(): void
    {
        $admin = $this->admin();
        $adminToken = $this->token($admin);

        $customer = $this->customer();
        $owner = $this->owner();
        $workspace = $this->workspaceFor($owner);
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $booking = $this->createBooking($customer, $unit, ['ref' => 'BK-1006']);

        $response = $this->withToken($adminToken)
            ->patchJson("/api/admin/bookings/{$booking->id}/status", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_admin_booking_status_requires_authentication(): void
    {
        $customer = $this->customer();
        $owner = $this->owner();
        $workspace = $this->workspaceFor($owner);
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $booking = $this->createBooking($customer, $unit, ['ref' => 'BK-1007']);

        $this->patchJson("/api/admin/bookings/{$booking->id}/status", ['status' => 'confirmed'])
            ->assertStatus(401);
    }

    public function test_admin_booking_status_requires_admin_role(): void
    {
        $customer = $this->customer();
        $customerToken = $this->token($customer);

        $owner = $this->owner();
        $workspace = $this->workspaceFor($owner);
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $booking = $this->createBooking($customer, $unit, ['ref' => 'BK-1008']);

        $this->withToken($customerToken)
            ->patchJson("/api/admin/bookings/{$booking->id}/status", ['status' => 'confirmed'])
            ->assertStatus(403);
    }

    public function test_admin_booking_show_resolves_both_ref_formats(): void
    {
        $admin = $this->admin();
        $adminToken = $this->token($admin);

        $customer = $this->customer();
        $owner = $this->owner();
        $workspace = $this->workspaceFor($owner);
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $booking = $this->createBooking($customer, $unit, ['ref' => 'BK-1009', 'status' => 'confirmed']);

        // Without #
        $response = $this->withToken($adminToken)
            ->getJson("/api/admin/bookings/{$booking->ref}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $booking->id)
            ->assertJsonPath('data.ref', 'BK-1009');

        // With # (simulate legacy stored form)
        $legacyBooking = $this->createBooking($customer, $unit, ['ref' => '#BK-1010', 'status' => 'pending']);

        $response = $this->withToken($adminToken)
            ->getJson("/api/admin/bookings/{$legacyBooking->ref}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $legacyBooking->id);
    }
}
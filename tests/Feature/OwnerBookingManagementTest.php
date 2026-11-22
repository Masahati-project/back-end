<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Notification;
use App\Models\Pricing;
use App\Models\Unit;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Owner booking inbox and Accept/Reject decisions.
 *
 * Covers the contract in booking.md: pending rows are visible, ownership is
 * enforced server-side, transitions are idempotent and terminal, and the
 * customer receives exactly one notification per decision.
 */
class OwnerBookingManagementTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return $user->createToken('auth_token')->plainTextToken;
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => 'customer']);
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'space_owner']);
    }

    private function workspaceFor(User $owner): Workspace
    {
        return Workspace::create([
            'owner_id' => $owner->id,
            'title' => 'قاعة العروض الكبرى',
            'location' => 'غزة',
            'latitude' => 31.5017,
            'longitude' => 34.4668,
            'contact_phone' => '0500000000',
            'status' => 'active',
            'open_time' => '08:00',
            'close_time' => '22:00',
            'is_closed' => false,
            'is_active' => true,
        ]);
    }

    private function unitFor(Workspace $workspace): Unit
    {
        $unit = Unit::create([
            'workspace_id' => $workspace->id,
            'type' => 'desk',
            'capacity' => '4',
            'has_wifi' => true,
            'has_power' => true,
            'status' => 'available',
        ]);

        Pricing::create([
            'unit_id' => $unit->id,
            'price_type' => 'hourly',
            'price' => '150',
            'currency' => 'ILS',
        ]);

        return $unit;
    }

    private function bookingFor(User $customer, Unit $unit, string $status = 'pending'): Booking
    {
        return Booking::create([
            'user_id' => $customer->id,
            'unit_id' => $unit->id,
            'start_datetime' => now()->addDays(3)->setTime(10, 0),
            'end_datetime' => now()->addDays(3)->setTime(13, 0),
            'status' => $status,
            'total_price' => 450,
        ]);
    }

    public function test_owner_sees_pending_bookings_with_customer_name_and_price(): void
    {
        $owner = $this->owner();
        $customer = $this->customer();
        $booking = $this->bookingFor($customer, $this->unitFor($this->workspaceFor($owner)));

        $response = $this->withToken($this->token($owner))
            ->getJson('/api/owner/bookings');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.booking_id', $booking->id)
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.customer_name', $customer->full_name)
            ->assertJsonPath('data.0.price', 450)
            ->assertJsonPath('data.0.space_name', 'قاعة العروض الكبرى')
            ->assertJsonPath('bookings.0.booking_id', $booking->id);
    }

    public function test_owner_accepts_a_pending_booking_and_the_customer_is_notified_once(): void
    {
        $owner = $this->owner();
        $customer = $this->customer();
        $booking = $this->bookingFor($customer, $this->unitFor($this->workspaceFor($owner)));

        $token = $this->token($owner);

        $this->withToken($token)
            ->patchJson("/api/owner/bookings/{$booking->id}/status", ['status' => 'confirmed'])
            ->assertStatus(200)
            ->assertJsonPath('data.booking.status', 'confirmed');

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'confirmed']);

        $notification = Notification::where('user_id', $customer->id)
            ->where('type', 'booking_confirmed')
            ->firstOrFail();

        $this->assertSame('/ads/'.$booking->unit->workspace->id, $notification->link);
        $this->assertSame('confirmed', $notification->data['status']);
        $this->assertSame($booking->id, $notification->data['booking_id']);

        // Accepting again is a no-op: same 200, no second notification.
        $this->withToken($token)
            ->patchJson("/api/owner/bookings/{$booking->id}/status", ['status' => 'confirmed'])
            ->assertStatus(200)
            ->assertJsonPath('data.booking.status', 'confirmed');

        $this->assertSame(
            1,
            Notification::where('user_id', $customer->id)->where('type', 'booking_confirmed')->count()
        );
    }

    public function test_owner_rejection_marks_the_booking_rejected_and_notifies_the_customer(): void
    {
        $owner = $this->owner();
        $customer = $this->customer();
        $booking = $this->bookingFor($customer, $this->unitFor($this->workspaceFor($owner)));

        $this->withToken($this->token($owner))
            ->patchJson("/api/owner/bookings/{$booking->id}/status", ['status' => 'rejected'])
            ->assertStatus(200)
            ->assertJsonPath('data.booking.status', 'rejected');

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'rejected']);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $customer->id,
            'type' => 'booking_rejected',
        ]);
    }

    public function test_a_legacy_cancelled_rejection_is_stored_as_rejected(): void
    {
        $owner = $this->owner();
        $customer = $this->customer();
        $booking = $this->bookingFor($customer, $this->unitFor($this->workspaceFor($owner)));

        $this->withToken($this->token($owner))
            ->patchJson("/api/owner/bookings/{$booking->id}/status", ['status' => 'cancelled'])
            ->assertStatus(200)
            ->assertJsonPath('data.booking.status', 'rejected');

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'rejected']);
    }

    public function test_another_owner_cannot_change_the_booking_status(): void
    {
        $owner = $this->owner();
        $customer = $this->customer();
        $booking = $this->bookingFor($customer, $this->unitFor($this->workspaceFor($owner)));

        $otherOwner = $this->owner();

        $this->withToken($this->token($otherOwner))
            ->patchJson("/api/owner/bookings/{$booking->id}/status", ['status' => 'confirmed'])
            ->assertStatus(403);

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'pending']);
        $this->assertSame(0, Notification::where('user_id', $customer->id)->count());
    }

    public function test_a_customer_cannot_change_a_booking_status(): void
    {
        $customer = $this->customer();
        $booking = $this->bookingFor($customer, $this->unitFor($this->workspaceFor($this->owner())));

        $this->withToken($this->token($customer))
            ->patchJson("/api/owner/bookings/{$booking->id}/status", ['status' => 'confirmed'])
            ->assertStatus(403);

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'pending']);
    }

    public function test_status_must_be_confirmed_or_rejected(): void
    {
        $owner = $this->owner();
        $booking = $this->bookingFor($this->customer(), $this->unitFor($this->workspaceFor($owner)));

        $this->withToken($this->token($owner))
            ->patchJson("/api/owner/bookings/{$booking->id}/status", ['status' => 'pending'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_a_confirmed_booking_cannot_be_rejected(): void
    {
        $owner = $this->owner();
        $booking = $this->bookingFor(
            $this->customer(),
            $this->unitFor($this->workspaceFor($owner)),
            'confirmed'
        );

        $this->withToken($this->token($owner))
            ->patchJson("/api/owner/bookings/{$booking->id}/status", ['status' => 'rejected'])
            ->assertStatus(409);

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'confirmed']);
    }

    public function test_changing_an_unknown_booking_is_a_404(): void
    {
        $this->withToken($this->token($this->owner()))
            ->patchJson('/api/owner/bookings/999999/status', ['status' => 'confirmed'])
            ->assertStatus(404);
    }

    public function test_owner_notifications_endpoint_returns_link_and_data(): void
    {
        $owner = $this->owner();
        $customer = $this->customer();
        $workspace = $this->workspaceFor($owner);
        $this->unitFor($workspace);

        $created = $this->withToken($this->token($customer))
            ->postJson('/api/bookings', [
                'space_id' => $workspace->id,
                'booking_date' => now()->addDays(3)->format('Y-m-d'),
                'start_time' => '10:00',
                'hours' => 3,
            ]);

        $created->assertStatus(201);
        $bookingId = $created->json('data.booking.booking_id');

        Auth::forgetGuards();

        $this->withToken($this->token($owner))
            ->getJson('/api/notifications')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'notifications' => [
                        '*' => [
                            'id',
                            'type',
                            'title',
                            'text',
                            'link',
                            'data',
                            'read',
                            'is_read',
                            'created_at',
                        ],
                    ],
                ],
            ])
            ->assertJsonPath('data.notifications.0.type', 'booking_request')
            ->assertJsonPath('data.notifications.0.link', '/dashboard/space-owner?tab=bookings&booking='.$bookingId)
            ->assertJsonPath('data.notifications.0.data.booking_id', $bookingId)
            ->assertJsonPath('data.notifications.0.data.status', 'pending')
            ->assertJsonPath('data.notifications.0.data.customer_name', $customer->full_name)
            ->assertJsonPath('data.notifications.0.read', false);
    }

    public function test_customer_dashboard_bookings_returns_the_real_pending_status(): void
    {
        $customer = $this->customer();
        $booking = $this->bookingFor($customer, $this->unitFor($this->workspaceFor($this->owner())));

        $this->withToken($this->token($customer))
            ->getJson('/api/dashboard/bookings')
            ->assertStatus(200)
            ->assertJsonPath('data.0.booking_id', $booking->id)
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.price', 450)
            ->assertJsonPath('data.0.hours', 3);
    }

    public function test_mark_all_notifications_read_accepts_patch_and_post(): void
    {
        $customer = $this->customer();
        $owner = $this->owner();
        $booking = $this->bookingFor($customer, $this->unitFor($this->workspaceFor($owner)));

        Notification::create([
            'user_id' => $customer->id,
            'type' => 'booking_confirmed',
            'message' => 'تم تأكيد حجزك.',
            'is_read' => false,
        ]);

        $token = $this->token($customer);

        $this->withToken($token)
            ->patchJson('/api/notifications/read')
            ->assertStatus(200);

        $this->assertSame(
            0,
            Notification::where('user_id', $customer->id)
                ->where(function ($q) {
                    $q->where('is_read', false)->orWhereNull('is_read');
                })
                ->count()
        );

        Notification::create([
            'user_id' => $customer->id,
            'type' => 'booking_confirmed',
            'message' => 'تم تأكيد حجزك مرة أخرى.',
            'is_read' => false,
        ]);

        $this->withToken($token)
            ->postJson('/api/notifications/read')
            ->assertStatus(200);

        Auth::forgetGuards();
    }
}

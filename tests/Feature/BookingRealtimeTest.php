<?php

namespace Tests\Feature;

use App\Events\BookingRequested;
use App\Events\BookingStatusChanged;
use App\Models\Booking;
use App\Models\Notification;
use App\Models\Pricing;
use App\Models\Unit;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The real-time side of the booking flow.
 *
 * The notification row is the source of truth; these events are only the instant
 * trigger. Each event must go out on the right private channel, under the name
 * the client listens for, carrying the notification id — and a broadcaster that
 * blows up must not fail the booking.
 */
class BookingRealtimeTest extends TestCase
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

    private function unitFor(User $owner): Unit
    {
        $workspace = Workspace::create([
            'owner_id' => $owner->id,
            'title' => 'قاعة البث',
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
            'price' => '50',
            'currency' => 'ILS',
        ]);

        return $unit;
    }

    public function test_creating_a_booking_broadcasts_booking_requested_to_the_owner(): void
    {
        Event::fake([BookingRequested::class]);

        $owner = $this->owner();
        $customer = $this->customer();
        $unit = $this->unitFor($owner);
        $workspaceId = $unit->workspace_id;

        $this->withToken($this->token($customer))
            ->postJson('/api/bookings', [
                'space_id' => $workspaceId,
                'booking_date' => now()->addDays(3)->format('Y-m-d'),
                'start_time' => '10:00',
                'hours' => 2,
            ])
            ->assertStatus(201);

        Event::assertDispatched(BookingRequested::class, function (BookingRequested $event) use ($owner, $workspaceId) {
            $payload = $event->broadcastWith();

            return $event->broadcastOn()[0]->name === 'private-owner.'.$owner->id
                && $event->broadcastAs() === 'booking.requested'
                && $payload['space_id'] === $workspaceId
                && $payload['type'] === 'booking_request'
                && $payload['id'] === $event->notification->id;
        });
    }

    public function test_a_status_change_broadcasts_booking_status_changed_to_the_customer(): void
    {
        Event::fake([BookingStatusChanged::class]);

        $owner = $this->owner();
        $customer = $this->customer();
        $unit = $this->unitFor($owner);

        $booking = Booking::create([
            'user_id' => $customer->id,
            'unit_id' => $unit->id,
            'start_datetime' => now()->addDays(3)->setTime(10, 0),
            'end_datetime' => now()->addDays(3)->setTime(12, 0),
            'status' => 'pending',
            'total_price' => 100,
        ]);

        $this->withToken($this->token($owner))
            ->patchJson("/api/owner/bookings/{$booking->id}/status", ['status' => 'confirmed'])
            ->assertStatus(200);

        Event::assertDispatched(BookingStatusChanged::class, function (BookingStatusChanged $event) use ($customer) {
            $payload = $event->broadcastWith();

            return $event->broadcastOn()[0]->name === 'private-user.'.$customer->id
                && $event->broadcastAs() === 'booking.status_changed'
                && $payload['type'] === 'booking_confirmed'
                && $payload['space_id'] === $event->notification->data['space_id'];
        });
    }

    public function test_a_broadcast_failure_does_not_fail_the_booking(): void
    {
        Event::listen(BookingRequested::class, function () {
            throw new \RuntimeException('socket server is down');
        });

        $owner = $this->owner();
        $customer = $this->customer();
        $unit = $this->unitFor($owner);

        $this->withToken($this->token($customer))
            ->postJson('/api/bookings', [
                'space_id' => $unit->workspace_id,
                'booking_date' => now()->addDays(3)->format('Y-m-d'),
                'start_time' => '10:00',
                'hours' => 2,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('bookings', ['user_id' => $customer->id, 'status' => 'pending']);
        $this->assertSame(
            1,
            Notification::where('user_id', $owner->id)->where('type', 'booking_request')->count()
        );
    }
}

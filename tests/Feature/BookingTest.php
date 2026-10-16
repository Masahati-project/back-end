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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Customer bookings: POST /api/bookings and PATCH /api/bookings/{id}/cancel.
 *
 * There is no factory for Workspace / Unit / Pricing, so the fixtures are built
 * inline, and the frontend contract is space-centric while the schema is
 * unit-centric — nearly every test below exists because of that gap.
 *
 * Two things to know while reading:
 *
 * - The suite runs on SQLite :memory: but the assertions are written to be
 *   driver-independent: prices are compared as floats because the decimal(10,2)
 *   column serialises as a string, and no test leans on SQLite-specific SQL.
 *   The one thing SQLite cannot exercise is the row lock that keeps two
 *   concurrent bookings of the same unit from both winning — SQLite ignores
 *   FOR UPDATE — so the overlap tests below prove the rule, and the locking is
 *   reviewed rather than tested here. The reverse divergence (a MySQL-only
 *   migration widening bookings.status) is what tests/Feature/ModelTableTest.php
 *   was written for.
 * - Sanctum is driven with real bearer tokens through withToken(). Sanctum::actingAs()
 *   is not used anywhere in this repo.
 */
class BookingTest extends TestCase
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

    /**
     * A space that is actually on sale: approved, active, not deleted.
     *
     * A workspace only becomes bookable once the admin approves it AND the owner
     * activates it, so the default fixture has to carry both or every booking
     * test would be asserting against an unpublished space.
     */
    private function workspaceFor(User $owner, array $overrides = []): Workspace
    {
        return Workspace::create(array_merge([
            'owner_id' => $owner->id,
            'title' => 'مساحة الاختبار',
            'location' => 'غزة',
            'latitude' => 31.5017,
            'longitude' => 34.4668,
            'contact_phone' => '0500000000',
            'status' => 'active',
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

    /**
     * The payload the frontend sends, on a date far enough ahead that the
     * "not in the past" rule can never be the reason a test fails.
     */
    private function payload(Workspace $workspace, array $overrides = []): array
    {
        return array_merge([
            'space_id' => $workspace->id,
            'booking_date' => now()->addDays(3)->format('Y-m-d'),
            'start_time' => '10:00',
            'end_time' => '13:00',
            'hours' => 3,
        ], $overrides);
    }

    /**
     * A booking written straight to the table, standing in for whatever already
     * holds the slot. Same columns and same user_id that every creation path
     * writes — POST /api/bookings and SpecialRequestController::acceptOffer.
     */
    private function existingBooking(User $customer, Unit $unit, array $overrides = []): Booking
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

    public function test_a_customer_can_book_an_available_space(): void
    {
        $customer = $this->customer();
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $response = $this->withToken($this->token($customer))
            ->postJson('/api/bookings', $this->payload($workspace));

        $response->assertStatus(201)
            ->assertJsonPath('message', 'تم إنشاء الحجز بنجاح.')
            ->assertJsonPath('booking.status', 'pending')
            ->assertJsonStructure([
                'message',
                'booking' => [
                    'id',
                    'booking_id',
                    'status',
                    'price',
                    'space_id',
                    'space_name',
                    'date',
                    'time_from',
                    'time_to',
                    'hours',
                    'total_price',
                ],
            ]);

        $booking = Booking::firstOrFail();

        $response->assertJsonPath('booking.id', $booking->id)
            ->assertJsonPath('booking.booking_id', $booking->id)
            ->assertJsonPath('booking.space_id', $workspace->id);

        $this->assertDatabaseHas('bookings', [
            'user_id' => $customer->id,
            'unit_id' => $unit->id,
            'status' => 'pending',
            'total_price' => 150,
        ]);
    }

    public function test_the_created_booking_gets_a_reference(): void
    {
        $customer = $this->customer();
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $this->withToken($this->token($customer))
            ->postJson('/api/bookings', $this->payload($workspace))
            ->assertStatus(201);

        $booking = Booking::firstOrFail();

        $this->assertSame('BK-' . $booking->id, $booking->ref);
    }

    public function test_the_price_is_computed_on_the_server_from_the_hourly_rate(): void
    {
        $customer = $this->customer();
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $response = $this->withToken($this->token($customer))
            ->postJson('/api/bookings', $this->payload($workspace, ['hours' => 1, 'total_price' => 5]));

        $response->assertStatus(201)
            ->assertJsonPath('booking.hours', 3)
            ->assertJsonPath('booking.price', 150)
            ->assertJsonPath('booking.total_price', 150);

        $this->assertIsNotString($response->json('booking.price'));
        $this->assertIsNumeric($response->json('booking.price'));
        $this->assertEqualsWithDelta(150.0, $response->json('booking.price'), 0.001);
        $this->assertDatabaseHas('bookings', ['total_price' => 150]);
    }

    public function test_a_partial_hour_is_priced_proportionally(): void
    {
        $customer = $this->customer();
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $response = $this->withToken($this->token($customer))
            ->postJson('/api/bookings', $this->payload($workspace, [
                'start_time' => '10:00',
                'end_time' => '11:30',
            ]));

        $response->assertStatus(201)
            ->assertJsonPath('booking.hours', 1.5)
            ->assertJsonPath('booking.price', 75);

        $this->assertDatabaseHas('bookings', ['total_price' => 75]);
    }

    public function test_clock_times_may_carry_seconds(): void
    {
        $customer = $this->customer();
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $this->withToken($this->token($customer))
            ->postJson('/api/bookings', $this->payload($workspace, [
                'start_time' => '10:00:00',
                'end_time' => '13:00:00',
            ]))
            ->assertStatus(201)
            ->assertJsonPath('booking.time_from', '10:00')
            ->assertJsonPath('booking.time_to', '13:00');
    }

    public function test_the_space_and_the_time_are_echoed_back_for_the_booking_screen(): void
    {
        $customer = $this->customer();
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $response = $this->withToken($this->token($customer))
            ->postJson('/api/bookings', $this->payload($workspace));

        $response->assertStatus(201)
            ->assertJsonPath('booking.space_name', 'مساحة الاختبار')
            ->assertJsonPath('booking.date', now()->addDays(3)->format('Y-m-d'))
            ->assertJsonPath('booking.time_from', '10:00')
            ->assertJsonPath('booking.start_time', '10:00')
            ->assertJsonPath('booking.time_to', '13:00')
            ->assertJsonPath('booking.end_time', '13:00')
            ->assertJsonPath('booking.cost', 150);
    }

    public function test_booking_requires_authentication(): void
    {
        $workspace = $this->workspaceFor($this->owner());
        $this->unitFor($workspace);

        $this->postJson('/api/bookings', $this->payload($workspace))->assertStatus(401);
    }

    public function test_a_space_owner_cannot_book_a_space(): void
    {
        $owner = $this->owner();
        $workspace = $this->workspaceFor($owner);
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $this->withToken($this->token($owner))
            ->postJson('/api/bookings', $this->payload($workspace))
            ->assertStatus(403);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_an_admin_cannot_book_through_the_customer_endpoint(): void
    {
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->withToken($this->token($admin))
            ->postJson('/api/bookings', $this->payload($workspace))
            ->assertStatus(403);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_booking_validates_its_payload(): void
    {
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $this->withToken($this->token($this->customer()))
            ->postJson('/api/bookings', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['space_id', 'booking_date', 'start_time', 'end_time']);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_an_unknown_space_is_a_validation_error(): void
    {
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $this->withToken($this->token($this->customer()))
            ->postJson('/api/bookings', $this->payload($workspace, ['space_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('space_id');
    }

    public function test_a_space_that_is_not_approved_and_active_cannot_be_booked(): void
    {
        $customer = $this->customer();
        $owner = $this->owner();

        $pending = $this->workspaceFor($owner, ['status' => 'pending']);
        $this->unitFor($pending);
        $inactive = $this->workspaceFor($owner, ['is_active' => false]);
        $this->unitFor($inactive);

        $token = $this->token($customer);

        $this->withToken($token)
            ->postJson('/api/bookings', $this->payload($pending))
            ->assertStatus(422)
            ->assertJsonValidationErrors('space_id');

        $this->withToken($token)
            ->postJson('/api/bookings', $this->payload($inactive))
            ->assertStatus(422)
            ->assertJsonValidationErrors('space_id');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_a_soft_deleted_space_cannot_be_booked(): void
    {
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        DB::table('workspaces')->where('id', $workspace->id)->update(['deleted_at' => now()]);

        $this->withToken($this->token($this->customer()))
            ->postJson('/api/bookings', $this->payload($workspace))
            ->assertStatus(422)
            ->assertJsonValidationErrors('space_id');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_a_booking_date_in_the_past_is_rejected(): void
    {
        $customer = $this->customer();
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $token = $this->token($customer);

        $this->withToken($token)
            ->postJson('/api/bookings', $this->payload($workspace, [
                'booking_date' => now()->subDay()->format('Y-m-d'),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('booking_date');

        $this->withToken($token)
            ->postJson('/api/bookings', $this->payload($workspace))
            ->assertStatus(201);
    }

    public function test_an_end_time_before_the_start_time_is_rejected(): void
    {
        $customer = $this->customer();
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $this->withToken($this->token($customer))
            ->postJson('/api/bookings', $this->payload($workspace, [
                'start_time' => '15:00',
                'end_time' => '11:00',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_time');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_a_zero_length_booking_is_rejected(): void
    {
        $customer = $this->customer();
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $this->withToken($this->token($customer))
            ->postJson('/api/bookings', $this->payload($workspace, [
                'start_time' => '10:00',
                'end_time' => '10:00:00',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_time');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_a_space_with_several_available_units_books_the_first_one(): void
    {
        $customer = $this->customer();
        $workspace = $this->workspaceFor($this->owner());
        $first = $this->unitFor($workspace);
        $second = $this->unitFor($workspace);
        $this->hourlyPricingFor($first);
        $this->hourlyPricingFor($second, '80');

        $this->withToken($this->token($customer))
            ->postJson('/api/bookings', $this->payload($workspace))
            ->assertStatus(201);

        $this->assertDatabaseHas('bookings', ['unit_id' => $first->id]);
        $this->assertDatabaseMissing('bookings', ['unit_id' => $second->id]);
    }

    public function test_an_explicit_unit_id_is_honoured_when_it_belongs_to_the_space(): void
    {
        $customer = $this->customer();
        $workspace = $this->workspaceFor($this->owner());
        $first = $this->unitFor($workspace);
        $second = $this->unitFor($workspace);
        $this->hourlyPricingFor($first);
        $this->hourlyPricingFor($second, '80');

        $this->withToken($this->token($customer))
            ->postJson('/api/bookings', $this->payload($workspace, ['unit_id' => $second->id]))
            ->assertStatus(201);

        $this->assertDatabaseHas('bookings', ['unit_id' => $second->id, 'total_price' => 240]);
    }

    public function test_a_unit_from_another_space_is_rejected(): void
    {
        $customer = $this->customer();
        $owner = $this->owner();
        $workspace = $this->workspaceFor($owner);
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $foreignUnit = $this->unitFor($this->workspaceFor($owner));
        $this->hourlyPricingFor($foreignUnit);

        $this->withToken($this->token($customer))
            ->postJson('/api/bookings', $this->payload($workspace, ['unit_id' => $foreignUnit->id]))
            ->assertStatus(422);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_a_space_with_no_available_unit_cannot_be_booked(): void
    {
        $customer = $this->customer();
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace, ['status' => 'unavailable']);
        $this->hourlyPricingFor($unit);

        $this->withToken($this->token($customer))
            ->postJson('/api/bookings', $this->payload($workspace))
            ->assertStatus(422);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_a_unit_without_hourly_pricing_cannot_be_booked(): void
    {
        $customer = $this->customer();
        $workspace = $this->workspaceFor($this->owner());
        $this->unitFor($workspace);

        $response = $this->withToken($this->token($customer))
            ->postJson('/api/bookings', $this->payload($workspace));

        $response->assertStatus(422)
            ->assertJsonPath('message', 'لا يوجد سعر ساعي محدد لهذه الوحدة، ولا يمكن إتمام الحجز الآن.');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_a_booking_outside_the_opening_hours_is_rejected(): void
    {
        $customer = $this->customer();
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $token = $this->token($customer);

        $this->withToken($token)
            ->postJson('/api/bookings', $this->payload($workspace, [
                'start_time' => '21:00',
                'end_time' => '23:00',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'الوقت المختار خارج ساعات عمل المساحة.');

        $this->withToken($token)
            ->postJson('/api/bookings', $this->payload($workspace, [
                'start_time' => '06:00',
                'end_time' => '08:00',
            ]))
            ->assertStatus(422);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_a_slot_that_ends_exactly_at_closing_time_is_accepted(): void
    {
        $customer = $this->customer();
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $this->withToken($this->token($customer))
            ->postJson('/api/bookings', $this->payload($workspace, [
                'start_time' => '20:00',
                'end_time' => '22:00',
            ]))
            ->assertStatus(201);
    }

    public function test_an_overnight_opening_window_is_accepted(): void
    {
        $customer = $this->customer();
        $workspace = $this->workspaceFor($this->owner(), [
            'open_time' => '20:00',
            'close_time' => '04:00',
        ]);
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $this->withToken($this->token($customer))
            ->postJson('/api/bookings', $this->payload($workspace, [
                'start_time' => '21:00',
                'end_time' => '23:00',
            ]))
            ->assertStatus(201)
            ->assertJsonPath('booking.hours', 2);
    }

    public function test_an_overnight_space_still_rejects_a_slot_before_opening(): void
    {
        $customer = $this->customer();
        $workspace = $this->workspaceFor($this->owner(), [
            'open_time' => '20:00',
            'close_time' => '04:00',
        ]);
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $this->withToken($this->token($customer))
            ->postJson('/api/bookings', $this->payload($workspace, [
                'start_time' => '10:00',
                'end_time' => '11:00',
            ]))
            ->assertStatus(422);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_a_second_booking_over_the_same_window_is_a_conflict(): void
    {
        $customer = $this->customer();
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $this->existingBooking($customer, $unit);

        $response = $this->withToken($this->token($this->customer()))
            ->postJson('/api/bookings', $this->payload($workspace, [
                'start_time' => '11:00',
                'end_time' => '12:00',
            ]));

        $response->assertStatus(409)
            ->assertJsonPath('message', 'الفترة المختارة محجوزة مسبقاً في هذه المساحة، يرجى اختيار وقت آخر.');

        $this->assertArrayNotHasKey('errors', $response->json());
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_a_slot_that_starts_exactly_when_another_ends_can_be_booked(): void
    {
        $customer = $this->customer();
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $this->existingBooking($customer, $unit);

        $this->withToken($this->token($this->customer()))
            ->postJson('/api/bookings', $this->payload($workspace, [
                'start_time' => '13:00',
                'end_time' => '16:00',
            ]))
            ->assertStatus(201);

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_a_cancelled_booking_frees_the_slot(): void
    {
        $customer = $this->customer();
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $this->existingBooking($customer, $unit, ['status' => 'cancelled']);

        $this->withToken($this->token($customer))
            ->postJson('/api/bookings', $this->payload($workspace))
            ->assertStatus(201);

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_two_units_of_the_same_space_can_hold_the_same_window(): void
    {
        $firstCustomer = $this->customer();
        $secondCustomer = $this->customer();
        $workspace = $this->workspaceFor($this->owner());
        $firstUnit = $this->unitFor($workspace);
        $secondUnit = $this->unitFor($workspace);
        $this->hourlyPricingFor($firstUnit);
        $this->hourlyPricingFor($secondUnit);

        $this->existingBooking($firstCustomer, $firstUnit);

        $this->withToken($this->token($secondCustomer))
            ->postJson('/api/bookings', $this->payload($workspace, ['unit_id' => $secondUnit->id]))
            ->assertStatus(201);

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_the_same_idempotency_key_creates_one_booking_and_replays_it(): void
    {
        $customer = $this->customer();
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $token = $this->token($customer);

        $first = $this->withToken($token)
            ->withHeader('Idempotency-Key', 'retry-key-1')
            ->postJson('/api/bookings', $this->payload($workspace));

        $first->assertStatus(201);

        $second = $this->withToken($token)
            ->withHeader('Idempotency-Key', 'retry-key-1')
            ->postJson('/api/bookings', $this->payload($workspace));

        $second->assertStatus(200)
            ->assertJsonPath('booking.id', $first->json('booking.id'))
            ->assertJsonPath('booking.booking_id', $first->json('booking.id'));

        $this->assertSame(
            'تم استلام هذا الطلب مسبقاً، وهذا هو الحجز الأصلي.',
            $second->json('message')
        );

        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseCount('booking_idempotency_keys', 1);
    }

    public function test_a_replay_is_not_blocked_by_the_slot_it_already_holds(): void
    {
        $customer = $this->customer();
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $token = $this->token($customer);

        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'retry-key-2')
            ->postJson('/api/bookings', $this->payload($workspace))
            ->assertStatus(201);

        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'retry-key-2')
            ->postJson('/api/bookings', $this->payload($workspace))
            ->assertStatus(200);

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_two_customers_may_reuse_the_same_idempotency_key(): void
    {
        $firstCustomer = $this->customer();
        $secondCustomer = $this->customer();
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $this->withToken($this->token($firstCustomer))
            ->withHeader('Idempotency-Key', 'shared-key')
            ->postJson('/api/bookings', $this->payload($workspace))
            ->assertStatus(201);

        Auth::forgetGuards();

        $this->withToken($this->token($secondCustomer))
            ->withHeader('Idempotency-Key', 'shared-key')
            ->postJson('/api/bookings', $this->payload($workspace, [
                'start_time' => '14:00',
                'end_time' => '16:00',
            ]))
            ->assertStatus(201);

        $this->assertDatabaseCount('bookings', 2);
        $this->assertDatabaseCount('booking_idempotency_keys', 2);
    }

    public function test_a_booking_without_an_idempotency_key_is_processed_normally(): void
    {
        $customer = $this->customer();
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $token = $this->token($customer);

        $this->withToken($token)
            ->postJson('/api/bookings', $this->payload($workspace))
            ->assertStatus(201);

        $this->withToken($token)
            ->postJson('/api/bookings', $this->payload($workspace))
            ->assertStatus(409);

        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseCount('booking_idempotency_keys', 0);
    }

    public function test_an_over_long_idempotency_key_is_rejected(): void
    {
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $this->withToken($this->token($this->customer()))
            ->withHeader('Idempotency-Key', str_repeat('k', 256))
            ->postJson('/api/bookings', $this->payload($workspace))
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_a_customer_can_cancel_their_own_booking(): void
    {
        $customer = $this->customer();
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace);
        $this->hourlyPricingFor($unit);

        $this->withToken($this->token($customer))
            ->postJson('/api/bookings', $this->payload($workspace))
            ->assertStatus(201);

        $booking = Booking::firstOrFail();

        $response = $this->withToken($this->token($customer))
            ->patchJson("/api/bookings/{$booking->id}/cancel");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'تم إلغاء الحجز بنجاح.')
            ->assertJsonPath('booking.status', 'cancelled')
            ->assertJsonPath('booking.id', $booking->id);

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'cancelled']);
    }

    public function test_cancelling_twice_is_idempotent(): void
    {
        $customer = $this->customer();
        $booking = $this->existingBooking($customer, $this->unitFor($this->workspaceFor($this->owner())));

        $token = $this->token($customer);

        $this->withToken($token)
            ->patchJson("/api/bookings/{$booking->id}/cancel")
            ->assertStatus(200);

        $this->withToken($token)
            ->patchJson("/api/bookings/{$booking->id}/cancel")
            ->assertStatus(200)
            ->assertJsonPath('message', 'الحجز ملغى مسبقاً.')
            ->assertJsonPath('booking.status', 'cancelled');

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'cancelled']);
    }

    public function test_another_customer_cannot_cancel_someone_elses_booking(): void
    {
        $booker = $this->customer();
        $booking = $this->existingBooking($booker, $this->unitFor($this->workspaceFor($this->owner())));

        $this->withToken($this->token($this->customer()))
            ->patchJson("/api/bookings/{$booking->id}/cancel")
            ->assertStatus(403)
            ->assertJsonPath('message', 'لا يمكنك إلغاء حجز لا يخصك.');

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'pending']);
    }

    public function test_cancelling_an_unknown_booking_is_a_404(): void
    {
        $this->withToken($this->token($this->customer()))
            ->patchJson('/api/bookings/999999/cancel')
            ->assertStatus(404)
            ->assertJsonPath('message', 'الحجز غير موجود.');
    }

    public function test_cancelling_a_completed_booking_is_a_conflict(): void
    {
        $customer = $this->customer();
        $booking = $this->existingBooking($customer, $this->unitFor($this->workspaceFor($this->owner())), [
            'status' => 'completed',
        ]);

        $response = $this->withToken($this->token($customer))
            ->patchJson("/api/bookings/{$booking->id}/cancel");

        $response->assertStatus(409)
            ->assertJsonPath('message', 'لا يمكن إلغاء حجز بهذه الحالة.');

        $this->assertArrayNotHasKey('errors', $response->json());
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'completed']);
    }

    public function test_cancelling_a_checked_in_booking_is_a_conflict(): void
    {
        $customer = $this->customer();
        $booking = $this->existingBooking($customer, $this->unitFor($this->workspaceFor($this->owner())), [
            'status' => 'checked_in',
        ]);

        $this->withToken($this->token($customer))
            ->patchJson("/api/bookings/{$booking->id}/cancel")
            ->assertStatus(409);

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'checked_in']);
    }

    public function test_cancelling_notifies_the_space_owner(): void
    {
        $customer = $this->customer();
        $spaceOwner = $this->owner();
        $booking = $this->existingBooking($customer, $this->unitFor($this->workspaceFor($spaceOwner)));

        $this->withToken($this->token($customer))
            ->patchJson("/api/bookings/{$booking->id}/cancel")
            ->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $spaceOwner->id,
            'type' => 'booking_cancelled',
            'is_read' => false,
        ]);

        $this->assertFalse(
            Notification::where('user_id', $customer->id)->where('type', 'booking_cancelled')->exists()
        );
    }

    public function test_a_booking_created_from_an_accepted_offer_can_be_cancelled(): void
    {
        $customer = $this->customer();
        $workspace = $this->workspaceFor($this->owner());
        $unit = $this->unitFor($workspace);

        $booking = $this->existingBooking($customer, $unit, ['notes' => 'عرض خاص']);

        $this->withToken($this->token($customer))
            ->patchJson("/api/bookings/{$booking->id}/cancel")
            ->assertStatus(200)
            ->assertJsonPath('booking.status', 'cancelled')
            ->assertJsonPath('booking.space_name', 'مساحة الاختبار');

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'cancelled']);
    }

    public function test_cancelling_requires_authentication(): void
    {
        $booking = $this->existingBooking($this->customer(), $this->unitFor($this->workspaceFor($this->owner())));

        $this->patchJson("/api/bookings/{$booking->id}/cancel")->assertStatus(401);

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'pending']);
    }
}
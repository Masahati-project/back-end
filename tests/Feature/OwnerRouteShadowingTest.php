<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The five literal owner endpoints, and the free-parameter routes they sit next to.
 *
 * THE BUG. Owner routes were declared with unguarded parameters (`/{space}`, `/{ad}`),
 * so Laravel's matcher treats a literal word like `open`, `stats` or `published` as a
 * candidate id. The literal segment is then captured by the parameter route, which
 * does not answer GET — the request comes back 405 Method Not Allowed and the owner's
 * dashboard card never renders. The requirements doc cites /api/special-requests/open
 * as the proof that a numeric constraint fixes it: whereNumber() makes a non-numeric
 * segment unmatchable regardless of declaration order.
 *
 * The five literal owner endpoints did not exist at all before this work, so this file
 * also pins their behaviour. The route assertions at the top are written against the
 * FINAL expected route table and are meant to fail loudly — with a message that names
 * the missing piece — while routes/api.php still has the free parameters or is missing
 * the new lines. That is deliberate: a test that only ever passes once the integrator's
 * change lands is exactly the regression guard this workstream needs.
 */
class OwnerRouteShadowingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every literal segment under /api/owner that the requirements doc lists.
     *
     * Kept as one constant so the sweeps below cannot drift apart: if an endpoint is
     * added to one test and forgotten in another, the file stops proving anything.
     */
    private const LITERAL_ENDPOINTS = [
        '/api/owner/spaces/open',
        '/api/owner/spaces/stats',
        '/api/owner/ads/open',
        '/api/owner/ads/published',
        '/api/owner/ads/stats',
    ];

    private function token(User $user): string
    {
        return $user->createToken('auth_token')->plainTextToken;
    }

    private function owner(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => 'space_owner'], $attributes));
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => 'customer']);
    }

    /**
     * There is no WorkspaceFactory, so spaces are built inline.
     *
     * is_closed is NOT NULL with no default, and space_document_url is nullable only
     * because a later migration relaxed it, so both are set explicitly here rather
     * than relying on database defaults that differ between SQLite and MySQL.
     */
    private function spaceFor(User $owner, array $overrides = []): Workspace
    {
        return Workspace::create(array_merge([
            'owner_id' => $owner->id,
            'title' => 'مساحة الاختبار',
            'space_document_url' => 'documents/space.pdf',
            'location' => 'الرياض',
            'latitude' => 24.7136,
            'longitude' => 46.6753,
            'contact_phone' => '0500000000',
            'status' => 'pending',
            'open_time' => '08:00',
            'close_time' => '22:00',
            'is_closed' => false,
            'is_active' => false,
        ], $overrides));
    }

    /**
     * A space needs a unit before it can hold a booking, and the unit is also the
     * denominator of the occupancy figure GET /api/owner/spaces/stats reports.
     */
    private function unitFor(Workspace $space): Unit
    {
        return Unit::create([
            'workspace_id' => $space->id,
            'type' => 'full_space',
            'capacity' => '4',
            'has_wifi' => true,
            'has_power' => true,
            'status' => 'available',
        ]);
    }

    /**
     * A confirmed booking created now, i.e. inside the current month — the window
     * Workspace::getStats() and /api/owner/spaces/stats both filter on.
     */
    private function confirmedBooking(User $customer, Unit $unit, float $total): Booking
    {
        return Booking::create([
            'user_id' => $customer->id,
            'unit_id' => $unit->id,
            'start_datetime' => now(),
            'end_datetime' => now()->addHours(3),
            'status' => 'confirmed',
            'total_price' => $total,
        ]);
    }

    /**
     * There is no AdFactory, so ads are built inline.
     *
     * `schedule` is a JSON column cast to `json`, so an array is written as a JSON
     * object. Pass a JSON *string* instead to reproduce the shape OwnerAdController
     * actually stores — see test_a_json_encoded_schedule_still_expires.
     */
    private function adFor(User $owner, array $overrides = []): Ad
    {
        return Ad::create(array_merge([
            'user_id' => $owner->id,
            'title' => 'إعلان تجريبي',
            'description' => 'وصف الإعلان',
            'link' => null,
            'image' => null,
            'target' => 'customers',
            'status' => 'draft',
            'schedule' => null,
            'impressions' => 0,
            'sent_at' => null,
        ], $overrides));
    }

    private function publishedAd(User $owner, array $overrides = []): Ad
    {
        return $this->adFor($owner, array_merge(['status' => 'published'], $overrides));
    }

    /**
     * Asserts a literal owner endpoint was not swallowed by a free-parameter route.
     *
     * 405 is the signature failure: the URI matched a parameter route registered for
     * another verb, so the router refused the method before any handler ran. It is
     * asserted separately from the 200 because the failure message matters — a bare
     * "expected 200, got 405" sends the next reader looking at the handler instead of
     * at the route table.
     */
    private function assertNotShadowed($response, string $path): void
    {
        $this->assertNotSame(
            405,
            $response->getStatusCode(),
            "{$path} was captured by a free-parameter owner route and answered 405 Method Not Allowed."
        );
    }

    // -------------------------------------------------------------------------
    // The route table itself — the regression this whole file exists for
    // -------------------------------------------------------------------------

    public function test_the_five_literal_owner_routes_are_registered(): void
    {
        // The endpoints did not exist before this workstream, so a request to one of
        // them 404'd rather than 405'ing. This fails loudly, by name, until the route
        // lines are in routes/api.php.
        $expected = [
            'owner.spaces.open',
            'owner.spaces.stats',
            'owner.ads.open',
            'owner.ads.published',
            'owner.ads.stats',
        ];

        foreach ($expected as $name) {
            $this->assertNotNull(
                Route::getRoutes()->getByName($name),
                "route [{$name}] is not registered in routes/api.php"
            );
        }
    }

    public function test_every_free_parameter_owner_route_constrains_its_id_to_a_number(): void
    {
        // The fix itself. whereNumber() compiles to a [0-9]+ regex on the named
        // parameter, which is what makes `open`, `stats` and `published` unmatchable
        // as ids. The parameter names are checked as well as their presence: a guard
        // attached to the wrong name (the requirements doc proposed spaceId/adId while
        // the routes use space/ad) constrains nothing at all.
        $expected = [
            'owner.spaces.update' => 'space',
            'owner.spaces.toggle-active' => 'space',
            'owner.spaces.destroy' => 'space',
            'owner.ads.update' => 'ad',
            'owner.ads.destroy' => 'ad',
            'owner.ads.publish' => 'ad',
            'owner.bookings.update-status' => 'booking',
        ];

        foreach ($expected as $name => $parameter) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "route [{$name}] is missing from routes/api.php");

            $wheres = $route->wheres;

            $this->assertArrayHasKey(
                $parameter,
                $wheres,
                "route [{$name}] does not constrain {{$parameter}} to a number"
            );

            $this->assertStringContainsString(
                '[0-9]+',
                (string) $wheres[$parameter],
                "route [{$name}] constrains {{$parameter}} with '{$wheres[$parameter]}', which is not numeric"
            );
        }
    }

    public function test_the_literal_owner_routes_are_declared_before_the_parameterised_ones(): void
    {
        // Defence in depth. whereNumber() alone is sufficient, but routes/api.php
        // documents the same rule for /api/special-requests: the literal route is
        // declared first so that even a future unguarded parameter route cannot take
        // the word "open" while the literal one is still registered under the same
        // verb. Registration order is read from the compiled collection, which keeps
        // the order the routes were declared in.
        $names = array_keys(collect(Route::getRoutes()->getRoutes())->keyBy(
            fn ($route) => $route->getName()
        )->all());

        $pairs = [
            ['owner.spaces.open', 'owner.spaces.update'],
            ['owner.spaces.stats', 'owner.spaces.update'],
            ['owner.ads.open', 'owner.ads.update'],
            ['owner.ads.published', 'owner.ads.update'],
            ['owner.ads.stats', 'owner.ads.update'],
        ];

        foreach ($pairs as [$literal, $parameterised]) {
            $literalAt = array_search($literal, $names, true);
            $parameterisedAt = array_search($parameterised, $names, true);

            $this->assertNotFalse($literalAt, "route [{$literal}] is not registered");
            $this->assertNotFalse($parameterisedAt, "route [{$parameterised}] is not registered");

            $this->assertLessThan(
                $parameterisedAt,
                $literalAt,
                "[{$literal}] is declared after [{$parameterised}] and could be shadowed by it"
            );
        }
    }

    // -------------------------------------------------------------------------
    // The five endpoints answer, and answer with their own handler's body
    // -------------------------------------------------------------------------

    public function test_each_literal_owner_endpoint_answers_200_and_not_405(): void
    {
        // THE CORE ASSERTION. Every one of these five paths is one word away from a
        // free-parameter route that only answers PUT/PATCH/DELETE/POST, which is
        // exactly the combination that produced 405 in production.
        $token = $this->token($this->owner());

        foreach (self::LITERAL_ENDPOINTS as $path) {
            $response = $this->withToken($token)->getJson($path);

            $this->assertNotShadowed($response, $path);

            $response->assertStatus(200);
        }
    }

    public function test_each_literal_owner_endpoint_serves_its_own_handler(): void
    {
        // A 200 is not enough: the literal route must reach ITS handler and not some
        // other one. Each path is therefore asked for the key only that handler emits.
        $owner = $this->owner();
        $space = $this->spaceFor($owner, ['status' => 'approved', 'is_active' => true]);
        $this->publishedAd($owner);

        $token = $this->token($owner);

        $spacesOpen = $this->withToken($token)->getJson('/api/owner/spaces/open');
        $this->assertNotShadowed($spacesOpen, '/api/owner/spaces/open');
        $spacesOpen->assertStatus(200)
            ->assertJsonStructure(['spaces', 'message'])
            ->assertJsonPath('spaces.0.id', $space->id);

        $spacesStats = $this->withToken($token)->getJson('/api/owner/spaces/stats');
        $this->assertNotShadowed($spacesStats, '/api/owner/spaces/stats');
        $spacesStats->assertStatus(200)
            ->assertJsonStructure(['stats' => ['total', 'by_status', 'by_is_active'], 'message']);

        $adsOpen = $this->withToken($token)->getJson('/api/owner/ads/open');
        $this->assertNotShadowed($adsOpen, '/api/owner/ads/open');
        $adsOpen->assertStatus(200)
            ->assertJsonStructure(['ads', 'message'])
            ->assertJsonPath('ads.0.status', 'published');

        $adsPublished = $this->withToken($token)->getJson('/api/owner/ads/published');
        $this->assertNotShadowed($adsPublished, '/api/owner/ads/published');
        $adsPublished->assertStatus(200)
            ->assertJsonStructure(['ads', 'message'])
            ->assertJsonPath('ads.0.status', 'published');

        $adsStats = $this->withToken($token)->getJson('/api/owner/ads/stats');
        $this->assertNotShadowed($adsStats, '/api/owner/ads/stats');
        $adsStats->assertStatus(200)
            ->assertJsonStructure(['stats' => ['total', 'by_status', 'impressions'], 'message']);
    }

    public function test_the_literal_paths_answer_with_an_empty_list_rather_than_a_404(): void
    {
        // An owner with nothing yet must get an empty list, not a 404: the endpoint
        // existing is what is being pinned here, and {} versus [] is the difference
        // between "no data" and "malformed response" on the client.
        $token = $this->token($this->owner());

        $spacesOpen = $this->withToken($token)->getJson('/api/owner/spaces/open');
        $this->assertNotShadowed($spacesOpen, '/api/owner/spaces/open');
        $spacesOpen->assertStatus(200)->assertJsonCount(0, 'spaces');

        $adsOpen = $this->withToken($token)->getJson('/api/owner/ads/open');
        $this->assertNotShadowed($adsOpen, '/api/owner/ads/open');
        $adsOpen->assertStatus(200)->assertJsonCount(0, 'ads');

        $adsPublished = $this->withToken($token)->getJson('/api/owner/ads/published');
        $this->assertNotShadowed($adsPublished, '/api/owner/ads/published');
        $adsPublished->assertStatus(200)->assertJsonCount(0, 'ads');
    }

    // -------------------------------------------------------------------------
    // Role and authentication, on all five
    // -------------------------------------------------------------------------

    public function test_a_customer_token_is_refused_by_every_literal_owner_endpoint(): void
    {
        // ensureOwnerRole() is the first line of every method in the owner
        // controllers, so a customer gets a 403 from all five — including the two
        // stats endpoints, which a hand-rolled "if ($request->user()->isAdmin())"
        // shortcut would happily have leaked.
        $token = $this->token($this->customer());

        foreach (self::LITERAL_ENDPOINTS as $path) {
            $response = $this->withToken($token)->getJson($path);

            $this->assertNotShadowed($response, $path);

            $response->assertStatus(403);
        }
    }

    public function test_every_literal_owner_endpoint_requires_a_token(): void
    {
        // The routes live inside the auth:sanctum group. Without that middleware the
        // handlers would call Auth::user()->role on null and 500 instead of 401.
        foreach (self::LITERAL_ENDPOINTS as $path) {
            $response = $this->getJson($path);

            $this->assertNotShadowed($response, $path);

            $response->assertStatus(401);
        }
    }

    // -------------------------------------------------------------------------
    // Shadowing, from the other side: the numeric routes must not accept words
    // -------------------------------------------------------------------------

    public function test_a_non_numeric_segment_does_not_reach_the_numeric_owner_handlers(): void
    {
        // The other half of the guard. With an unguarded parameter these 404s become
        // 405s — the parameter route matches any word — which is how the literal
        // endpoints were being swallowed in the first place. 404 here means the word
        // can never reach a handler that expects an id.
        $token = $this->token($this->owner());

        $response = $this->withToken($token)->getJson('/api/owner/ads/not-a-number');
        $response->assertStatus(404);

        $response = $this->withToken($token)->getJson('/api/owner/spaces/not-a-number');
        $response->assertStatus(404);
    }

    public function test_a_non_numeric_id_does_not_reach_the_guarded_write_routes(): void
    {
        // Same guard, seen from the write verbs: the word must not be accepted as an
        // id by the routes that actually use one.
        $owner = $this->owner();
        $space = $this->spaceFor($owner);
        $ad = $this->adFor($owner);
        $token = $this->token($owner);

        $this->withToken($token)
            ->putJson('/api/owner/spaces/not-a-number', ['title' => 'محاولة'])
            ->assertStatus(404);

        $this->withToken($token)
            ->putJson('/api/owner/ads/not-a-number', ['title' => 'محاولة'])
            ->assertStatus(404);

        $this->withToken($token)
            ->postJson('/api/owner/ads/not-a-number/publish')
            ->assertStatus(404);

        $this->assertDatabaseHas('workspaces', ['id' => $space->id, 'title' => 'مساحة الاختبار']);
        $this->assertDatabaseHas('ads', ['id' => $ad->id, 'status' => 'draft']);
    }

    public function test_the_numeric_owner_routes_still_work_after_the_guard_is_added(): void
    {
        // A guard that breaks the real ids would trade one outage for another, so the
        // numeric path is pinned as well. If this ever fails with a TypeError about
        // string-to-int, the controller's `int $id` hint is the thing to look at.
        $owner = $this->owner();
        $space = $this->spaceFor($owner);
        $ad = $this->adFor($owner);
        $token = $this->token($owner);

        $this->withToken($token)
            ->putJson("/api/owner/spaces/{$space->id}", ['title' => 'مساحة معدّلة'])
            ->assertStatus(200)
            ->assertJsonPath('space.id', $space->id);

        $this->withToken($token)
            ->patchJson("/api/owner/spaces/{$space->id}/active", ['is_active' => true])
            ->assertStatus(200)
            ->assertJsonPath('space.is_active', true);

        $this->withToken($token)
            ->postJson("/api/owner/ads/{$ad->id}/publish")
            ->assertStatus(200)
            ->assertJsonPath('ad.status', 'published');

        $this->assertDatabaseHas('workspaces', ['id' => $space->id, 'title' => 'مساحة معدّلة']);
        $this->assertDatabaseHas('ads', ['id' => $ad->id, 'status' => 'published']);
    }

    public function test_a_numeric_id_still_cannot_reach_another_owners_space(): void
    {
        // The guard is about the shape of the segment, never about who owns it:
        // scoping still happens in ensureOwnsWorkspace().
        $owner = $this->owner();
        $foreign = $this->spaceFor($this->owner());

        $this->withToken($this->token($owner))
            ->putJson("/api/owner/spaces/{$foreign->id}", ['title' => 'محاولة'])
            ->assertStatus(403);

        $this->assertDatabaseHas('workspaces', ['id' => $foreign->id, 'title' => 'مساحة الاختبار']);
    }

    // -------------------------------------------------------------------------
    // GET /api/owner/spaces/open
    // -------------------------------------------------------------------------

    public function test_spaces_open_lists_only_approved_and_active_spaces(): void
    {
        // "Live" is two conditions and both are enforced server side: approved by an
        // admin, and switched on by the owner. Anything else is invisible to
        // customers, so listing it here would be a false positive on the dashboard.
        $owner = $this->owner();

        $live = $this->spaceFor($owner, [
            'title' => 'مساحة مفتوحة',
            'status' => 'approved',
            'is_active' => true,
        ]);
        $this->spaceFor($owner, [
            'title' => 'مساحة معطلة',
            'status' => 'approved',
            'is_active' => false,
        ]);
        $this->spaceFor($owner, [
            'title' => 'مساحة قيد المراجعة',
            'status' => 'pending',
            'is_active' => true,
        ]);
        $this->spaceFor($owner, [
            'title' => 'مساحة مرفوضة',
            'status' => 'rejected',
            'is_active' => true,
        ]);

        $response = $this->withToken($this->token($owner))
            ->getJson('/api/owner/spaces/open');

        $this->assertNotShadowed($response, '/api/owner/spaces/open');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'spaces')
            ->assertJsonPath('spaces.0.id', $live->id)
            ->assertJsonPath('spaces.0.title', 'مساحة مفتوحة')
            ->assertJsonPath('spaces.0.status', 'approved')
            ->assertJsonPath('spaces.0.is_active', true);
    }

    public function test_spaces_open_returns_the_same_projection_as_the_full_list(): void
    {
        // formatSpaceResponse() is reused rather than re-projected. Comparing the key
        // sets of an item from /owner/spaces with one from /owner/spaces/open is what
        // actually proves the two cannot drift: the card and the full list read the
        // same fields, and adding a field to one adds it to both.
        $owner = $this->owner();
        $this->spaceFor($owner, ['status' => 'approved', 'is_active' => true]);

        $token = $this->token($owner);

        $index = $this->withToken($token)->getJson('/api/owner/spaces')->assertStatus(200);
        $open = $this->withToken($token)->getJson('/api/owner/spaces/open');
        $this->assertNotShadowed($open, '/api/owner/spaces/open');
        $open->assertStatus(200);

        $this->assertSame(
            array_keys($index->json('spaces')[0]),
            array_keys($open->json('spaces')[0])
        );
    }

    public function test_spaces_open_ignores_a_soft_deleted_row(): void
    {
        // Workspace imports SoftDeletes but never applies the trait, so the query
        // builder does not filter the column the migration added. A space the owner
        // deleted is not live and must not be listed. The flag is set directly
        // because without the trait a delete() is a hard delete.
        $owner = $this->owner();
        $space = $this->spaceFor($owner, ['status' => 'approved', 'is_active' => true]);

        $space->deleted_at = now();
        $space->save();

        $this->withToken($this->token($owner))
            ->getJson('/api/owner/spaces/open')
            ->assertStatus(200)
            ->assertJsonCount(0, 'spaces');
    }

    // -------------------------------------------------------------------------
    // GET /api/owner/spaces/stats
    // -------------------------------------------------------------------------

    public function test_spaces_stats_counts_match_the_fixtures(): void
    {
        // The counters must describe the fixtures that were actually created, not a
        // plausible-looking set of defaults. Every number here is asserted against a
        // row that exists in the database at the end of the test.
        $owner = $this->owner();

        $this->spaceFor($owner, ['status' => 'approved', 'is_active' => true]);
        $this->spaceFor($owner, ['status' => 'approved', 'is_active' => false]);
        $this->spaceFor($owner, ['status' => 'pending', 'is_active' => true]);
        $this->spaceFor($owner, ['status' => 'rejected', 'is_active' => false]);

        $response = $this->withToken($this->token($owner))
            ->getJson('/api/owner/spaces/stats');

        $this->assertNotShadowed($response, '/api/owner/spaces/stats');

        $response->assertStatus(200)
            ->assertJsonPath('stats.total', 4)
            ->assertJsonPath('stats.total_spaces', 4)
            ->assertJsonPath('stats.approved', 2)
            ->assertJsonPath('stats.pending', 1)
            ->assertJsonPath('stats.rejected', 1)
            ->assertJsonPath('stats.active', 2)
            ->assertJsonPath('stats.inactive', 2)
            ->assertJsonPath('stats.by_status.approved', 2)
            ->assertJsonPath('stats.by_status.pending', 1)
            ->assertJsonPath('stats.by_status.rejected', 1)
            ->assertJsonPath('stats.by_is_active.active', 2)
            ->assertJsonPath('stats.by_is_active.inactive', 2)
            // The length of /owner/spaces/open: approved AND active.
            ->assertJsonPath('stats.live', 1)
            ->assertJsonPath('stats.spaces_count', 4)
            ->assertJsonPath('stats.truncated', false);

        $this->assertDatabaseCount('workspaces', 4);
    }

    public function test_spaces_stats_reports_zeroes_for_an_owner_with_no_spaces(): void
    {
        // A new account must see a rendered panel of zeros, never a 500 or a missing
        // key — every branch is read straight off the response body.
        $response = $this->withToken($this->token($this->owner()))
            ->getJson('/api/owner/spaces/stats');

        $this->assertNotShadowed($response, '/api/owner/spaces/stats');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'stats.spaces')
            ->assertJsonPath('stats.total', 0)
            ->assertJsonPath('stats.by_status.pending', 0)
            ->assertJsonPath('stats.by_status.approved', 0)
            ->assertJsonPath('stats.by_status.rejected', 0)
            ->assertJsonPath('stats.monthly_bookings', 0)
            ->assertJsonPath('stats.truncated', false);
    }

    public function test_spaces_stats_reports_the_monthly_figures_of_each_space(): void
    {
        // Workspace::getStats() computes confirmed bookings, their revenue and
        // occupancy for the current month. The stats endpoint surfaces the same three
        // numbers per space, computed in bulk, so the card and each space's own
        // `stats` block can never disagree.
        $owner = $this->owner();
        $customer = $this->customer();

        $busy = $this->spaceFor($owner, ['status' => 'approved', 'is_active' => true]);
        $busyUnit = $this->unitFor($busy);

        // Two confirmed bookings this month for the busy space, one pending booking
        // that must be ignored, and one confirmed booking for somebody else's space.
        $this->confirmedBooking($customer, $busyUnit, 150);
        $this->confirmedBooking($customer, $busyUnit, 200);

        Booking::create([
            'user_id' => $customer->id,
            'unit_id' => $busyUnit->id,
            'start_datetime' => now(),
            'end_datetime' => now()->addHours(1),
            'status' => 'pending',
            'total_price' => 999,
        ]);

        $quiet = $this->spaceFor($owner, ['status' => 'approved', 'is_active' => true]);
        $quietUnit = $this->unitFor($quiet);
        $this->confirmedBooking($customer, $quietUnit, 50);

        $otherOwner = $this->owner();
        $foreign = $this->spaceFor($otherOwner, ['status' => 'approved', 'is_active' => true]);
        $foreignUnit = $this->unitFor($foreign);
        $this->confirmedBooking($customer, $foreignUnit, 5000);

        $response = $this->withToken($this->token($owner))
            ->getJson('/api/owner/spaces/stats');

        $this->assertNotShadowed($response, '/api/owner/spaces/stats');

        $response->assertStatus(200)
            // The owner's own confirmed revenue this month: 150 + 200 + 50. The
            // pending booking and the other owner's booking are both excluded.
            ->assertJsonPath('stats.monthly_bookings', 3);

        $this->assertEqualsWithDelta(400.0, (float) $response->json('stats.monthly_revenue'), 0.001);

        $rows = collect($response->json('stats.spaces'))->keyBy('space_id');

        // Newest first, so the busy space was created first and therefore comes second;
        // indexing by id rather than position keeps the assertion readable.
        $busyRow = $rows->get($busy->id);

        $this->assertNotNull($busyRow, 'the busy space is missing from the stats rows');
        $this->assertSame(2, $busyRow['stats']['bookings']);
        $this->assertSame(2, $busyRow['stats']['total_bookings']);
        // The same figure under the camelCase spelling getStats() inherited.
        $this->assertSame(2, $busyRow['stats']['totalBookings']);
        $this->assertEqualsWithDelta(350.0, (float) $busyRow['stats']['revenue'], 0.001);
        // One unit, two confirmed bookings against 30 days of capacity.
        $this->assertSame(7, $busyRow['stats']['occupancy']);

        $quietRow = $rows->get($quiet->id);

        $this->assertNotNull($quietRow, 'the quiet space is missing from the stats rows');
        $this->assertSame(1, $quietRow['stats']['bookings']);
        $this->assertEqualsWithDelta(50.0, (float) $quietRow['stats']['revenue'], 0.001);

        // Cross-tenant, on the itemised rows: another owner's space is neither counted
        // nor itemised.
        $this->assertNull($rows->get($foreign->id), 'a foreign space leaked into the stats rows');
    }

    public function test_spaces_stats_does_not_divide_by_zero_for_a_space_without_units(): void
    {
        // Occupancy divides by the number of units, and a space created through the
        // dashboard always has one — but a row inserted directly (an import, an admin
        // fix, a test) may have none. The figure has to be 0, not a division error.
        $owner = $this->owner();
        $this->spaceFor($owner, ['status' => 'approved', 'is_active' => true]);

        $this->withToken($this->token($owner))
            ->getJson('/api/owner/spaces/stats')
            ->assertStatus(200)
            ->assertJsonPath('stats.spaces.0.stats.occupancy', 0)
            ->assertJsonPath('stats.spaces.0.stats.bookings', 0);
    }

    // -------------------------------------------------------------------------
    // GET /api/owner/ads/open
    // -------------------------------------------------------------------------

    public function test_ads_open_lists_only_published_ads_that_have_not_expired(): void
    {
        // Two conditions: the owner published it, and its window has not closed.
        // `schedule` is nullable, so an ad with no expiry at all is running until its
        // owner archives it — the same contract the public feed enforces.
        $owner = $this->owner();

        $noSchedule = $this->publishedAd($owner, ['title' => 'بلا جدولة']);
        $running = $this->publishedAd($owner, [
            'title' => 'إعلان ساري',
            'schedule' => ['expires_at' => now()->addMonth()->toIso8601String()],
        ]);
        $this->publishedAd($owner, [
            'title' => 'إعلان منتهٍ',
            'schedule' => ['expires_at' => now()->subDay()->toIso8601String()],
        ]);
        $this->adFor($owner, ['title' => 'مسودة']);
        $this->adFor($owner, ['title' => 'مؤرشف', 'status' => 'archived']);

        $response = $this->withToken($this->token($owner))
            ->getJson('/api/owner/ads/open');

        $this->assertNotShadowed($response, '/api/owner/ads/open');

        $response->assertStatus(200)->assertJsonCount(2, 'ads');

        $ids = array_column($response->json('ads'), 'ad_id');

        $this->assertContains($noSchedule->id, $ids);
        $this->assertContains($running->id, $ids);

        // The expiry is echoed back so the owner can see when a campaign ends, and it
        // is null for the ad that has none.
        $byId = collect($response->json('ads'))->keyBy('ad_id');

        $this->assertNull($byId->get($noSchedule->id)['expires_at']);
        $this->assertNotNull($byId->get($running->id)['expires_at']);
    }

    public function test_a_json_encoded_schedule_still_expires_in_the_owner_endpoints(): void
    {
        // OwnerAdController validates `schedule` with the `json` rule, which only
        // accepts a string, so it hands Ad::create() an already-encoded JSON string,
        // the `json` cast encodes it a second time, and the column stores a JSON
        // string scalar rather than an object. Reading only an array would leave every
        // campaign created through the dashboard live forever.
        $owner = $this->owner();

        $expired = $this->publishedAd($owner, [
            'schedule' => json_encode(['expires_at' => now()->subDay()->toIso8601String()]),
        ]);
        $stillRunning = $this->publishedAd($owner, [
            'schedule' => json_encode(['expires_at' => now()->addMonth()->toIso8601String()]),
        ]);

        // Pins the premise: the value really does come back as a string.
        $this->assertIsString($expired->fresh()->schedule);

        $open = $this->withToken($this->token($owner))->getJson('/api/owner/ads/open');

        $this->assertNotShadowed($open, '/api/owner/ads/open');

        $open->assertStatus(200)
            ->assertJsonCount(1, 'ads')
            ->assertJsonPath('ads.0.ad_id', $stillRunning->id);

        // The historical record keeps both.
        $this->withToken($this->token($owner))
            ->getJson('/api/owner/ads/published')
            ->assertStatus(200)
            ->assertJsonCount(2, 'ads');
    }

    public function test_an_unreadable_expiry_does_not_hide_an_ad_from_its_owner(): void
    {
        // The owner dashboard validates `schedule` with the `json` rule only, so it
        // accepts whatever JSON the client sends. An expiry that cannot be read must
        // degrade to "no expiry" instead of taking the ad off the list — or fataling
        // the endpoint.
        $owner = $this->owner();
        $ad = $this->publishedAd($owner, [
            'schedule' => ['expires_at' => '__garbage__'],
        ]);

        $this->withToken($this->token($owner))
            ->getJson('/api/owner/ads/open')
            ->assertStatus(200)
            ->assertJsonCount(1, 'ads')
            ->assertJsonPath('ads.0.ad_id', $ad->id)
            ->assertJsonPath('ads.0.expires_at', null);
    }

    public function test_every_supported_expiry_spelling_is_honoured_by_the_owner_endpoints(): void
    {
        // ads has no expires_at column, so the expiry is derived from the schedule
        // blob. Each accepted spelling has to expire the ad here too, otherwise an
        // owner dashboard and the public feed would disagree about the same campaign.
        $owner = $this->owner();
        $token = $this->token($owner);

        foreach (['expires_at', 'expiresAt', 'end_at', 'endAt', 'expires', 'until'] as $key) {
            $this->publishedAd($owner, [
                'title' => 'إعلان ' . $key,
                'schedule' => [$key => now()->subHour()->toIso8601String()],
            ]);

            $response = $this->withToken($token)->getJson('/api/owner/ads/open');

            $this->assertNotShadowed($response, '/api/owner/ads/open');

            $response->assertStatus(200)->assertJsonCount(0, 'ads');
        }
    }

    // -------------------------------------------------------------------------
    // GET /api/owner/ads/published
    // -------------------------------------------------------------------------

    public function test_ads_published_is_a_superset_of_ads_open(): void
    {
        // published() is the historical record and open() is what is running right
        // now, so open() must be a strict SUBSET: everything running is in the
        // record, and a campaign that has since ended is in the record only. If this
        // ever fails, the owner has lost the history of what they ran.
        $owner = $this->owner();

        $running = $this->publishedAd($owner, ['title' => 'إعلان ساري']);
        $expired = $this->publishedAd($owner, [
            'title' => 'إعلان منتهٍ',
            'schedule' => ['expires_at' => now()->subDay()->toIso8601String()],
        ]);
        $this->adFor($owner, ['title' => 'مسودة']);

        $token = $this->token($owner);

        $open = $this->withToken($token)->getJson('/api/owner/ads/open');
        $this->assertNotShadowed($open, '/api/owner/ads/open');
        $open->assertStatus(200)->assertJsonCount(1, 'ads');

        $published = $this->withToken($token)->getJson('/api/owner/ads/published');
        $this->assertNotShadowed($published, '/api/owner/ads/published');
        $published->assertStatus(200)->assertJsonCount(2, 'ads');

        $openIds = array_column($open->json('ads'), 'ad_id');
        $publishedIds = array_column($published->json('ads'), 'ad_id');

        $this->assertContains($running->id, $openIds);
        $this->assertContains($running->id, $publishedIds);

        // The expired campaign: in the record, off the air.
        $this->assertContains($expired->id, $publishedIds);
        $this->assertNotContains($expired->id, $openIds);

        foreach ($openIds as $id) {
            $this->assertContains($id, $publishedIds, 'ads/open returned an ad that ads/published drops');
        }
    }

    public function test_ads_published_keeps_an_ad_the_owner_has_archived(): void
    {
        // Deliberate: published() filters on the status, not on the audit trail of
        // whether the ad was once live. An archived campaign has been retired by the
        // owner, and /api/owner/ads remains the place to see every row regardless of
        // state. This test exists so that a future change to the filter is a
        // deliberate, visible decision rather than a silent one.
        $owner = $this->owner();
        $ad = $this->publishedAd($owner, ['sent_at' => now()->subMonth()]);
        $ad->update(['status' => 'archived']);

        $this->withToken($this->token($owner))
            ->getJson('/api/owner/ads/published')
            ->assertStatus(200)
            ->assertJsonCount(0, 'ads');

        $this->withToken($this->token($owner))
            ->getJson('/api/owner/ads')
            ->assertStatus(200)
            ->assertJsonCount(1, 'ads');
    }

    // -------------------------------------------------------------------------
    // GET /api/owner/ads/stats
    // -------------------------------------------------------------------------

    public function test_ads_stats_counts_and_sums_the_impressions_of_the_owner(): void
    {
        // draft / published / archived are the three values the column's enum allows,
        // and the impressions figure is the SUM over the owner's own ads — including
        // the archived ones, because the total is about reach, not about live state.
        $owner = $this->owner();

        $this->adFor($owner, ['status' => 'draft', 'impressions' => 10]);
        $this->publishedAd($owner, ['impressions' => 25]);
        $this->adFor($owner, ['status' => 'archived', 'impressions' => 5]);

        $response = $this->withToken($this->token($owner))
            ->getJson('/api/owner/ads/stats');

        $this->assertNotShadowed($response, '/api/owner/ads/stats');

        $response->assertStatus(200)
            ->assertJsonPath('stats.total', 3)
            ->assertJsonPath('stats.total_ads', 3)
            ->assertJsonPath('stats.draft', 1)
            ->assertJsonPath('stats.published', 1)
            ->assertJsonPath('stats.archived', 1)
            ->assertJsonPath('stats.by_status.draft', 1)
            ->assertJsonPath('stats.by_status.published', 1)
            ->assertJsonPath('stats.by_status.archived', 1)
            // The length of /api/owner/ads/open.
            ->assertJsonPath('stats.running', 1)
            ->assertJsonPath('stats.impressions', 40)
            ->assertJsonPath('stats.total_impressions', 40);

        $this->assertDatabaseCount('ads', 3);
    }

    public function test_ads_stats_reports_zeroes_for_an_owner_with_no_ads(): void
    {
        $response = $this->withToken($this->token($this->owner()))
            ->getJson('/api/owner/ads/stats');

        $this->assertNotShadowed($response, '/api/owner/ads/stats');

        $response->assertStatus(200)
            ->assertJsonPath('stats.total', 0)
            ->assertJsonPath('stats.by_status.draft', 0)
            ->assertJsonPath('stats.by_status.published', 0)
            ->assertJsonPath('stats.by_status.archived', 0)
            ->assertJsonPath('stats.running', 0)
            ->assertJsonPath('stats.impressions', 0);
    }

    public function test_ads_stats_running_agrees_with_the_open_list(): void
    {
        // The counter is derived from the same bounded scan and the same expiry helper
        // the list uses, so the two can never disagree. One ad that has expired is the
        // only way they could.
        $owner = $this->owner();

        $this->publishedAd($owner);
        $this->publishedAd($owner);
        $this->publishedAd($owner, [
            'schedule' => ['expires_at' => now()->subHour()->toIso8601String()],
        ]);

        $token = $this->token($owner);

        $open = $this->withToken($token)->getJson('/api/owner/ads/open');
        $this->assertNotShadowed($open, '/api/owner/ads/open');

        $stats = $this->withToken($token)->getJson('/api/owner/ads/stats');
        $this->assertNotShadowed($stats, '/api/owner/ads/stats');

        $stats->assertStatus(200)->assertJsonPath('stats.published', 3);

        $this->assertSame(
            $open->assertStatus(200)->json('ads') === null ? 0 : count($open->json('ads')),
            $stats->json('stats.running'),
            'stats.running and the length of ads/open disagree'
        );
    }

    // -------------------------------------------------------------------------
    // Isolation — the highest-value assertions in this file
    // -------------------------------------------------------------------------

    public function test_open_and_stats_never_expose_another_owners_spaces(): void
    {
        // Cross-tenant leakage is the serious failure mode here. Both owners have an
        // approved, active space, so a missing owner_id scope would still produce the
        // right COUNT in a single-owner test and the wrong list here.
        $owner = $this->owner();
        $otherOwner = $this->owner();

        $mine = $this->spaceFor($owner, [
            'title' => 'مساحتي',
            'status' => 'approved',
            'is_active' => true,
        ]);
        $theirs = $this->spaceFor($otherOwner, [
            'title' => 'مساحتهم',
            'status' => 'approved',
            'is_active' => true,
        ]);

        $token = $this->token($owner);

        $open = $this->withToken($token)->getJson('/api/owner/spaces/open');
        $this->assertNotShadowed($open, '/api/owner/spaces/open');
        $open->assertStatus(200)->assertJsonCount(1, 'spaces');

        $openIds = array_column($open->json('spaces'), 'id');

        $this->assertContains($mine->id, $openIds);
        $this->assertNotContains($theirs->id, $openIds);

        $stats = $this->withToken($token)->getJson('/api/owner/spaces/stats');
        $this->assertNotShadowed($stats, '/api/owner/spaces/stats');
        $stats->assertStatus(200)
            ->assertJsonPath('stats.total', 1)
            ->assertJsonPath('stats.approved', 1)
            ->assertJsonPath('stats.active', 1)
            ->assertJsonPath('stats.live', 1)
            ->assertJsonCount(1, 'stats.spaces');

        $this->assertNotContains(
            $theirs->id,
            array_column($stats->json('stats.spaces'), 'space_id')
        );
    }

    public function test_open_published_and_stats_never_expose_another_owners_ads(): void
    {
        // The same isolation requirement for ads, on all three endpoints: an owner's
        // impressions and campaign history are commercial data, and a missing
        // user_id scope would disclose them wholesale.
        $owner = $this->owner();
        $otherOwner = $this->owner();

        $mine = $this->publishedAd($owner, ['title' => 'إعلاني', 'impressions' => 100]);
        $theirs = $this->publishedAd($otherOwner, ['title' => 'إعلانهم', 'impressions' => 900]);

        $token = $this->token($owner);

        foreach (['/api/owner/ads/open', '/api/owner/ads/published'] as $path) {
            $response = $this->withToken($token)->getJson($path);

            $this->assertNotShadowed($response, $path);

            $response->assertStatus(200)->assertJsonCount(1, 'ads');

            $ids = array_column($response->json('ads'), 'ad_id');

            $this->assertContains($mine->id, $ids);
            $this->assertNotContains($theirs->id, $ids);
        }

        $stats = $this->withToken($token)->getJson('/api/owner/ads/stats');

        $this->assertNotShadowed($stats, '/api/owner/ads/stats');

        $stats->assertStatus(200)
            ->assertJsonPath('stats.total', 1)
            ->assertJsonPath('stats.published', 1)
            ->assertJsonPath('stats.running', 1)
            // Theirs would make this 1000.
            ->assertJsonPath('stats.impressions', 100);
    }
}
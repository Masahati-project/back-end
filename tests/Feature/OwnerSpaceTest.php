<?php

namespace Tests\Feature;

use App\Models\Pricing;
use App\Models\Unit;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OwnerSpaceTest extends TestCase
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

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'مساحة الاختبار',
            'description' => 'وصف مختصر للمساحة',
            'location' => 'الرياض',
            'lat' => 24.7136,
            'lng' => 46.6753,
            'price_per_hour' => 50,
            'capacity' => 4,
            'amenities' => ['wifi', 'power'],
            'internet' => true,
            'power' => true,
            'open_time' => '08:00',
            'close_time' => '22:00',
            'contact_phone' => '0500000000',
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // Schema
    // -------------------------------------------------------------------------

    public function test_space_document_url_column_is_nullable(): void
    {
        // The production 500 came from a NOT NULL column with no default, which
        // MySQL strict mode rejects with error 1364.
        foreach (Schema::getColumns('workspaces') as $column) {
            if ($column['name'] === 'space_document_url') {
                $this->assertTrue((bool) $column['nullable'], 'space_document_url must be nullable');
                return;
            }
        }

        $this->fail('workspaces.space_document_url column is missing');
    }

    // -------------------------------------------------------------------------
    // POST /api/owner/spaces
    // -------------------------------------------------------------------------

    public function test_owner_can_create_a_space(): void
    {
        $owner = $this->owner();

        $response = $this->withToken($this->token($owner))
            ->postJson('/api/owner/spaces', $this->payload());

        $response->assertStatus(201);

        $space = Workspace::firstWhere('title', 'مساحة الاختبار');
        $this->assertNotNull($space, 'the workspace was not persisted');

        // open_time / close_time are asserted on the model, not on the payload:
        // the insert used to carry a typo'd "opne_time" key, which silently left
        // the column empty and made MySQL reject the whole row.
        $this->assertSame('08:00', $this->normalizeTime($space->open_time));
        $this->assertSame('22:00', $this->normalizeTime($space->close_time));
        $this->assertSame($owner->id, $space->owner_id);
        $this->assertSame('الرياض', $space->location);
        $this->assertEqualsWithDelta(24.7136, (float) $space->latitude, 0.0001);
        $this->assertEqualsWithDelta(46.6753, (float) $space->longitude, 0.0001);
        $this->assertSame('0500000000', $space->contact_phone);
        $this->assertSame('pending', $space->status);
        $this->assertFalse((bool) $space->is_closed);

        // Assert the raw stored values too, so a silently dropped column cannot
        // hide behind Eloquent's cast layer.
        $raw = DB::table('workspaces')->where('id', $space->id)->first();
        $this->assertSame('08:00', $this->normalizeTime($raw->open_time));
        $this->assertSame('22:00', $this->normalizeTime($raw->close_time));
        $this->assertSame('0500000000', $raw->contact_phone);
    }

    public function test_owner_can_create_a_space_without_any_document(): void
    {
        // Regression: space_document_url was NOT NULL with no default, so a
        // space created before uploading the lease contract 500'd.
        $owner = $this->owner();

        $response = $this->withToken($this->token($owner))
            ->postJson('/api/owner/spaces', $this->payload());

        $response->assertStatus(201);

        $space = Workspace::firstWhere('title', 'مساحة الاختبار');
        $this->assertNotNull($space);
        $this->assertNull($space->space_document_url);
        $this->assertDatabaseHas('workspaces', [
            'id' => $space->id,
            'space_document_url' => null,
        ]);
    }

    public function test_optional_space_document_url_is_persisted_when_supplied(): void
    {
        $owner = $this->owner();

        $response = $this->withToken($this->token($owner))
            ->postJson('/api/owner/spaces', $this->payload([
                'space_document_url' => 'documents/lease.pdf',
            ]));

        $response->assertStatus(201);

        $space = Workspace::firstWhere('title', 'مساحة الاختبار');
        $this->assertNotNull($space);
        $this->assertSame('documents/lease.pdf', $space->space_document_url);
    }

    public function test_create_response_keeps_its_contract(): void
    {
        $owner = $this->owner();

        $response = $this->withToken($this->token($owner))
            ->postJson('/api/owner/spaces', $this->payload());

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'space' => [
                    'id',
                    'price_per_hour',
                    'capacity',
                    'amenities',
                    'status',
                ],
            ]);

        $space = Workspace::firstWhere('title', 'مساحة الاختبار');
        $this->assertNotNull($space);

        $response->assertJsonPath('space.id', $space->id)
            ->assertJsonPath('space.capacity', 4)
            ->assertJsonPath('space.status', 'pending');

        // Pricing casts price as decimal:2, so it comes back as "50.00".
        $this->assertEqualsWithDelta(
            50.0,
            (float) $response->json('space.price_per_hour'),
            0.001
        );

        $this->assertEqualsCanonicalizing(['wifi', 'power'], $response->json('space.amenities'));
    }

    public function test_create_persists_a_unit_with_hourly_pricing(): void
    {
        $owner = $this->owner();

        $this->withToken($this->token($owner))
            ->postJson('/api/owner/spaces', $this->payload())
            ->assertStatus(201);

        $space = Workspace::firstWhere('title', 'مساحة الاختبار');
        $this->assertNotNull($space);

        $unit = Unit::where('workspace_id', $space->id)->first();
        $this->assertNotNull($unit, 'the default unit was not created');
        $this->assertSame(4, $unit->capacity);
        $this->assertTrue((bool) $unit->has_wifi);
        $this->assertTrue((bool) $unit->has_power);

        $pricing = Pricing::where('unit_id', $unit->id)
            ->where('price_type', 'hourly')
            ->first();
        $this->assertNotNull($pricing, 'the hourly pricing was not created');
        $this->assertEqualsWithDelta(50, (float) $pricing->price, 0.001);
    }

    public function test_create_still_validates_required_fields(): void
    {
        $owner = $this->owner();

        // Dropping open_time must stay a 422, not a 500.
        $payload = $this->payload();
        unset($payload['open_time']);

        $this->withToken($this->token($owner))
            ->postJson('/api/owner/spaces', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['open_time']);

        $this->assertDatabaseCount('workspaces', 0);
    }

    public function test_non_owner_cannot_create_a_space(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $this->withToken($this->token($customer))
            ->postJson('/api/owner/spaces', $this->payload())
            ->assertStatus(403);

        $this->assertDatabaseCount('workspaces', 0);
    }

    // -------------------------------------------------------------------------
    // PUT /api/owner/spaces/{id} — shares the amenity resolution helper
    // -------------------------------------------------------------------------

    public function test_owner_can_update_a_space_and_sync_new_amenities(): void
    {
        $owner = $this->owner();

        $space = Workspace::create([
            'owner_id' => $owner->id,
            'title' => 'مساحة أولية',
            'location' => 'الرياض',
            'latitude' => 24.7136,
            'longitude' => 46.6753,
            'contact_phone' => '0500000000',
            'status' => 'pending',
            'open_time' => '08:00',
            'close_time' => '22:00',
            'is_closed' => false,
        ]);

        $response = $this->withToken($this->token($owner))
            ->putJson("/api/owner/spaces/{$space->id}", [
                'title' => 'مساحة معدّلة',
                'amenities' => ['monitor', 'air_conditioning'],
            ]);

        $response->assertStatus(200)->assertJsonStructure(['message', 'space']);

        $space->refresh();
        $this->assertSame('مساحة معدّلة', $space->title);
        $this->assertEqualsCanonicalizing(
            ['air_conditioning', 'monitor'],
            $space->amenities()->pluck('name')->toArray()
        );
    }

    /**
     * A `time` column reads back as "08:00:00" on MySQL and "08:00:00" on
     * SQLite's newer drivers, but some builds return "08:00". Compare on the
     * hour:minute prefix so the assertion is not driver-specific.
     */
    private function normalizeTime($value): string
    {
        return substr((string) $value, 0, 5);
    }
}
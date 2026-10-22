<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Pricing;
use App\Models\Unit;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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

    /**
     * POST /api/owner/spaces the way a client has to send it.
     *
     * `docs` is required, so the call is always a multipart one and a plain
     * postJson cannot express it — an UploadedFile in a JSON body reaches the
     * endpoint as an ordinary string. The Cloudinary disk is faked so that a real
     * upload is never attempted.
     *
     * @param  array<string, mixed>|null  $files
     */
    private function createSpace(User $owner, array $overrides = [], ?array $files = null)
    {
        Storage::fake('cloudinary');

        return $this->withToken($this->token($owner))
            ->post('/api/owner/spaces', array_merge(
                $this->payload($overrides),
                $files ?? ['docs' => UploadedFile::fake()->create('lease.pdf', 120, 'application/pdf')],
            ));
    }

    public function test_space_document_url_column_is_nullable(): void
    {
        foreach (Schema::getColumns('workspaces') as $column) {
            if ($column['name'] === 'space_document_url') {
                $this->assertTrue((bool) $column['nullable'], 'space_document_url must be nullable');
                return;
            }
        }

        $this->fail('workspaces.space_document_url column is missing');
    }

    public function test_owner_can_create_a_space(): void
    {
        $owner = $this->owner();

        $response = $this->createSpace($owner);

        $response->assertStatus(201);

        $space = Workspace::firstWhere('title', 'مساحة الاختبار');
        $this->assertNotNull($space, 'the workspace was not persisted');

        $this->assertSame('08:00', $this->normalizeTime($space->open_time));
        $this->assertSame('22:00', $this->normalizeTime($space->close_time));
        $this->assertSame($owner->id, $space->owner_id);
        $this->assertSame('الرياض', $space->location);
        $this->assertEqualsWithDelta(24.7136, (float) $space->latitude, 0.0001);
        $this->assertEqualsWithDelta(46.6753, (float) $space->longitude, 0.0001);
        $this->assertSame('0500000000', $space->contact_phone);
        $this->assertSame('pending', $space->status);
        $this->assertFalse((bool) $space->is_closed);

        $raw = DB::table('workspaces')->where('id', $space->id)->first();
        $this->assertSame('08:00', $this->normalizeTime($raw->open_time));
        $this->assertSame('22:00', $this->normalizeTime($raw->close_time));
        $this->assertSame('0500000000', $raw->contact_phone);
    }

    public function test_a_space_cannot_be_created_without_its_document(): void
    {
        $owner = $this->owner();

        $this->createSpace($owner, [], [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['docs']);

        $this->assertDatabaseCount('workspaces', 0);
    }

    public function test_the_uploaded_document_is_stored_on_the_listing(): void
    {
        $owner = $this->owner();

        $this->createSpace($owner)->assertStatus(201);

        $space = Workspace::firstWhere('title', 'مساحة الاختبار');
        $this->assertNotNull($space);
        $this->assertNotNull($space->space_document_url, 'the uploaded document was dropped');

        Storage::disk('cloudinary')->assertExists($space->space_document_url);

        // Like the image fields, the response carries a delivery URL and not
        // the disk-relative path the column stores. PDFs are Cloudinary `raw`
        // assets, so they do not live under `image/upload`.
        config(['filesystems.disks.cloudinary.cloud' => 'test-cloud']);

        $this->assertSame(
            'https://res.cloudinary.com/test-cloud/raw/upload/'.$space->space_document_url,
            $this->withToken($this->token($owner))
                ->getJson('/api/owner/spaces')
                ->json('spaces.0.space_document_url')
        );
    }

    public function test_a_document_of_an_unaccepted_type_is_rejected(): void
    {
        $owner = $this->owner();

        $this->createSpace($owner, [], [
            'docs' => UploadedFile::fake()->create('lease.txt', 120, 'text/plain'),
        ])->assertStatus(422)->assertJsonValidationErrors(['docs']);

        $this->assertDatabaseCount('workspaces', 0);
    }

    public function test_create_response_keeps_its_contract(): void
    {
        $owner = $this->owner();

        $response = $this->createSpace($owner);

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

        $this->createSpace($owner)->assertStatus(201);

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

    public function test_owner_needs_no_admin_approval_to_create_a_space(): void
    {
        $owner = $this->owner();

        $this->assertNull(Document::where('user_id', $owner->id)->first());

        $response = $this->createSpace($owner);

        $response->assertStatus(201)->assertJsonPath('space.status', 'pending');

        $space = Workspace::firstWhere('title', 'مساحة الاختبار');
        $this->assertNotNull($space);

        $this->assertSame('pending', $space->status);
        $this->assertFalse((bool) $space->is_active);
    }

    public function test_multipart_string_flags_and_json_amenities_are_accepted(): void
    {
        $owner = $this->owner();

        $this->createSpace($owner, [
            'internet' => 'true',
            'power' => 'false',
            'amenities' => json_encode(['wifi', 'power']),
        ])->assertStatus(201);

        $space = Workspace::firstWhere('title', 'مساحة الاختبار');
        $this->assertNotNull($space);

        $unit = Unit::where('workspace_id', $space->id)->first();
        $this->assertNotNull($unit);
        $this->assertTrue((bool) $unit->has_wifi);
        $this->assertFalse((bool) $unit->has_power);
        $this->assertEqualsCanonicalizing(
            ['wifi', 'power'],
            $space->amenities()->pluck('name')->toArray()
        );
    }

    public function test_create_still_validates_required_fields(): void
    {
        $owner = $this->owner();

        $this->createSpace($owner, ['open_time' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['open_time']);

        $this->assertDatabaseCount('workspaces', 0);
    }

    public function test_non_owner_cannot_create_a_space(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $this->createSpace($customer)->assertStatus(403);

        $this->assertDatabaseCount('workspaces', 0);
    }

    public function test_an_owner_can_create_at_most_two_spaces_a_minute(): void
    {
        $owner = $this->owner();

        $this->createSpace($owner)
            ->assertStatus(201)
            ->assertHeader('X-RateLimit-Remaining', '1');

        $this->createSpace($owner)
            ->assertStatus(201)
            ->assertHeader('X-RateLimit-Remaining', '0');

        $this->createSpace($owner)
            ->assertStatus(429)
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('Retry-After');

        $this->assertDatabaseCount('workspaces', 2);
    }

    public function test_a_rejected_submission_does_not_spend_the_quota(): void
    {
        $owner = $this->owner();

        $this->createSpace($owner, ['open_time' => null])->assertStatus(422);
        $this->createSpace($owner, ['contact_phone' => null])->assertStatus(422);
        $this->createSpace($owner, [], [])->assertStatus(422);

        $this->createSpace($owner)->assertStatus(201);
        $this->createSpace($owner)->assertStatus(201);
        $this->createSpace($owner)->assertStatus(429);

        $this->assertDatabaseCount('workspaces', 2);
    }

    public function test_the_quota_is_per_owner_and_not_per_address(): void
    {
        $first = $this->owner();
        $second = $this->owner();

        $this->createSpace($first)->assertStatus(201);
        $this->createSpace($first)->assertStatus(201);
        $this->createSpace($first)->assertStatus(429);

        Auth::forgetGuards();

        $this->createSpace($second)->assertStatus(201);

        $this->assertDatabaseCount('workspaces', 3);
    }

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
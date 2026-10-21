<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\Pricing;
use App\Models\Unit;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The edit-space contract the frontend's requirements doc describes, one item
 * per group:
 *
 *  - Item 1: open_time / close_time / contact_phone are persisted on edit.
 *  - Item 2: the image field is a full string URL, never the upload boolean.
 *  - Item 3: PATCH /api/owner/spaces/{id} edits only the fields it is sent.
 *  - Item 4: POST /api/owner/spaces/{id} with `_method=PATCH` accepts a real
 *    image file and answers with the new URL.
 *
 * The cloudinary disk is faked in every upload test, and the cloud name is
 * pinned so the URL builder can assemble absolute delivery URLs.
 */
class OwnerSpaceEditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A 1x1 PNG, large enough for storeBase64Image's decode path and far below
     * the five-megabyte cap.
     */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.cloudinary.cloud' => 'test-cloud']);
    }

    private function token(User $user): string
    {
        return $user->createToken('auth_token')->plainTextToken;
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'space_owner']);
    }

    /**
     * There is no WorkspaceFactory, so spaces are built inline — see
     * OwnerRouteShadowingTest for the same reasoning about NOT NULL columns.
     */
    private function spaceFor(User $owner, array $overrides = []): Workspace
    {
        return Workspace::create(array_merge([
            'owner_id' => $owner->id,
            'title' => 'مساحة الاختبار',
            'space_document_url' => 'documents/space.pdf',
            'description' => 'الوصف الأولي',
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

    private function unitFor(Workspace $space, array $overrides = []): Unit
    {
        return Unit::create(array_merge([
            'workspace_id' => $space->id,
            'type' => 'full_space',
            'capacity' => '4',
            'has_wifi' => true,
            'has_power' => true,
            'status' => 'available',
        ], $overrides));
    }

    private function withAmenities(Workspace $space, array $names): array
    {
        $ids = [];

        foreach ($names as $name) {
            $ids[] = Amenity::firstOrCreate(['name' => $name], ['icon' => 'check'])->id;
        }

        $space->amenities()->sync($ids);

        return $ids;
    }

    private function pricingFor(Unit $unit, float $price): Pricing
    {
        return Pricing::create([
            'unit_id' => $unit->id,
            'price_type' => 'hourly',
            'price' => $price,
            'currency' => 'ILS',
        ]);
    }

    /**
     * The raw value of the space's first stored image row — what the DB
     * actually holds, before any URL building.
     */
    private function storedImageValue(int $workspaceId): ?string
    {
        return WorkspaceImage::where('workspace_id', $workspaceId)
            ->orderBy('id')
            ->value('image_url');
    }

    private function storedImageValues(int $workspaceId): array
    {
        return WorkspaceImage::where('workspace_id', $workspaceId)
            ->orderBy('id')
            ->pluck('image_url')
            ->all();
    }

    private function createStoredImage(int $workspaceId, string $url): void
    {
        WorkspaceImage::create([
            'workspace_id' => $workspaceId,
            'image_url' => $url,
        ]);
    }

    // ------------------------------------------------------------------
    // Item 3 + Item 1: partial edits
    // ------------------------------------------------------------------

    public function test_patch_updates_only_the_fields_it_was_sent(): void
    {
        $owner = $this->owner();
        $space = $this->spaceFor($owner);
        $this->unitFor($space);
        $this->withAmenities($space, ['wifi']);
        $this->pricingFor($space->units()->first(), 25);

        $response = $this->withToken($this->token($owner))
            ->patchJson("/api/owner/spaces/{$space->id}", [
                'open_time' => '09:00',
                'close_time' => '18:00',
                'contact_phone' => '0599123456',
            ]);

        $response->assertStatus(200);

        $space->refresh();

        // Sent fields are persisted…
        $this->assertSame('09:00', $this->normalizeTime($space->open_time));
        $this->assertSame('18:00', $this->normalizeTime($space->close_time));
        $this->assertSame('0599123456', $space->contact_phone);

        // …and nothing else moved.
        $this->assertSame('مساحة الاختبار', $space->title);
        $this->assertSame('الوصف الأولي', $space->description);
        $this->assertSame('الرياض', $space->location);
        $this->assertEqualsWithDelta(24.7136, (float) $space->latitude, 0.0001);
        $this->assertEqualsWithDelta(46.6753, (float) $space->longitude, 0.0001);
        $this->assertEqualsCanonicalizing(['wifi'], $space->amenities()->pluck('name')->toArray());

        $response->assertJsonPath('space.open_time', '09:00')
            ->assertJsonPath('space.close_time', '18:00')
            ->assertJsonPath('space.contact_phone', '0599123456')
            ->assertJsonPath('space.title', 'مساحة الاختبار');
    }

    public function test_patch_can_change_a_single_price(): void
    {
        $owner = $this->owner();
        $space = $this->spaceFor($owner);
        $unit = $this->unitFor($space);
        $this->pricingFor($unit, 25);

        $response = $this->withToken($this->token($owner))
            ->patchJson("/api/owner/spaces/{$space->id}", ['price_per_hour' => 30]);

        $response->assertStatus(200)
            ->assertJsonPath('space.price_per_hour', '30.00');

        $this->assertEqualsWithDelta(30, (float) Pricing::where('unit_id', $unit->id)->where('price_type', 'hourly')->first()->price, 0.001);

        // The workspace table itself was never touched.
        $fresh = $space->refresh();
        $this->assertSame('08:00', $this->normalizeTime($fresh->open_time));
        $this->assertSame('0500000000', $fresh->contact_phone);
    }

    public function test_put_with_the_full_object_still_updates_hours_and_phone(): void
    {
        // The frontend falls back to PUT with the full object when PATCH is
        // unavailable; history showed a false "saved" there. Now it persists.
        $owner = $this->owner();
        $space = $this->spaceFor($owner);
        $this->unitFor($space);

        $response = $this->withToken($this->token($owner))
            ->putJson("/api/owner/spaces/{$space->id}", [
                'title' => 'التحديث الشامل',
                'description' => 'وصف جديد',
                'location' => 'جدة',
                'lat' => 21.5,
                'lng' => 39.2,
                'price_per_hour' => 40,
                'capacity' => 12,
                'amenities' => ['internet'],
                'internet' => false,
                'power' => true,
                'open_time' => '10:00',
                'close_time' => '20:00',
                'contact_phone' => '0598888888',
            ]);

        $response->assertStatus(200);

        $space->refresh();
        $unit = Unit::where('workspace_id', $space->id)->first();

        $this->assertSame('التحديث الشامل', $space->title);
        $this->assertSame('جدة', $space->location);
        $this->assertEqualsWithDelta(21.5, (float) $space->latitude, 0.0001);
        $this->assertEqualsWithDelta(39.2, (float) $space->longitude, 0.0001);
        $this->assertSame('10:00', $this->normalizeTime($space->open_time));
        $this->assertSame('20:00', $this->normalizeTime($space->close_time));
        $this->assertSame('0598888888', $space->contact_phone);
        $this->assertSame(12, $unit->capacity);
        $this->assertFalse((bool) $unit->has_wifi);
        $this->assertTrue((bool) $unit->has_power);
        $this->assertEqualsWithDelta(40, (float) Pricing::where('unit_id', $unit->id)->where('price_type', 'hourly')->first()->price, 0.001);
        $this->assertEqualsCanonicalizing(['internet'], $space->amenities()->pluck('name')->toArray());
    }

    public function test_an_empty_patch_is_a_successful_no_op(): void
    {
        $owner = $this->owner();
        $space = $this->spaceFor($owner);

        $this->withToken($this->token($owner))
            ->patchJson("/api/owner/spaces/{$space->id}", [])
            ->assertStatus(200)
            ->assertJsonPath('space.id', $space->id);

        $space->refresh();
        $this->assertSame('مساحة الاختبار', $space->title);
        $this->assertSame('08:00', $this->normalizeTime($space->open_time));
    }

    // ------------------------------------------------------------------
    // Item 3: out-of-contract fields fail loudly, cleared values clear
    // ------------------------------------------------------------------

    public function test_edit_rejects_admin_owned_fields_with_422(): void
    {
        $owner = $this->owner();
        $space = $this->spaceFor($owner);

        $response = $this->withToken($this->token($owner))
            ->patchJson("/api/owner/spaces/{$space->id}", ['status' => 'active']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);

        $space->refresh();
        $this->assertSame('pending', $space->status, 'the status must survive a rejected edit');
    }

    public function test_edit_rejects_unknown_fields_with_422_instead_of_silently_ignoring_them(): void
    {
        $owner = $this->owner();
        $space = $this->spaceFor($owner);

        $this->withToken($this->token($owner))
            ->patchJson("/api/owner/spaces/{$space->id}", [
                'title' => 'عنوان جديد',
                'banana' => 'قيمة',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['banana']);

        $space->refresh();
        $this->assertSame('مساحة الاختبار', $space->title, 'a rejected PATCH must not half-apply');
    }

    public function test_a_foreign_space_cannot_be_edited(): void
    {
        $owner = $this->owner();
        $foreign = $this->spaceFor($this->owner());

        $this->withToken($this->token($owner))
            ->patchJson("/api/owner/spaces/{$foreign->id}", ['title' => 'محاولة'])
            ->assertStatus(403);

        $foreign->refresh();
        $this->assertSame('مساحة الاختبار', $foreign->title);
    }

    public function test_close_time_must_be_after_open_time(): void
    {
        $owner = $this->owner();
        $space = $this->spaceFor($owner);

        // Both sent, and in the wrong order…
        $this->withToken($this->token($owner))
            ->patchJson("/api/owner/spaces/{$space->id}", [
                'open_time' => '20:00',
                'close_time' => '18:00',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['close_time']);

        // …and a close-only edit checked against the stored open time.
        $space->update(['open_time' => '20:00']);

        $this->withToken($this->token($owner))
            ->patchJson("/api/owner/spaces/{$space->id}", ['close_time' => '09:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['close_time']);
    }

    public function test_times_outside_the_h_h_mm_contract_are_refused(): void
    {
        $owner = $this->owner();
        $space = $this->spaceFor($owner);

        $this->withToken($this->token($owner))
            ->patchJson("/api/owner/spaces/{$space->id}", ['open_time' => '25:99'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['open_time']);

        $this->withToken($this->token($owner))
            ->patchJson("/api/owner/spaces/{$space->id}", ['close_time' => 'not-a-clock'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['close_time']);

        $space->refresh();
        $this->assertSame('08:00', $this->normalizeTime($space->open_time), 'a refused time must not be stored');
    }

    public function test_numeric_and_boolean_edges_are_validated(): void
    {
        $owner = $this->owner();
        $space = $this->spaceFor($owner);
        $this->unitFor($space);

        $this->withToken($this->token($owner))
            ->patchJson("/api/owner/spaces/{$space->id}", ['lat' => 95])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lat']);

        $this->withToken($this->token($owner))
            ->patchJson("/api/owner/spaces/{$space->id}", ['lng' => -200])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lng']);

        $this->withToken($this->token($owner))
            ->patchJson("/api/owner/spaces/{$space->id}", ['capacity' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['capacity']);
    }

    public function test_explicit_null_clears_and_empty_array_clears_amenities(): void
    {
        $owner = $this->owner();
        $space = $this->spaceFor($owner);
        $unit = $this->unitFor($space);
        $this->withAmenities($space, ['wifi', 'coffee']);
        $this->pricingFor($unit, 25);

        $response = $this->withToken($this->token($owner))
            ->patchJson("/api/owner/spaces/{$space->id}", [
                'description' => null,
                'amenities' => [],
            ]);

        $response->assertStatus(200);

        $space->refresh();

        $this->assertNull($space->description);
        $this->assertSame([], $space->amenities()->pluck('name')->toArray());

        // Coordinates survived the empty-amenities edit (rule 4 + rule 2).
        $this->assertEqualsWithDelta(24.7136, (float) $space->latitude, 0.0001);
        $this->assertEqualsWithDelta(46.6753, (float) $space->longitude, 0.0001);
    }

    // ------------------------------------------------------------------
    // Item 4: image upload on edit
    // ------------------------------------------------------------------

    public function test_multipart_post_with_method_patch_replaces_the_image(): void
    {
        Storage::fake('cloudinary');

        $owner = $this->owner();
        $space = $this->spaceFor($owner);
        $this->unitFor($space);

        $this->createStoredImage($space->id, 'spaces/old-image.png');

        $response = $this->withToken($this->token($owner))
            ->post("/api/owner/spaces/{$space->id}", [
                '_method' => 'PATCH',
                'title' => 'بعنوان جديد',
                'image' => UploadedFile::fake()->image('photo.jpg', 10, 10),
            ]);

        $response->assertStatus(200);

        $rows = $this->storedImageValues($space->id);
        $this->assertCount(1, $rows, 'the edit replaces the image instead of appending one');
        $this->assertStringEndsWith('.jpg', (string) $rows[0], 'the stored value is a real file path, not an upload flag');

        Storage::disk('cloudinary')->assertExists($rows[0]);

        $this->assertMatchesRegularExpression(
            '#^https://res\.cloudinary\.com/test-cloud/image/upload/spaces/[A-Za-z0-9._-]+\.(jpg|png)$#',
            (string) $response->json('space.image'),
            'the response must carry the new full image URL (Item 2 + Item 4)'
        );

        $space->refresh();
        $this->assertSame('بعنوان جديد', $space->title);
        $this->assertSame('08:00', $this->normalizeTime($space->open_time), 'unmentioned fields keep their values');
    }

    public function test_json_patch_with_a_data_url_image_uploads_it(): void
    {
        Storage::fake('cloudinary');

        $owner = $this->owner();
        $space = $this->spaceFor($owner);
        $this->unitFor($space);

        $response = $this->withToken($this->token($owner))
            ->patchJson("/api/owner/spaces/{$space->id}", [
                'image' => 'data:image/png;base64,'.self::PNG_BASE64,
            ]);

        $response->assertStatus(200);

        $stored = $this->storedImageValue($space->id);

        $this->assertNotSame('1', (string) $stored, 'the boolean upload result must never be stored again (Item 2)');
        $this->assertStringEndsWith('.png', (string) $stored);

        Storage::disk('cloudinary')->assertExists($stored);

        $this->assertMatchesRegularExpression(
            '#^https://res\.cloudinary\.com/test-cloud/image/upload/spaces/[a-f0-9-]+\.png$#',
            (string) $response->json('space.image')
        );
    }

    public function test_an_already_hosted_image_url_is_kept_verbatim(): void
    {
        $owner = $this->owner();
        $space = $this->spaceFor($owner);
        $this->unitFor($space);

        $response = $this->withToken($this->token($owner))
            ->patchJson("/api/owner/spaces/{$space->id}", [
                'image' => 'https://example.com/pictures/pic.jpg',
            ]);

        $response->assertStatus(200);

        $this->assertSame('https://example.com/pictures/pic.jpg', $this->storedImageValue($space->id));
        $this->assertSame('https://example.com/pictures/pic.jpg', $response->json('space.image'));
    }

    public function test_multipart_edit_accepts_the_same_field_shapes_as_create(): void
    {
        Storage::fake('cloudinary');

        $owner = $this->owner();
        $space = $this->spaceFor($owner);
        $this->unitFor($space);

        $response = $this->withToken($this->token($owner))
            ->post("/api/owner/spaces/{$space->id}", [
                '_method' => 'PATCH',
                'internet' => 'false',
                'power' => 'true',
                'amenities' => json_encode(['wifi', 'coffee']),
                'open_time' => '07:30',
                'close_time' => '21:00',
            ]);

        $response->assertStatus(200);

        $space->refresh();
        $unit = Unit::where('workspace_id', $space->id)->first();

        $this->assertFalse((bool) $unit->has_wifi);
        $this->assertTrue((bool) $unit->has_power);
        $this->assertEqualsCanonicalizing(['wifi', 'coffee'], $space->amenities()->pluck('name')->toArray());
        $this->assertSame('07:30', $this->normalizeTime($space->open_time));
        $this->assertSame('21:00', $this->normalizeTime($space->close_time));
    }

    // ------------------------------------------------------------------
    // Item 2: URLs everywhere, flags never
    // ------------------------------------------------------------------

    public function test_owner_endpoints_return_full_image_urls_and_the_hours(): void
    {
        $owner = $this->owner();
        $space = $this->spaceFor($owner);
        $this->unitFor($space);

        $this->createStoredImage($space->id, 'spaces/legacy-real.png');
        $this->createStoredImage($space->id, 'spaces/legacy-two.jpg');

        $response = $this->withToken($this->token($owner))
            ->getJson('/api/owner/spaces');

        $response->assertStatus(200);

        $row = collect($response->json('spaces'))->firstWhere('space_id', $space->id);

        $this->assertSame('08:00', $row['open_time'], 'the owner list must expose the hours');
        $this->assertSame('22:00', $row['close_time']);
        $this->assertSame('0500000000', $row['contact_phone']);

        $this->assertSame(
            'https://res.cloudinary.com/test-cloud/image/upload/spaces/legacy-real.png',
            $row['image'],
            'image must be delivered as a full URL'
        );

        $this->assertSame([
            'https://res.cloudinary.com/test-cloud/image/upload/spaces/legacy-real.png',
            'https://res.cloudinary.com/test-cloud/image/upload/spaces/legacy-two.jpg',
        ], $row['gallery']);
    }

    public function test_legacy_upload_flags_never_resurface_as_urls(): void
    {
        $owner = $this->owner();
        $space = $this->spaceFor($owner);
        $this->unitFor($space);

        $this->createStoredImage($space->id, '1');

        $response = $this->withToken($this->token($owner))
            ->getJson('/api/owner/spaces');

        $row = collect($response->json('spaces'))->firstWhere('space_id', $space->id);

        $this->assertNull($row['image'], 'a stored boolean must not be handed back as an image');
        $this->assertSame([], $row['gallery']);
    }

    public function test_without_a_configured_cloud_name_the_stored_path_is_handed_back(): void
    {
        // An environment with no cloud name derived from CLOUDINARY_* cannot
        // build delivery URLs; the historical behaviour (the raw path) is kept
        // instead of hiding every image.
        config(['filesystems.disks.cloudinary.cloud' => null]);
        config(['cloudinary.cloud_url' => 'cloudinary://::@']);

        $owner = $this->owner();
        $space = $this->spaceFor($owner);
        $this->unitFor($space);
        $this->createStoredImage($space->id, 'spaces/unconfigured.jpg');

        $row = collect($this->withToken($this->token($owner))->getJson('/api/owner/spaces')->json('spaces'))
            ->firstWhere('space_id', $space->id);

        $this->assertSame('spaces/unconfigured.jpg', $row['image']);
        $this->assertSame(['spaces/unconfigured.jpg'], $row['gallery']);
    }

    public function test_public_spaces_answer_full_urls_too(): void
    {
        $owner = $this->owner();
        $space = $this->spaceFor($owner, ['status' => 'active', 'is_active' => true]);
        $unit = $this->unitFor($space);
        $this->pricingFor($unit, 25);
        $this->createStoredImage($space->id, 'spaces/public-shot.jpg');

        $response = $this->getJson("/api/spaces/{$space->id}");

        $response->assertStatus(200)
            ->assertJsonPath('space.image', 'https://res.cloudinary.com/test-cloud/image/upload/spaces/public-shot.jpg')
            ->assertJsonPath('space.gallery.0', 'https://res.cloudinary.com/test-cloud/image/upload/spaces/public-shot.jpg');
    }

    // ------------------------------------------------------------------
    // Create keeps its contracts while gaining the file-image path
    // ------------------------------------------------------------------

    public function test_create_accepts_the_image_as_a_real_file(): void
    {
        Storage::fake('cloudinary');

        $owner = $this->owner();

        $response = $this->withToken($this->token($owner))
            ->post('/api/owner/spaces', [
                'title' => 'مساحة جديدة',
                'description' => 'للثقة بالملف المرفق',
                'location' => 'الرياض',
                'lat' => '24.7136',
                'lng' => '46.6753',
                'price_per_hour' => '50',
                'capacity' => '4',
                'amenities' => json_encode(['wifi']),
                'internet' => 'true',
                'power' => 'false',
                'open_time' => '08:00',
                'close_time' => '22:00',
                'contact_phone' => '0500000000',
                'docs' => UploadedFile::fake()->create('lease.pdf', 120, 'application/pdf'),
                'image' => UploadedFile::fake()->image('photo.png', 10, 10),
            ]);

        $response->assertStatus(201);

        $space = Workspace::firstWhere('title', 'مساحة جديدة');
        $this->assertNotNull($space);

        $stored = $this->storedImageValue($space->id);
        $this->assertStringEndsWith('.png', (string) $stored);
        Storage::disk('cloudinary')->assertExists($stored);

        $this->assertMatchesRegularExpression(
            '#^https://res\.cloudinary\.com/test-cloud/image/upload/spaces/[A-Za-z0-9._-]+\.(jpg|png)$#',
            (string) $response->json('space.image')
        );
    }

    public function test_create_still_accepts_the_base64_image(): void
    {
        Storage::fake('cloudinary');

        $owner = $this->owner();

        $response = $this->withToken($this->token($owner))
            ->post('/api/owner/spaces', [
                'title' => 'مساحة بالـ base64',
                'location' => 'الرياض',
                'lat' => '24.7136',
                'lng' => '46.6753',
                'price_per_hour' => '50',
                'capacity' => '4',
                'amenities' => json_encode(['wifi']),
                'internet' => 'true',
                'power' => 'true',
                'open_time' => '08:00',
                'close_time' => '22:00',
                'contact_phone' => '0500000000',
                'image' => 'data:image/png;base64,'.self::PNG_BASE64,
                'docs' => UploadedFile::fake()->create('lease.pdf', 120, 'application/pdf'),
            ]);

        $response->assertStatus(201);

        $space = Workspace::firstWhere('title', 'مساحة بالـ base64');
        $this->assertNotNull($space);
        $this->assertStringEndsWith('.png', (string) $this->storedImageValue($space->id));
    }

    /**
     * The `time` column reads back as "08:00:00" on MySQL and "08:00" or
     * "08:00:00" on newer SQLite builds — compare the HH:MM prefix so the
     * assertions are driver-agnostic.
     */
    private function normalizeTime($value): string
    {
        return substr((string) $value, 0, 5);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\Pricing;
use App\Models\Review;
use App\Models\Unit;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The public spaces catalogue: GET /api/spaces and GET /api/spaces/{id}.
 *
 * Two things this file is really guarding:
 *
 * 1. Both endpoints are reachable with no token. They are what an anonymous
 *    visitor hits before signing up, so a 401 here is a broken homepage, and
 *    every listing request below deliberately sends no Authorization header.
 * 2. Every documented filter actually changes the result. An accepted-but-
 *    ignored filter renders a full catalogue that ignores what the visitor
 *    asked for, which is the silent failure this catalogue is replacing — so
 *    each filter has its own test that asserts the count actually moves.
 *
 * There is no factory for Workspace / Unit / Pricing (only UserFactory exists),
 * so every fixture is built inline by the helpers below, the way
 * OwnerSpaceTest and SpecialRequestTest do it.
 */
class SpaceCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The catalogue returns full delivery URLs, so pin the cloud name
        // instead of inheriting whatever the local .env happens to hold.
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

    private function customer(): User
    {
        return User::factory()->create(['role' => 'customer']);
    }

    /**
     * A space that is visible to the public by default: approved AND active.
     */
    private function space(array $overrides = []): Workspace
    {
        return Workspace::create(array_merge([
            'owner_id' => $this->owner()->id,
            'title' => 'مساحة الاختبار',
            'description' => 'وصف مختصر للمساحة',
            'location' => 'الرياض - حي النخيل',
            'latitude' => 24.7136,
            'longitude' => 46.6753,
            'contact_phone' => '0500000000',
            'status' => 'active',
            'open_time' => '08:00',
            'close_time' => '22:00',
            'is_closed' => false,
            'is_active' => true,
        ], $overrides));
    }

    private function unit(Workspace $space, array $overrides = []): Unit
    {
        return Unit::create(array_merge([
            'workspace_id' => $space->id,
            'type' => 'desk',
            'capacity' => '4',
            'has_wifi' => true,
            'has_power' => true,
            'status' => 'available',
        ], $overrides));
    }

    /**
     * pricing.price is a string column too; $type lets a test write a daily row
     * next to an hourly one.
     */
    private function price(Unit $unit, string $price, string $type = 'hourly', string $currency = 'ILS'): Pricing
    {
        return Pricing::create([
            'unit_id' => $unit->id,
            'price_type' => $type,
            'price' => $price,
            'currency' => $currency,
        ]);
    }

    private function amenity(string $name): Amenity
    {
        return Amenity::create(['name' => $name, 'icon' => 'check']);
    }

    private function review(Workspace $space, int $rating): Review
    {
        return Review::create([
            'user_id' => $this->customer()->id,
            'workspace_id' => $space->id,
            'rating' => $rating,
            'comment' => 'تقييم تجريبي',
        ]);
    }

    private function image(Workspace $space, string $url): WorkspaceImage
    {
        return WorkspaceImage::create([
            'workspace_id' => $space->id,
            'image_url' => $url,
        ]);
    }

    /**
     * The shape the catalogue is built for: a visible space with one available
     * desk carrying an hourly price.
     */
    private function cataloguedSpace(string $title = 'مساحة الاختبار', string $hourlyPrice = '50.00'): Workspace
    {
        $space = $this->space(['title' => $title]);

        $this->price($this->unit($space), $hourlyPrice);

        return $space;
    }

    /**
     * Build a query string. Arabic values have to be percent-encoded, and
     * http_build_query() is the way to do that without hand-rolling it.
     */
    private function query(array $params): string
    {
        return '?' . http_build_query($params);
    }

    public function test_the_catalogue_is_public_and_never_answers_401(): void
    {
        $this->cataloguedSpace();

        $response = $this->getJson('/api/spaces');

        $response->assertStatus(200);

        $this->assertNotSame(401, $response->getStatusCode(), 'the catalogue must never require a token');
        $response->assertJsonCount(1, 'data');
    }

    public function test_the_response_serves_both_documented_readers(): void
    {
        $this->cataloguedSpace('المساحة الأولى');
        $this->cataloguedSpace('المساحة الثانية');

        $response = $this->getJson('/api/spaces' . $this->query(['per_page' => 1]));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'spaces',
                'current_page',
                'last_page',
                'total',
                'has_more',
                'message',
            ])
            ->assertJsonPath('total', 2)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('has_more', true);

        $this->assertSame($response->json('data'), $response->json('spaces'));
        $response->assertJsonCount(1, 'data');
    }

    public function test_an_empty_catalogue_still_returns_an_array(): void
    {
        $response = $this->getJson('/api/spaces');

        $response->assertStatus(200)->assertJsonCount(0, 'data');
        $this->assertStringContainsString('"data":[]', $response->getContent());
        $this->assertStringContainsString('"spaces":[]', $response->getContent());
    }

    public function test_an_authenticated_caller_gets_the_same_public_catalogue(): void
    {
        $space = $this->cataloguedSpace();

        $this->withToken($this->token($this->customer()))
            ->getJson('/api/spaces')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.space_id', $space->id);
    }

    public function test_total_reflects_the_filtered_count_not_the_unfiltered_one(): void
    {
        $this->cataloguedSpace('مساحة الرياض', '50.00');
        $wanted = $this->cataloguedSpace('مساحة جدة', '60.00');
        $this->cataloguedSpace('مساحة الدمام', '70.00');
        $this->space(['title' => 'مساحة بانتظار الموافقة', 'status' => 'pending']);
        $this->space(['title' => 'مساحة معطّلة', 'is_active' => false]);

        $response = $this->getJson('/api/spaces' . $this->query(['search' => 'جدة']));

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('last_page', 1)
            ->assertJsonPath('data.0.space_id', $wanted->id);

        $this->getJson('/api/spaces')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('total', 3);
    }

    public function test_page_one_and_page_two_return_different_rows(): void
    {
        $first = $this->cataloguedSpace('المساحة الأولى');
        $second = $this->cataloguedSpace('المساحة الثانية');
        $third = $this->cataloguedSpace('المساحة الثالثة');

        $pageOne = $this->getJson('/api/spaces' . $this->query(['per_page' => 1, 'page' => 1]));
        $pageTwo = $this->getJson('/api/spaces' . $this->query(['per_page' => 1, 'page' => 2]));
        $pageThree = $this->getJson('/api/spaces' . $this->query(['per_page' => 1, 'page' => 3]));

        $pageOne->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('total', 3);
        $pageTwo->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('current_page', 2);
        $pageThree->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('current_page', 3);

        $seen = [
            $pageOne->json('data.0.space_id'),
            $pageTwo->json('data.0.space_id'),
            $pageThree->json('data.0.space_id'),
        ];

        $this->assertCount(3, array_unique($seen), 'two pages returned the same space');
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id, $third->id],
            $seen
        );
    }

    public function test_per_page_is_capped_at_fifty(): void
    {
        foreach (range(1, 52) as $index) {
            $this->cataloguedSpace("مساحة {$index}", (string) (10 * $index));
        }

        $response = $this->getJson('/api/spaces' . $this->query(['per_page' => 500]));

        $response->assertStatus(200)
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('total', 52)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('has_more', true);
    }

    public function test_per_page_falls_back_to_the_default_when_it_is_not_a_number(): void
    {
        $this->cataloguedSpace();

        $this->getJson('/api/spaces' . $this->query(['per_page' => 'lots']))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/spaces' . $this->query(['per_page' => 0]))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_search_matches_the_title(): void
    {
        $wanted = $this->cataloguedSpace('مساحة عمل مشتركة');
        $this->cataloguedSpace('قاعة اجتماعات');

        $this->getJson('/api/spaces' . $this->query(['search' => 'مشتركة']))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.space_id', $wanted->id);
    }

    public function test_search_matches_the_description(): void
    {
        $wanted = $this->space([
            'title' => 'مساحة الاختبار',
            'description' => 'قاعة مجهزة بشاشة عرض ضخمة',
        ]);
        $this->unit($wanted);
        $this->space(['title' => 'مسافة أخرى', 'description' => 'صالة بدون أي تجهيزات']);

        $this->getJson('/api/spaces' . $this->query(['search' => 'مجهزة']))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.space_id', $wanted->id);
    }

    public function test_search_matches_the_location(): void
    {
        $wanted = $this->cataloguedSpace('مساحة جدة', '50.00');
        $this->cataloguedSpace('مساحة الرياض', '50.00');

        $this->getJson('/api/spaces' . $this->query(['search' => 'جدة']))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.space_id', $wanted->id);
    }

    public function test_search_is_case_insensitive(): void
    {
        $wanted = $this->cataloguedSpace('Co-Working Hub');

        $this->getJson('/api/spaces' . $this->query(['search' => 'co-working']))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.space_id', $wanted->id);
    }

    public function test_a_blank_search_is_not_a_filter(): void
    {
        $this->cataloguedSpace('المساحة الأولى');
        $this->cataloguedSpace('المساحة الثانية');

        $this->getJson('/api/spaces' . $this->query(['search' => '   ']))
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_category_filters_by_the_unit_type(): void
    {
        $desk = $this->cataloguedSpace('طاولة عمل');

        $room = $this->cataloguedSpace('غرفة خاصة');
        $room->units()->first()->update(['type' => 'private_room']);

        $whole = $this->cataloguedSpace('مساحة كاملة');
        $whole->units()->first()->update(['type' => 'full_space']);

        $this->getJson('/api/spaces' . $this->query(['category' => 'private_room']))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.space_id', $room->id)
            ->assertJsonPath('data.0.category', 'private_room');

        $this->getJson('/api/spaces' . $this->query(['category' => 'desk']))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.space_id', $desk->id);

        $this->getJson('/api/spaces' . $this->query(['category' => 'full_space']))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.space_id', $whole->id);
    }

    public function test_an_unsupported_category_is_ignored_instead_of_erroring(): void
    {
        $this->cataloguedSpace('المساحة الأولى');
        $this->cataloguedSpace('المساحة الثانية');

        $this->getJson('/api/spaces' . $this->query(['category' => 'castle']))
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 2);
    }

    public function test_area_matches_the_location(): void
    {
        $wanted = $this->space(['location' => 'جدة - حي الياسمين']);
        $this->unit($wanted);
        $this->cataloguedSpace('مساحة الرياض', '50.00');

        $this->getJson('/api/spaces' . $this->query(['area' => 'الياسمين']))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.space_id', $wanted->id)
            ->assertJsonPath('data.0.area', 'جدة - حي الياسمين');
    }

    public function test_min_price_is_inclusive_and_drops_the_cheaper_spaces(): void
    {
        $cheap = $this->cataloguedSpace('مساحة رخيصة', '50.00');
        $mid = $this->cataloguedSpace('مساحة متوسطة', '100.00');
        $pricey = $this->cataloguedSpace('مساحة غالية', '250.00');

        $inclusive = $this->getJson('/api/spaces' . $this->query(['min_price' => 50]));
        $inclusive->assertStatus(200)->assertJsonCount(3, 'data');

        $this->getJson('/api/spaces' . $this->query(['min_price' => 51]))
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 2);

        $this->assertNotContains(
            $cheap->id,
            $this->getJson('/api/spaces' . $this->query(['min_price' => 51]))->json('data.*.space_id')
        );

        $this->assertEqualsCanonicalizing(
            [$mid->id, $pricey->id],
            $this->getJson('/api/spaces' . $this->query(['min_price' => 51]))->json('data.*.space_id')
        );
    }

    public function test_max_price_is_inclusive_and_drops_the_pricier_spaces(): void
    {
        $this->cataloguedSpace('مساحة رخيصة', '50.00');
        $this->cataloguedSpace('مساحة متوسطة', '100.00');
        $pricey = $this->cataloguedSpace('مساحة غالية', '250.00');

        $this->getJson('/api/spaces' . $this->query(['max_price' => 100]))
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');

        $this->assertNotContains(
            $pricey->id,
            $this->getJson('/api/spaces' . $this->query(['max_price' => 99]))->json('data.*.space_id')
        );

        $this->getJson('/api/spaces' . $this->query(['max_price' => 99]))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_the_price_filters_read_the_hourly_rate_and_never_the_daily_one(): void
    {
        $hourlySpace = $this->space(['title' => 'مساحة بالساعة']);
        $hourlyUnit = $this->unit($hourlySpace);
        $this->price($hourlyUnit, '10', 'daily');
        $this->price($hourlyUnit, '120', 'hourly');

        $this->price($hourlyUnit, '900', 'monthly');

        $other = $this->space(['title' => 'مساحة أغلى']);
        $otherUnit = $this->unit($other);
        $this->price($otherUnit, '5', 'daily');
        $this->price($otherUnit, '200', 'hourly');

        $response = $this->getJson('/api/spaces' . $this->query(['max_price' => 150]));

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.space_id', $hourlySpace->id)
            ->assertJsonPath('data.0.price_per_hour', 120)
            ->assertJsonPath('data.0.price', 120);
    }

    public function test_amenities_require_every_listed_amenity(): void
    {
        $internet = $this->amenity('internet');
        $ac = $this->amenity('ac');

        $both = $this->cataloguedSpace('مساحة كاملة التجهيز');
        $both->amenities()->sync([$internet->id, $ac->id]);

        $internetOnly = $this->cataloguedSpace('مساحة الإنترنت فقط');
        $internetOnly->amenities()->sync([$internet->id]);

        $this->cataloguedSpace('مساحة بلا تجهيزات');

        $this->getJson('/api/spaces' . $this->query(['amenities' => 'internet,ac']))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.space_id', $both->id);

        $this->getJson('/api/spaces' . $this->query(['amenities' => 'internet']))
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 2);

        $this->getJson('/api/spaces' . $this->query(['amenities' => 'jacuzzi']))
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_amenities_are_returned_as_names(): void
    {
        $internet = $this->amenity('internet');
        $space = $this->cataloguedSpace('مساحة فيها إنترنت');
        $space->amenities()->sync([$internet->id]);

        $response = $this->getJson('/api/spaces');

        $response->assertStatus(200);
        $this->assertSame(['internet'], $response->json('data.0.amenities'));
    }

    public function test_min_rating_filters_on_the_review_average(): void
    {
        $strong = $this->cataloguedSpace('مساحة ممتازة', '50.00');
        $this->review($strong, 5);
        $this->review($strong, 4);

        $weak = $this->cataloguedSpace('مسافة عادية', '40.00');
        $this->review($weak, 3);

        $unrated = $this->cataloguedSpace('مساحة بلا تقييمات', '30.00');

        $this->getJson('/api/spaces' . $this->query(['min_rating' => 0]))
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');

        $this->getJson('/api/spaces' . $this->query(['min_rating' => 4]))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.space_id', $strong->id)
            ->assertJsonPath('data.0.rating', 4.5)
            ->assertJsonPath('data.0.review_count', 2);

        $this->getJson('/api/spaces' . $this->query(['min_rating' => 4.5]))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/spaces' . $this->query(['min_rating' => 5]))
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $atLeastOneStar = $this->getJson('/api/spaces' . $this->query(['min_rating' => 1]));

        $atLeastOneStar->assertStatus(200)->assertJsonCount(2, 'data');
        $this->assertNotContains(
            $unrated->id,
            $atLeastOneStar->json('data.*.space_id')
        );
    }

    public function test_sort_by_rating_is_descending(): void
    {
        $best = $this->cataloguedSpace('الأعلى تقييماً', '50.00');
        $this->review($best, 5);
        $this->review($best, 4);

        $middle = $this->cataloguedSpace('الأوسط تقييماً', '50.00');
        $this->review($middle, 3);

        $worst = $this->cataloguedSpace('الأدنى تقييماً', '50.00');
        $this->review($worst, 1);

        $unrated = $this->cataloguedSpace('بلا تقييم', '50.00');

        $response = $this->getJson('/api/spaces' . $this->query(['sort' => 'rating']));

        $response->assertStatus(200)->assertJsonCount(4, 'data');

        $this->assertEqualsCanonicalizing(
            [$best->id, $middle->id, $worst->id],
            [$response->json('data.0.space_id'), $response->json('data.1.space_id'), $response->json('data.2.space_id')],
            'sort=rating is not descending, or unreviewed spaces are not last'
        );

        $this->assertSame($unrated->id, $response->json('data.3.space_id'));
    }

    public function test_sort_by_price_is_ascending_and_descending(): void
    {
        $cheapest = $this->cataloguedSpace('الأرخص', '30.00');
        $middle = $this->cataloguedSpace('المتوسط', '80.00');
        $dearest = $this->cataloguedSpace('الأغلى', '200.00');

        $ascending = $this->getJson('/api/spaces' . $this->query(['sort' => 'price_asc']));
        $descending = $this->getJson('/api/spaces' . $this->query(['sort' => 'price_desc']));

        $ascending->assertStatus(200)->assertJsonCount(3, 'data');
        $descending->assertStatus(200)->assertJsonCount(3, 'data');

        $this->assertSame(
            [$cheapest->id, $middle->id, $dearest->id],
            $ascending->json('data.*.space_id'),
            'sort=price_asc is not ascending by hourly price'
        );

        $this->assertSame(
            [$dearest->id, $middle->id, $cheapest->id],
            $descending->json('data.*.space_id'),
            'sort=price_desc is not descending by hourly price'
        );
    }

    public function test_newest_is_the_default_and_an_unknown_sort_falls_back_to_it(): void
    {
        $older = $this->cataloguedSpace('المساحة الأقدم', '50.00');
        $newer = $this->cataloguedSpace('المساحة الأحدث', '50.00');

        $older->created_at = now()->subDays(2);
        $older->save();

        $default = $this->getJson('/api/spaces');
        $default->assertStatus(200)->assertJsonPath('data.0.space_id', $newer->id);

        $this->getJson('/api/spaces' . $this->query(['sort' => 'newest']))
            ->assertStatus(200)
            ->assertJsonPath('data.0.space_id', $newer->id);

        $this->getJson('/api/spaces' . $this->query(['sort' => 'cheapest']))
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.space_id', $newer->id);
    }

    public function test_pending_inactive_and_soft_deleted_spaces_are_excluded(): void
    {
        $visible = $this->cataloguedSpace('مساحة مرئية');

        $pending = $this->space(['title' => 'مساحة بانتظار الموافقة', 'status' => 'pending']);
        $this->unit($pending);

        $rejected = $this->space(['title' => 'مساحة مرفوضة', 'status' => 'rejected']);
        $this->unit($rejected);

        $inactive = $this->space(['title' => 'مساحة معطّلة', 'is_active' => false]);
        $this->unit($inactive);

        $deleted = $this->cataloguedSpace('مساحة محذوفة');

        DB::table('workspaces')->where('id', $deleted->id)->update(['deleted_at' => now()]);

        $response = $this->getJson('/api/spaces');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.space_id', $visible->id);

        $this->assertNotContains(
            $pending->id,
            $response->json('data.*.space_id')
        );
    }

    public function test_unknown_query_parameters_are_ignored(): void
    {
        $this->cataloguedSpace();

        $this->getJson('/api/spaces' . $this->query([
            'utm_source' => 'newsletter',
            'sort_by' => 'cheapest',
            'filters' => 'anything',
            'page' => 1,
        ]))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('total', 1);
    }

    public function test_a_catalogue_item_carries_the_dual_spelled_typed_fields(): void
    {
        $space = $this->cataloguedSpace('مساحة العقد', '75.00');
        $this->image($space, 'spaces/one.jpg');
        $this->image($space, 'spaces/two.jpg');

        $response = $this->getJson('/api/spaces');
        $response->assertStatus(200);

        $item = $response->json('data.0');

        $this->assertSame($space->id, $item['space_id']);
        $this->assertSame($item['space_id'], $item['id']);
        $this->assertSame($item['price_per_hour'], $item['price']);

        $this->assertIsNotString($item['price_per_hour']);
        $this->assertIsNumeric($item['price_per_hour']);
        $this->assertEqualsWithDelta(75.0, $item['price_per_hour'], 0.001);
        $this->assertIsInt($item['capacity']);
        $this->assertIsNotString($item['rating']);
        $this->assertIsNumeric($item['rating']);
        $this->assertIsFloat($item['latitude']);
        $this->assertIsBool($item['is_active']);
        $this->assertIsBool($item['instant_booking']);

        $this->assertSame($space->location, $item['area']);
        $this->assertSame('desk', $item['category']);

        $this->assertIsArray($item['gallery']);
        $this->assertSame([
            'https://res.cloudinary.com/test-cloud/image/upload/spaces/one.jpg',
            'https://res.cloudinary.com/test-cloud/image/upload/spaces/two.jpg',
        ], $item['gallery']);
        $this->assertSame(
            'https://res.cloudinary.com/test-cloud/image/upload/spaces/one.jpg',
            $item['image']
        );

        $this->assertIsArray($item['amenities']);
        $this->assertSame('08:00', $item['open_time']);
        $this->assertSame('22:00', $item['close_time']);
        $this->assertSame(0, $item['review_count']);
        $this->assertNotNull($item['created_at']);
    }

    public function test_capacity_is_the_sum_of_the_available_units(): void
    {
        $space = $this->space(['title' => 'مساحة بعدة وحدات']);
        $this->unit($space, ['capacity' => '4', 'status' => 'available']);
        $this->unit($space, ['capacity' => '6', 'status' => 'available']);
        $this->unit($space, ['capacity' => '10', 'status' => 'unavailable']);

        $this->getJson('/api/spaces')
            ->assertStatus(200)
            ->assertJsonPath('data.0.capacity', 10);
    }

    public function test_capacity_falls_back_to_the_largest_unit_when_none_are_available(): void
    {
        $space = $this->space(['title' => 'مساحة غير متاحة']);
        $this->unit($space, ['capacity' => '4', 'status' => 'unavailable']);
        $this->unit($space, ['capacity' => '9', 'status' => 'unavailable']);

        $this->getJson('/api/spaces')
            ->assertStatus(200)
            ->assertJsonPath('data.0.capacity', 9)
            ->assertJsonPath('data.0.instant_booking', false);
    }

    public function test_instant_booking_requires_an_available_unit(): void
    {
        $bookable = $this->cataloguedSpace('مساحة قابلة للحجز', '50.00');

        $blocked = $this->space(['title' => 'مساحة غير قابلة للحجز']);
        $this->unit($blocked, ['status' => 'unavailable']);

        $response = $this->getJson('/api/spaces');
        $response->assertStatus(200)->assertJsonCount(2, 'data');

        $byId = collect($response->json('data'))->keyBy('space_id');

        $this->assertTrue($byId[$bookable->id]['instant_booking']);
        $this->assertFalse($byId[$blocked->id]['instant_booking']);
    }

    public function test_a_space_with_no_images_pricing_or_reviews_still_renders(): void
    {
        $this->space(['title' => 'مساحة بلا وحدات', 'description' => null]);
        $unpriced = $this->space(['title' => 'مساحة بلا تسعير']);
        $this->unit($unpriced);
        $this->cataloguedSpace('مساحة مكتملة', '50.00');

        $response = $this->getJson('/api/spaces');
        $response->assertStatus(200)->assertJsonCount(3, 'data');

        $bare = collect($response->json('data'))->firstWhere('title', 'مساحة بلا وحدات');

        $this->assertNotNull($bare);
        $this->assertNull($bare['image']);
        $this->assertSame([], $bare['gallery']);
        $this->assertSame([], $bare['amenities']);
        $this->assertNull($bare['category']);
        $this->assertSame(0, $bare['capacity']);
        $this->assertEqualsWithDelta(0.0, $bare['rating'], 0.001);
        $this->assertIsNotString($bare['rating']);
        $this->assertSame(0, $bare['review_count']);

        $noPricing = collect($response->json('data'))->firstWhere('title', 'مساحة بلا تسعير');

        $this->assertNull($noPricing['price_per_hour']);
        $this->assertNull($noPricing['price']);
        $this->assertSame('desk', $noPricing['category']);
    }

    public function test_show_returns_the_space_under_both_documented_keys(): void
    {
        $space = $this->cataloguedSpace('مساحة التفاصيل', '90.00');
        $this->image($space, 'spaces/detail.jpg');

        $response = $this->getJson("/api/spaces/{$space->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'space' => [
                    'space_id',
                    'id',
                    'title',
                    'description',
                    'location',
                    'area',
                    'category',
                    'image',
                    'gallery',
                    'price_per_hour',
                    'price',
                    'capacity',
                    'amenities',
                    'rating',
                    'review_count',
                    'is_active',
                    'instant_booking',
                    'open_time',
                    'close_time',
                    'contact_phone',
                    'latitude',
                    'longitude',
                ],
                'data' => ['space_id', 'id'],
                'message',
            ])
            ->assertJsonPath('space.id', $space->id)
            ->assertJsonPath('data.id', $space->id)
            ->assertJsonPath('space.title', 'مساحة التفاصيل')
            ->assertJsonPath('space.price_per_hour', 90)
            ->assertJsonPath('space.instant_booking', true)
            ->assertJsonPath('space.gallery', [
                'https://res.cloudinary.com/test-cloud/image/upload/spaces/detail.jpg',
            ]);

        $this->assertSame($response->json('space'), $response->json('data'));
    }

    public function test_show_is_public_without_a_token(): void
    {
        $space = $this->cataloguedSpace();

        $response = $this->getJson("/api/spaces/{$space->id}");

        $response->assertStatus(200)->assertJsonPath('space.id', $space->id);
        $this->assertNotSame(401, $response->getStatusCode());
        $this->assertNotSame(403, $response->getStatusCode());
    }

    public function test_show_returns_404_for_an_unknown_id_and_never_401_or_403(): void
    {
        $this->cataloguedSpace();

        $response = $this->getJson('/api/spaces/999999');

        $response->assertStatus(404)->assertJsonPath('message', 'المساحة غير موجودة.');

        $this->assertNotSame(401, $response->getStatusCode(), 'a public space must never be behind a token');
        $this->assertNotSame(403, $response->getStatusCode(), 'the space is public, so 403 is never right');
    }

    public function test_show_returns_404_not_403_for_an_unapproved_space(): void
    {
        $pending = $this->space(['title' => 'مساحة بانتظار الموافقة', 'status' => 'pending']);
        $this->unit($pending);

        $response = $this->getJson("/api/spaces/{$pending->id}");

        $response->assertStatus(404)->assertJsonPath('message', 'المساحة غير موجودة.');
        $this->assertNotSame(403, $response->getStatusCode(), 'an unapproved listing must not be acknowledged with 403');
        $this->assertNotSame(401, $response->getStatusCode());
    }

    public function test_show_returns_404_for_an_inactive_or_soft_deleted_space(): void
    {
        $inactive = $this->space(['title' => 'مساحة معطّلة', 'is_active' => false]);
        $this->unit($inactive);

        $deleted = $this->cataloguedSpace('مساحة محذوفة');
        DB::table('workspaces')->where('id', $deleted->id)->update(['deleted_at' => now()]);

        $this->getJson("/api/spaces/{$inactive->id}")
            ->assertStatus(404)
            ->assertJsonPath('message', 'المساحة غير موجودة.');

        $this->getJson("/api/spaces/{$deleted->id}")
            ->assertStatus(404)
            ->assertJsonPath('message', 'المساحة غير موجودة.');
    }
}
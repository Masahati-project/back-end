<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/ads/open — the public banner feed.
 *
 * The feed had no backend at all: the page read a hardcoded empty array, so no
 * customer has ever seen a published ad. These tests pin the two rules that make
 * it safe to publish real ads through it — status is enforced server side, and
 * the expiry is enforced on the server too, not left to the page's own
 * client-side date filter.
 */
class PublicAdTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return $user->createToken('auth_token')->plainTextToken;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function owner(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['role' => 'space_owner'], $overrides));
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => 'customer']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function workspaceFor(User $owner): Workspace
    {
        return Workspace::create([
            'owner_id' => $owner->id,
            'title' => 'مساحة الاختبار',
            'location' => 'الرياض',
            'latitude' => 24.7136,
            'longitude' => 46.6753,
            'contact_phone' => '0500000000',
            'status' => 'active',
            'open_time' => '08:00',
            'close_time' => '22:00',
            'is_closed' => false,
        ]);
    }

    /**
     * There is no AdFactory, so ads are built inline.
     *
     * `schedule` is a JSON column cast to `json`, so an array is written as a
     * JSON object; pass a JSON *string* instead to reproduce the shape
     * OwnerAdController stores (see test_a_json_encoded_schedule_still_expires).
     */
    private function adFrom(User $owner, array $overrides = []): Ad
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
        return $this->adFrom($owner, array_merge(['status' => 'published'], $overrides));
    }

    public function test_a_customer_can_read_the_feed(): void
    {
        $this->publishedAd($this->owner());

        $this->withToken($this->token($this->customer()))
            ->getJson('/api/ads/open')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_a_space_owner_can_read_the_feed(): void
    {
        $this->publishedAd($this->owner());

        $this->withToken($this->token($this->owner()))
            ->getJson('/api/ads/open')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_an_admin_is_refused_the_feed(): void
    {
        $this->withToken($this->token($this->admin()))
            ->getJson('/api/ads/open')
            ->assertStatus(403)
            ->assertJsonPath('message', 'لا تملك صلاحية الوصول إلى قائمة الإعلانات.');
    }

    public function test_the_feed_requires_authentication(): void
    {
        $this->getJson('/api/ads/open')->assertStatus(401);
    }

    public function test_the_feed_contains_only_published_ads(): void
    {
        $owner = $this->owner();
        $live = $this->publishedAd($owner, ['title' => 'إعلان منشور']);

        $draft = $this->adFrom($owner, ['title' => 'إعلان مسودة', 'status' => 'draft']);
        $archived = $this->adFrom($owner, ['title' => 'إعلان مؤرشف', 'status' => 'archived']);

        $response = $this->withToken($this->token($this->customer()))
            ->getJson('/api/ads/open');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ad_id', $live->id);

        $response->assertJsonMissing(['ad_id' => $draft->id])
            ->assertJsonMissing(['ad_id' => $archived->id]);
    }

    public function test_an_ad_that_expired_is_dropped_by_the_server(): void
    {
        $owner = $this->owner();
        $live = $this->publishedAd($owner, ['title' => 'إعلان ساري']);
        $expired = $this->publishedAd($owner, [
            'title' => 'إعلان منتهٍ',
            'schedule' => ['expires_at' => now()->subDay()->toIso8601String()],
        ]);

        $response = $this->withToken($this->token($this->customer()))
            ->getJson('/api/ads/open');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ad_id', $live->id);

        $response->assertJsonMissing(['ad_id' => $expired->id]);
    }

    public function test_an_ad_that_has_not_expired_is_returned(): void
    {
        $expiresAt = now()->addMonth();
        $ad = $this->publishedAd($this->owner(), [
            'schedule' => ['expires_at' => $expiresAt->toIso8601String()],
        ]);

        $response = $this->withToken($this->token($this->customer()))
            ->getJson('/api/ads/open');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ad_id', $ad->id)
            ->assertJsonPath('data.0.expires_at', $expiresAt->toIso8601String());
    }

    public function test_an_ad_with_no_expiry_at_all_is_returned(): void
    {
        $owner = $this->owner();
        $withoutSchedule = $this->publishedAd($owner, ['title' => 'بلا جدولة', 'schedule' => null]);
        $unrelatedSchedule = $this->publishedAd($owner, [
            'title' => 'جدولة بلا انتهاء',
            'schedule' => ['frequency' => 'weekly', 'impressions_per_day' => 100],
        ]);

        $response = $this->withToken($this->token($this->customer()))
            ->getJson('/api/ads/open');

        $response->assertStatus(200)->assertJsonCount(2, 'data');

        $response->assertJsonPath('data.0.expires_at', null)
            ->assertJsonPath('data.1.expires_at', null);

        $ids = array_column($response->json('data'), 'ad_id');

        $this->assertContains($withoutSchedule->id, $ids);
        $this->assertContains($unrelatedSchedule->id, $ids);
    }

    public function test_an_unreadable_expiry_does_not_hide_the_ad(): void
    {
        $ad = $this->publishedAd($this->owner(), [
            'schedule' => ['expires_at' => '__garbage__'],
        ]);

        $this->withToken($this->token($this->customer()))
            ->getJson('/api/ads/open')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ad_id', $ad->id)
            ->assertJsonPath('data.0.expires_at', null);
    }

    public function test_every_supported_expiry_spelling_is_honoured(): void
    {
        $customer = $this->customer();

        foreach (['expires_at', 'expiresAt', 'end_at', 'endAt', 'expires', 'until'] as $key) {
            $this->publishedAd($this->owner(), [
                'title' => 'إعلان ' . $key,
                'schedule' => [$key => now()->subHour()->toIso8601String()],
            ]);

            $this->withToken($this->token($customer))
                ->getJson('/api/ads/open')
                ->assertStatus(200)
                ->assertJsonCount(0, 'data');
        }
    }

    public function test_the_first_readable_expiry_key_wins(): void
    {
        $ad = $this->publishedAd($this->owner(), [
            'schedule' => [
                'expires_at' => now()->addMonth()->toIso8601String(),
                'until' => now()->subDay()->toIso8601String(),
            ],
        ]);

        $this->withToken($this->token($this->customer()))
            ->getJson('/api/ads/open')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ad_id', $ad->id);
    }

    public function test_a_json_encoded_schedule_still_expires(): void
    {
        $ad = $this->publishedAd($this->owner(), [
            'schedule' => json_encode(['expires_at' => now()->subDay()->toIso8601String()]),
        ]);

        $this->assertIsString($ad->fresh()->schedule);

        $this->withToken($this->token($this->customer()))
            ->getJson('/api/ads/open')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_the_feed_exposes_the_documented_shape(): void
    {
        $owner = $this->owner(['profile_picture_url' => 'avatars/owner.png']);
        $expiresAt = now()->addMonth()->toIso8601String();
        $sentAt = now()->subDays(3);

        $ad = $this->publishedAd($owner, [
            'description' => 'خصم على الليلة',
            'link' => '/spaces/12',
            'image' => 'ads/banner.png',
            'schedule' => ['expires_at' => $expiresAt],
            'sent_at' => $sentAt,
        ]);

        $response = $this->withToken($this->token($this->customer()))
            ->getJson('/api/ads/open');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'ad_id',
                        'title',
                        'description',
                        'link',
                        'image',
                        'owner_name',
                        'owner_avatar',
                        'published_at',
                        'expires_at',
                    ],
                ],
                'ads' => ['*' => ['id', 'ad_id']],
            ])
            ->assertJsonPath('data.0.ad_id', $ad->id)
            ->assertJsonPath('data.0.id', $ad->id)
            ->assertJsonPath('data.0.title', 'إعلان تجريبي')
            ->assertJsonPath('data.0.description', 'خصم على الليلة')
            ->assertJsonPath('data.0.link', '/spaces/12')
            ->assertJsonPath('data.0.image', 'ads/banner.png')
            ->assertJsonPath('data.0.owner_name', $owner->full_name)
            ->assertJsonPath('data.0.owner_avatar', 'avatars/owner.png')
            ->assertJsonPath('data.0.published_at', $sentAt->toIso8601String())
            ->assertJsonPath('data.0.expires_at', $expiresAt);
    }

    public function test_published_at_falls_back_to_created_at(): void
    {
        $ad = $this->publishedAd($this->owner(), ['sent_at' => null]);

        $this->withToken($this->token($this->customer()))
            ->getJson('/api/ads/open')
            ->assertStatus(200)
            ->assertJsonPath('data.0.published_at', $ad->created_at->toIso8601String());
    }

    public function test_data_and_ads_carry_the_same_items(): void
    {
        $this->publishedAd($this->owner());

        $response = $this->withToken($this->token($this->customer()))
            ->getJson('/api/ads/open');

        $response->assertStatus(200)->assertJsonStructure(['data', 'ads', 'message']);

        $this->assertSame($response->json('data'), $response->json('ads'));
    }

    public function test_an_ad_without_a_link_reports_it_as_null(): void
    {
        $this->publishedAd($this->owner(), ['link' => null]);

        $this->withToken($this->token($this->customer()))
            ->getJson('/api/ads/open')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.link', null);
    }

    public function test_an_empty_feed_is_returned_as_an_empty_array(): void
    {
        $response = $this->withToken($this->token($this->customer()))
            ->getJson('/api/ads/open');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonCount(0, 'ads');

        $this->assertStringContainsString('"data":[]', $response->getContent());
    }

    public function test_the_feed_is_capped_at_fifty_ads(): void
    {
        $owner = $this->owner();

        for ($i = 0; $i < 55; $i++) {
            $this->publishedAd($owner, ['title' => 'إعلان ' . $i]);
        }

        $this->withToken($this->token($this->customer()))
            ->getJson('/api/ads/open')
            ->assertStatus(200)
            ->assertJsonCount(50, 'data');
    }

    public function test_the_fields_of_a_deleted_advertiser_are_null(): void
    {
        $owner = $this->owner(['full_name' => 'مالك الإعلان', 'profile_picture_url' => 'avatars/gone.png']);
        $ad = $this->publishedAd($owner);
        $owner->delete();

        $this->withToken($this->token($this->customer()))
            ->getJson('/api/ads/open')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ad_id', $ad->id)
            ->assertJsonPath('data.0.owner_name', null)
            ->assertJsonPath('data.0.owner_avatar', null);
    }

    public function test_the_details_of_a_deleted_space_are_not_exposed(): void
    {
        $owner = $this->owner();
        $space = $this->workspaceFor($owner);
        $ad = $this->publishedAd($owner, ['space_id' => $space->id]);

        $space->deleted_at = now();
        $space->save();

        $this->withToken($this->token($this->customer()))
            ->getJson('/api/ads/open')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.space_id', $space->id)
            ->assertJsonPath('data.0.space_name', null);
    }

    public function test_the_space_name_is_exposed_for_a_live_space(): void
    {
        $owner = $this->owner();
        $space = $this->workspaceFor($owner);
        $this->publishedAd($owner, ['space_id' => $space->id]);

        $this->withToken($this->token($this->customer()))
            ->getJson('/api/ads/open')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.space_id', $space->id)
            ->assertJsonPath('data.0.space_name', 'مساحة الاختبار');
    }
}
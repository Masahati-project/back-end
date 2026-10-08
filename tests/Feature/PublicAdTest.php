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

    private function owner(): User
    {
        return User::factory()->create(['role' => 'space_owner']);
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
            'status' => 'approved',
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

    // -------------------------------------------------------------------------
    // GET /api/ads/open — access control
    // -------------------------------------------------------------------------

    public function test_a_customer_can_read_the_feed(): void
    {
        // The banner strip is shown to signed-in customers, so this is the case
        // the endpoint exists for.
        $this->publishedAd($this->owner());

        $this->withToken($this->token($this->customer()))
            ->getJson('/api/ads/open')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_a_space_owner_can_read_the_feed(): void
    {
        // Owners browse the marketplace too, so the same strip has to be
        // readable for them.
        $this->publishedAd($this->owner());

        $this->withToken($this->token($this->owner()))
            ->getJson('/api/ads/open')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_an_admin_is_refused_the_feed(): void
    {
        // `admin` is not an audience of the public strip, and an admin already
        // reads its own rows through /api/owner/ads.
        $this->withToken($this->token($this->admin()))
            ->getJson('/api/ads/open')
            ->assertStatus(403)
            ->assertJsonPath('message', 'لا تملك صلاحية الوصول إلى قائمة الإعلانات.');
    }

    public function test_the_feed_requires_authentication(): void
    {
        // Unlike the spaces endpoints this one is NOT public data: an
        // unauthenticated request has to be a 401, not an empty list.
        $this->getJson('/api/ads/open')->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // GET /api/ads/open — only published ads are ever served
    // -------------------------------------------------------------------------

    public function test_the_feed_contains_only_published_ads(): void
    {
        $owner = $this->owner();
        $live = $this->publishedAd($owner, ['title' => 'إعلان منشور']);

        // A draft has not been sent yet and an archived one has been retired;
        // neither may ever reach a customer.
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

    // -------------------------------------------------------------------------
    // GET /api/ads/open — expiry is enforced here, not in the browser
    // -------------------------------------------------------------------------

    public function test_an_ad_that_expired_is_dropped_by_the_server(): void
    {
        // This is the reason the endpoint exists. The frontend drops ads whose
        // expires_at has passed, but the requirement is explicit that the server
        // honours it too: a client that skips the filter, a crawler, or a stale
        // bundle must not be served a finished campaign.
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
            // The derived date is echoed back so the client can render it.
            ->assertJsonPath('data.0.expires_at', $expiresAt->toIso8601String());
    }

    public function test_an_ad_with_no_expiry_at_all_is_returned(): void
    {
        // `schedule` is nullable and most owners never fill it in, so treating
        // "no expiry" as "expired" would take the whole inventory off the air.
        // The frontend contract says the same thing: expires_at is optional and a
        // missing date still displays.
        $owner = $this->owner();
        $withoutSchedule = $this->publishedAd($owner, ['title' => 'بلا جدولة', 'schedule' => null]);
        $unrelatedSchedule = $this->publishedAd($owner, [
            'title' => 'جدولة بلا انتهاء',
            'schedule' => ['frequency' => 'weekly', 'impressions_per_day' => 100],
        ]);

        $response = $this->withToken($this->token($this->customer()))
            ->getJson('/api/ads/open');

        $response->assertStatus(200)->assertJsonCount(2, 'data');

        // Null is the honest answer for "runs until archived" — not a fabricated
        // date, and not an omission of the key.
        $response->assertJsonPath('data.0.expires_at', null)
            ->assertJsonPath('data.1.expires_at', null);

        $ids = array_column($response->json('data'), 'ad_id');

        $this->assertContains($withoutSchedule->id, $ids);
        $this->assertContains($unrelatedSchedule->id, $ids);
    }

    public function test_an_unreadable_expiry_does_not_hide_the_ad(): void
    {
        // OwnerAdController accepts whatever JSON the client sends, so an expiry
        // that cannot be read must degrade to "no expiry" instead of taking the
        // ad off the feed — and must not fatal the endpoint.
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
        // ads has no expires_at column, so the expiry is derived from the
        // schedule blob. Each accepted spelling has to expire the ad, otherwise
        // one of them silently becomes a permanent banner.
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
        // Documented precedence: expires_at is consulted before until, so the
        // later date is the one that decides the ad's fate.
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
        // OwnerAdController validates `schedule` with the `json` rule, which only
        // accepts a string — so it hands Ad::create() an already-encoded JSON
        // string, the `json` cast encodes it a second time, and the column stores
        // a JSON *string scalar* rather than an object. That is the shape every
        // row created through the owner dashboard actually has, so reading only
        // an array would leave those campaigns live forever.
        $ad = $this->publishedAd($this->owner(), [
            'schedule' => json_encode(['expires_at' => now()->subDay()->toIso8601String()]),
        ]);

        // Pins the premise: the value really does come back as a string.
        $this->assertIsString($ad->fresh()->schedule);

        $this->withToken($this->token($this->customer()))
            ->getJson('/api/ads/open')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    // -------------------------------------------------------------------------
    // GET /api/ads/open — response contract
    // -------------------------------------------------------------------------

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
            // sent_at is stamped when the ad goes live, so it is the honest
            // publication date; created_at would still read as the draft date.
            ->assertJsonPath('data.0.published_at', $sentAt->toIso8601String())
            ->assertJsonPath('data.0.expires_at', $expiresAt);
    }

    public function test_published_at_falls_back_to_created_at(): void
    {
        // A published row written before sent_at existed still needs a date, or
        // the banner renders without one.
        $ad = $this->publishedAd($this->owner(), ['sent_at' => null]);

        $this->withToken($this->token($this->customer()))
            ->getJson('/api/ads/open')
            ->assertStatus(200)
            ->assertJsonPath('data.0.published_at', $ad->created_at->toIso8601String());
    }

    public function test_data_and_ads_carry_the_same_items(): void
    {
        // The frontend accepts data, ads, or a bare array. `data` is the
        // documented key and `ads` is the alias, so a client written against
        // either one renders the strip instead of an empty list.
        $this->publishedAd($this->owner());

        $response = $this->withToken($this->token($this->customer()))
            ->getJson('/api/ads/open');

        $response->assertStatus(200)->assertJsonStructure(['data', 'ads', 'message']);

        $this->assertSame($response->json('data'), $response->json('ads'));
    }

    public function test_an_ad_without_a_link_reports_it_as_null(): void
    {
        // The API must not invent a destination: the frontend sanitises the link
        // and falls back to /spaces itself, and a substituted default here would
        // quietly bypass that decision.
        $this->publishedAd($this->owner(), ['link' => null]);

        $this->withToken($this->token($this->customer()))
            ->getJson('/api/ads/open')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.link', null);
    }

    public function test_an_empty_feed_is_returned_as_an_empty_array(): void
    {
        // {} would make the frontend treat the response as malformed and show
        // nothing at all, which is indistinguishable from a bug.
        $response = $this->withToken($this->token($this->customer()))
            ->getJson('/api/ads/open');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonCount(0, 'ads');

        // Asserted on the raw body: decoding turns both {} and [] into an empty
        // PHP array, so json() cannot tell the two apart.
        $this->assertStringContainsString('"data":[]', $response->getContent());
    }

    public function test_the_feed_is_capped_at_fifty_ads(): void
    {
        // The strip is a banner, not a browsable list. The cap keeps one request
        // from serialising an entire ads table into memory and onto the wire.
        $owner = $this->owner();

        for ($i = 0; $i < 55; $i++) {
            $this->publishedAd($owner, ['title' => 'إعلان ' . $i]);
        }

        $this->withToken($this->token($this->customer()))
            ->getJson('/api/ads/open')
            ->assertStatus(200)
            ->assertJsonCount(50, 'data');
    }

    // -------------------------------------------------------------------------
    // GET /api/ads/open — deleted owners and spaces
    // -------------------------------------------------------------------------

    public function test_the_fields_of_a_deleted_advertiser_are_null(): void
    {
        // User uses SoftDeletes, so `owner` is already null for a deleted
        // account. A missing null guard here would fatal the whole feed on a
        // single deleted advertiser.
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
        // Workspace imports SoftDeletes but never applies it, so a deleted space
        // is still eager loaded and its title would otherwise be published.
        // The row is flagged directly because without the trait a delete() would
        // be hard and the ads cascade would take the ad with it.
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
        // The banner tag carries the space name, which is why the relation is
        // eager loaded at all.
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
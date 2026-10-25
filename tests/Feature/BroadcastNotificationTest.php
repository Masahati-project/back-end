<?php

namespace Tests\Feature;

use App\Mail\BroadcastMail;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Admin broadcasts and the customer notification bell.
 *
 * The tests below cover the parts that are easy to regress: audience
 * resolution, per-user notification rows, email only when requested, and
 * per-user read scoping.
 */
class BroadcastNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return $user->createToken('auth_token')->plainTextToken;
    }

    /**
     * Sanctum's guard caches the resolved user for the lifetime of the app
     * instance, and the test HTTP kernel reuses that instance across calls.
     * Forget the guards whenever a test switches users, otherwise the second
     * token still resolves to the first user.
     */
    private function asUser(User $user): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token($user));
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'verified_at' => now(),
        ]);
    }

    private function customer(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'customer',
            'status' => 'active',
        ], $overrides));
    }

    private function owner(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'space_owner',
            'status' => 'active',
        ], $overrides));
    }

    public function test_a_broadcast_creates_in_app_notifications_for_customers_only(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $first = $this->customer();
        $second = $this->customer();
        $owner = $this->owner();

        $response = $this->asUser($admin)->postJson('/api/admin/broadcasts', [
            'title' => 'إعلان جديد',
            'body' => 'نص الإشعار',
            'target' => 'customers',
            'channels' => ['in_app'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.target', 'customers')
            ->assertJsonPath('data.recipients_count', 2)
            ->assertJsonPath('data.in_app_created_count', 2)
            ->assertJsonPath('data.email_queued_count', 0);

        $this->assertSame(2, Notification::where('type', 'broadcast')->count());
        $this->assertNotNull(Notification::where('user_id', $first->id)->first());
        $this->assertNotNull(Notification::where('user_id', $second->id)->first());
        $this->assertNull(Notification::where('user_id', $owner->id)->first());

        Mail::assertNothingQueued();
    }

    public function test_an_unknown_target_is_rejected(): void
    {
        $this->asUser($this->admin())
            ->postJson('/api/admin/broadcasts', [
                'title' => 'Test',
                'body' => 'Body',
                'target' => 'nobody',
                'channels' => ['in_app'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target');
    }

    public function test_an_empty_audience_is_rejected(): void
    {
        $this->asUser($this->admin())
            ->postJson('/api/admin/broadcasts', [
                'title' => 'Test',
                'body' => 'Body',
                'target' => 'customers',
                'channels' => ['in_app'],
            ])
            ->assertStatus(422);
    }

    public function test_email_channel_queues_one_mail_per_recipient(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $first = $this->customer();
        $second = $this->customer(['email' => '']);

        $response = $this->asUser($admin)->postJson('/api/admin/broadcasts', [
            'title' => 'إعلان',
            'body' => 'رسالة',
            'target' => 'customers',
            'channels' => ['email'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.recipients_count', 2)
            ->assertJsonPath('data.email_queued_count', 1)
            ->assertJsonPath('data.email_skipped_no_address_count', 1)
            ->assertJsonPath('data.in_app_created_count', 0);

        Mail::assertQueued(BroadcastMail::class, 1);
        Mail::assertQueued(BroadcastMail::class, fn (BroadcastMail $mail) => $mail->hasTo($first->email));
        $this->assertSame(0, Notification::count());
    }

    public function test_customers_only_see_their_own_notifications(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $first = $this->customer();
        $second = $this->customer();

        $this->asUser($admin)->postJson('/api/admin/broadcasts', [
            'title' => 'عنوان الإعلان',
            'body' => 'نص الإعلان',
            'target' => 'customers',
            'channels' => ['in_app'],
        ])->assertStatus(201);

        $response = $this->asUser($first)->getJson('/api/notifications');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data.notifications')
            ->assertJsonPath('data.notifications.0.title', 'عنوان الإعلان')
            ->assertJsonPath('data.notifications.0.body', 'نص الإعلان')
            ->assertJsonPath('data.notifications.0.read', false)
            ->assertJsonPath('data.notifications.0.type', 'broadcast');
        $this->assertNotNull($response->json('data.notifications.0.created_at'));

        $this->asUser($second)
            ->getJson('/api/notifications')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.notifications');

        $this->assertSame(2, Notification::count());
    }

    public function test_unread_count_reflects_only_the_authenticated_user(): void
    {
        $first = $this->customer();
        $second = $this->customer();

        Notification::create(['user_id' => $first->id, 'type' => 'test', 'message' => 'a', 'is_read' => false]);
        Notification::create(['user_id' => $first->id, 'type' => 'test', 'message' => 'b', 'is_read' => true]);
        Notification::create(['user_id' => $second->id, 'type' => 'test', 'message' => 'c', 'is_read' => false]);

        $this->asUser($first)
            ->getJson('/api/notifications/unread-count')
            ->assertStatus(200)
            ->assertJsonPath('data.unread_count', 1);
    }

    public function test_a_customer_can_mark_one_notification_as_read(): void
    {
        $first = $this->customer();
        $second = $this->customer();

        $own = Notification::create(['user_id' => $first->id, 'type' => 'test', 'message' => 'mine', 'is_read' => false]);
        $other = Notification::create(['user_id' => $second->id, 'type' => 'test', 'message' => 'not mine', 'is_read' => false]);

        $this->asUser($first)
            ->patchJson("/api/notifications/{$own->id}/read")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $own->id)
            ->assertJsonPath('data.read', true);

        $this->assertTrue((bool) $own->fresh()->is_read);

        $this->asUser($first)
            ->patchJson("/api/notifications/{$other->id}/read")
            ->assertStatus(404);

        $this->assertFalse((bool) $other->fresh()->is_read);
    }

    public function test_mark_all_as_read_only_touches_my_rows(): void
    {
        $first = $this->customer();
        $second = $this->customer();

        Notification::create(['user_id' => $first->id, 'type' => 'test', 'message' => 'a', 'is_read' => false]);
        Notification::create(['user_id' => $first->id, 'type' => 'test', 'message' => 'b', 'is_read' => false]);
        $other = Notification::create(['user_id' => $second->id, 'type' => 'test', 'message' => 'c', 'is_read' => false]);

        $this->asUser($first)
            ->postJson('/api/notifications/read')
            ->assertStatus(200);

        $this->assertSame(0, Notification::where('user_id', $first->id)->where('is_read', false)->count());
        $this->assertFalse((bool) $other->fresh()->is_read);
    }

    public function test_admin_can_read_broadcast_delivery_details(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $this->customer();

        $send = $this->asUser($admin)->postJson('/api/admin/broadcasts', [
            'title' => 'تفاصيل',
            'body' => 'جسم',
            'target' => 'customers',
            'channels' => ['in_app', 'email'],
        ])->assertStatus(201);

        $id = $send->json('data.id');

        $this->asUser($admin)
            ->getJson("/api/admin/broadcasts/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.recipients_count', 1)
            ->assertJsonPath('data.in_app_created_count', 1)
            ->assertJsonPath('data.email_queued_count', 1);
    }

    public function test_admin_can_list_recipients_with_masked_email(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $customer = $this->customer(['email' => 'customer@example.com']);

        $send = $this->asUser($admin)->postJson('/api/admin/broadcasts', [
            'title' => 'Recipients',
            'body' => 'Body',
            'target' => 'customers',
            'channels' => ['in_app', 'email'],
        ])->assertStatus(201);

        $id = $send->json('data.id');

        $response = $this->asUser($admin)
            ->getJson("/api/admin/broadcasts/{$id}/recipients")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.in_app.status', 'created')
            ->assertJsonPath('data.0.email_status', 'sent')
            ->assertJsonPath('data.0.email_delivery.status', 'sent');

        $emails = collect($response->json('data'))->pluck('email')->filter()->unique()->values();
        $this->assertSame(['c***@example.com'], $emails->all());
        $this->assertStringNotContainsString('customer@example.com', $response->getContent());

        $this->assertDatabaseHas('broadcast_recipients', [
            'broadcast_id' => $id,
            'user_id' => $customer->id,
            'channel' => 'in_app',
            'status' => 'created',
        ]);
    }

    public function test_test_send_only_notifies_the_requesting_admin(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $customer = $this->customer();

        $this->asUser($admin)
            ->postJson('/api/admin/broadcasts/test', [
                'title' => 'Test',
                'body' => 'Test body',
                'channels' => ['in_app', 'email'],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.in_app', true)
            ->assertJsonPath('data.email_status', 'queued');

        $this->assertSame(1, Notification::where('user_id', $admin->id)->count());
        $this->assertSame(0, Notification::where('user_id', $customer->id)->count());
        Mail::assertQueued(BroadcastMail::class, 1);
        Mail::assertQueued(BroadcastMail::class, fn (BroadcastMail $mail) => $mail->hasTo($admin->email));
    }

    public function test_retry_failed_only_resends_failed_email_recipients(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $failed = $this->customer();
        $succeeded = $this->customer();

        $broadcast = Broadcast::create([
            'title' => 'Retry',
            'body' => 'Body',
            'target' => 'customers',
            'channels' => ['email'],
            'total' => 2,
            'recipients_count' => 2,
            'email_failed_count' => 1,
            'sent_by' => $admin->id,
            'sent_at' => now(),
        ]);

        BroadcastRecipient::create([
            'broadcast_id' => $broadcast->id,
            'user_id' => $failed->id,
            'channel' => 'email',
            'status' => 'failed',
            'attempts' => 1,
            'error' => 'SMTP failure',
        ]);
        BroadcastRecipient::create([
            'broadcast_id' => $broadcast->id,
            'user_id' => $succeeded->id,
            'channel' => 'email',
            'status' => 'sent',
            'attempts' => 1,
        ]);

        $this->asUser($admin)
            ->postJson("/api/admin/broadcasts/{$broadcast->id}/retry-failed")
            ->assertStatus(200)
            ->assertJsonPath('data.retried', 1)
            ->assertJsonPath('data.email_failed_count', 0);

        Mail::assertQueued(BroadcastMail::class, 1);
        Mail::assertQueued(BroadcastMail::class, fn (BroadcastMail $mail) => $mail->hasTo($failed->email));

        $this->assertSame('sent', BroadcastRecipient::where('user_id', $failed->id)->first()->status);
    }

    public function test_audience_counts_match_the_send_audience(): void
    {
        $admin = $this->admin();
        $this->customer();
        $this->customer();
        $this->owner();
        $this->customer(['status' => 'suspended']);

        $this->asUser($admin)
            ->getJson('/api/admin/broadcasts/audience-counts')
            ->assertStatus(200)
            ->assertJsonPath('data.customers', 2)
            ->assertJsonPath('data.owners', 1)
            ->assertJsonPath('data.all', 3)
            ->assertJsonPath('data.freelancers', 0);
    }

    public function test_notifications_can_be_polled_after_an_id(): void
    {
        $user = $this->customer();
        $first = Notification::create(['user_id' => $user->id, 'type' => 'test', 'message' => 'first', 'is_read' => false]);
        $second = Notification::create(['user_id' => $user->id, 'type' => 'test', 'message' => 'second', 'is_read' => false]);

        $this->asUser($user)
            ->getJson("/api/notifications?after_id={$first->id}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.notifications')
            ->assertJsonPath('data.notifications.0.id', $second->id);
    }

    public function test_broadcast_index_is_flat_and_reports_recipient_counts(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $this->customer();

        $this->asUser($admin)->postJson('/api/admin/broadcasts', [
            'title' => 'Indexed',
            'body' => 'Body',
            'target' => 'customers',
            'channels' => ['in_app'],
        ])->assertStatus(201);

        $this->asUser($admin)
            ->getJson('/api/admin/broadcasts')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Indexed')
            ->assertJsonPath('data.0.recipients_count', 1)
            ->assertJsonPath('data.0.sent_by', 'admin')
            ->assertJsonPath('meta.current_page', 1);
    }

    public function test_resend_does_not_duplicate_in_app_notifications(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $this->customer();

        $send = $this->asUser($admin)->postJson('/api/admin/broadcasts', [
            'title' => 'Resend',
            'body' => 'Body',
            'target' => 'customers',
            'channels' => ['in_app'],
        ])->assertStatus(201);

        $this->asUser($admin)
            ->postJson("/api/admin/broadcasts/{$send->json('data.id')}/resend")
            ->assertStatus(200)
            ->assertJsonPath('data.recipients_count', 1)
            ->assertJsonPath('data.in_app_created_count', 1);

        $this->assertSame(1, Notification::where('type', 'broadcast')->count());
    }

    public function test_drafts_are_scoped_to_the_admin_who_created_them(): void
    {
        $firstAdmin = $this->admin();
        $secondAdmin = $this->admin();

        $draft = $this->asUser($firstAdmin)->postJson('/api/admin/broadcasts/drafts', [
            'title' => 'Draft',
            'body' => 'Draft body',
            'target' => 'customers',
            'channels' => ['in_app'],
        ])->assertStatus(201)->json('data');

        $this->asUser($secondAdmin)
            ->getJson('/api/admin/broadcasts/drafts')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->asUser($secondAdmin)
            ->putJson("/api/admin/broadcasts/drafts/{$draft['id']}", [
                'title' => 'Hijacked',
                'target' => 'customers',
            ])
            ->assertStatus(404);

        $this->asUser($secondAdmin)
            ->deleteJson("/api/admin/broadcasts/drafts/{$draft['id']}")
            ->assertStatus(404);

        $this->asUser($firstAdmin)
            ->getJson('/api/admin/broadcasts/drafts')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Draft')
            ->assertJsonStructure(['data' => [['id', 'title', 'body', 'target', 'link', 'channels', 'created_at', 'updated_at']]]);
    }
}

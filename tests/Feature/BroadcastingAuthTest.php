<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /api/broadcasting/auth — the private-channel handshake.
 *
 * Pusher-js posts {socket_id, channel_name} with the Sanctum token. Only the
 * caller's own `private-owner.{id}` / `private-user.{id}` may be authorized;
 * every other channel — including the same prefix for another user — is a 403.
 */
class BroadcastingAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
        ]);
    }

    private function token(User $user): string
    {
        return $user->createToken('auth_token')->plainTextToken;
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'space_owner']);
    }

    private function payload(string $channel): array
    {
        return ['socket_id' => '123.456', 'channel_name' => $channel];
    }

    public function test_an_owner_may_join_their_own_owner_channel(): void
    {
        $owner = $this->owner();

        $response = $this->withToken($this->token($owner))
            ->postJson('/api/broadcasting/auth', $this->payload('private-owner.'.$owner->id));

        $response->assertStatus(200)
            ->assertJsonStructure(['auth']);

        // Pusher protocol: "{key}:{hmac(socket_id:channel)}".
        $this->assertSame(
            'test-key:'.hash_hmac('sha256', '123.456:private-owner.'.$owner->id, 'test-secret'),
            $response->json('auth')
        );
    }

    public function test_a_user_may_join_their_own_user_channel(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $this->withToken($this->token($customer))
            ->postJson('/api/broadcasting/auth', $this->payload('private-user.'.$customer->id))
            ->assertStatus(200)
            ->assertJsonStructure(['auth']);
    }

    public function test_a_user_cannot_join_another_users_channel(): void
    {
        $owner = $this->owner();
        $other = $this->owner();

        $this->withToken($this->token($owner))
            ->postJson('/api/broadcasting/auth', $this->payload('private-owner.'.$other->id))
            ->assertStatus(403);

        $this->withToken($this->token($owner))
            ->postJson('/api/broadcasting/auth', $this->payload('private-user.'.$other->id))
            ->assertStatus(403);
    }

    public function test_an_unknown_channel_is_forbidden(): void
    {
        $owner = $this->owner();

        foreach (['private-owner', 'orders.1', 'presence-room.1', 'private-owner.1.2'] as $channel) {
            $this->withToken($this->token($owner))
                ->postJson('/api/broadcasting/auth', $this->payload($channel))
                ->assertStatus(403);
        }
    }

    public function test_broadcasting_auth_requires_authentication(): void
    {
        $this->postJson('/api/broadcasting/auth', $this->payload('private-owner.1'))
            ->assertStatus(401);
    }

    public function test_the_channel_name_is_required(): void
    {
        $this->withToken($this->token($this->owner()))
            ->postJson('/api/broadcasting/auth', ['socket_id' => '123.456'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('channel_name');
    }

    public function test_an_unconfigured_broadcaster_is_a_503_not_a_403(): void
    {
        config([
            'broadcasting.connections.pusher.key' => null,
            'broadcasting.connections.pusher.secret' => null,
        ]);

        $owner = $this->owner();

        $this->withToken($this->token($owner))
            ->postJson('/api/broadcasting/auth', $this->payload('private-owner.'.$owner->id))
            ->assertStatus(503);
    }
}

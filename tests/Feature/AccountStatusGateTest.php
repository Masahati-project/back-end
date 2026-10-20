<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountStatusGateTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(array $overrides): string
    {
        $user = User::factory()->create($overrides);

        return $user->createToken('auth_token')->plainTextToken;
    }

    public function test_suspended_user_cannot_reach_protected_routes(): void
    {
        $token = $this->tokenFor(['status' => 'suspended']);

        $this->withToken($token)->getJson('/api/dashboard/stats')
            ->assertStatus(403)
            ->assertJson(['status' => 'suspended']);

        $this->withToken($token)->patchJson('/api/customer/profile', [])->assertStatus(403);
        $this->withToken($token)->postJson('/api/bookings', [])->assertStatus(403);
    }

    public function test_pending_user_cannot_reach_protected_routes(): void
    {
        $token = $this->tokenFor(['status' => 'pending']);

        $this->withToken($token)->getJson('/api/dashboard/favorites')
            ->assertStatus(403)
            ->assertJson(['status' => 'pending']);

        $this->withToken($token)->getJson('/api/dashboard/bookings')->assertStatus(403);
    }

    public function test_profile_reports_the_status_so_the_app_can_explain_the_block(): void
    {
        $token = $this->tokenFor(['status' => 'pending']);

        $this->withToken($token)->getJson('/api/profile')
            ->assertStatus(200)
            ->assertJsonPath('status', 'pending');
    }

    public function test_pending_owner_cannot_create_or_use_spaces(): void
    {
        $token = $this->tokenFor(['status' => 'pending', 'role' => 'space_owner']);

        $this->withToken($token)->getJson('/api/owner/spaces')->assertStatus(403);
        $this->withToken($token)->postJson('/api/owner/spaces', [])->assertStatus(403);
        $this->withToken($token)->getJson('/api/owner/bookings')->assertStatus(403);
        $this->withToken($token)->getJson('/api/special-requests/open')->assertStatus(403);
    }

    public function test_active_user_still_reaches_protected_routes(): void
    {
        $token = $this->tokenFor(['status' => 'active']);

        $this->withToken($token)->getJson('/api/profile')->assertStatus(200);
    }

    public function test_blocked_user_can_still_log_out(): void
    {
        $user = User::factory()->create(['status' => 'suspended']);
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withToken($token)->postJson('/api/logout')->assertStatus(200);
    }

    /**
     * Browsing is public, so a pending or suspended account keeps the
     * catalogue. Blocking it here would leave the app's home screen stuck on
     * 403s even though an anonymous visitor sees the very same page.
     */
    public function test_blocked_user_can_still_browse_spaces(): void
    {
        foreach (['pending', 'suspended'] as $status) {
            $token = $this->tokenFor(['status' => $status]);

            $this->withToken($token)->getJson('/api/spaces')->assertStatus(200);
            $this->withToken($token)->getJson('/api/dashboard/spaces')->assertStatus(200);
            $this->withToken($token)->getJson('/api/notifications')->assertStatus(200);
        }
    }

    public function test_pending_and_suspended_users_cannot_log_in(): void
    {
        foreach (['pending', 'suspended'] as $status) {
            $user = User::factory()->create(['status' => $status]);

            $this->postJson('/api/login', [
                'login' => $user->email,
                'password' => 'password',
            ])
                ->assertStatus(203)
                ->assertJson(['status' => $status]);

            // Auth::attempt() logs the user into the web guard even when we
            // reject the login; clear it so the second loop is not redirected
            // by guest:sanctum before reaching the controller.
            $this->app['auth']->forgetGuards();
            $this->flushSession();
        }
    }

    public function test_suspended_admin_is_not_locked_out_of_the_panel(): void
    {
        $token = $this->tokenFor(['status' => 'suspended', 'role' => 'admin']);

        $this->withToken($token)->getJson('/api/admin/me')->assertStatus(200);
    }

    public function test_suspending_a_user_takes_effect_on_an_already_issued_token(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withToken($token)->getJson('/api/dashboard/stats')->assertStatus(200);

        $user->forceFill(['status' => 'suspended'])->save();

        // A real request boots a fresh auth guard; the test client keeps the
        // one that resolved the user before the status change.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/dashboard/stats')
            ->assertStatus(403)
            ->assertJson(['status' => 'suspended']);
    }
}

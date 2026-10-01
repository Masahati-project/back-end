<?php

namespace Tests\Feature;

use App\Models\Favorite;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavoriteToggleTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorkspace(string $title = 'مساحة الاختبار'): Workspace
    {
        $owner = User::factory()->create(['role' => 'space_owner']);

        return Workspace::create([
            'owner_id' => $owner->id,
            'title' => $title,
            'space_document_url' => 'documents/space.pdf',
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

    public function test_first_toggle_adds_to_favorites(): void
    {
        $user = User::factory()->create();
        $workspace = $this->makeWorkspace();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/dashboard/favorites/toggle', [
            'space_id' => $workspace->id,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'تمت الإضافة للمفضلة',
                'is_favorited' => true,
            ]);

        $this->assertDatabaseCount('favorites', 1);
        $this->assertDatabaseHas('favorites', [
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
        ]);

        $favorite = Favorite::first();
        $this->assertNotNull($favorite->created_at);
        $this->assertNotNull($favorite->updated_at);
    }

    public function test_second_toggle_removes_from_favorites(): void
    {
        $user = User::factory()->create();
        $workspace = $this->makeWorkspace();

        $this->actingAs($user, 'sanctum')->postJson('/api/dashboard/favorites/toggle', [
            'space_id' => $workspace->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/dashboard/favorites/toggle', [
            'space_id' => $workspace->id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'تمت الإزالة من المفضلة',
                'is_favorited' => false,
            ]);

        $this->assertDatabaseCount('favorites', 0);
    }

    public function test_third_toggle_adds_again_round_trip(): void
    {
        $user = User::factory()->create();
        $workspace = $this->makeWorkspace();

        $this->actingAs($user, 'sanctum')->postJson('/api/dashboard/favorites/toggle', ['space_id' => $workspace->id]);
        $this->actingAs($user, 'sanctum')->postJson('/api/dashboard/favorites/toggle', ['space_id' => $workspace->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/dashboard/favorites/toggle', [
            'space_id' => $workspace->id,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'تمت الإضافة للمفضلة',
                'is_favorited' => true,
            ]);

        $this->assertDatabaseCount('favorites', 1);
    }

    public function test_scoping_user_b_has_space_favorited_user_a_toggles_must_add_for_a_and_leave_b_untouched(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $workspace = $this->makeWorkspace();

        $this->actingAs($userB, 'sanctum')->postJson('/api/dashboard/favorites/toggle', [
            'space_id' => $workspace->id,
        ]);

        $response = $this->actingAs($userA, 'sanctum')->postJson('/api/dashboard/favorites/toggle', [
            'space_id' => $workspace->id,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'تمت الإضافة للمفضلة',
                'is_favorited' => true,
            ]);

        $this->assertDatabaseCount('favorites', 2);
        $this->assertDatabaseHas('favorites', [
            'user_id' => $userA->id,
            'workspace_id' => $workspace->id,
        ]);
        $this->assertDatabaseHas('favorites', [
            'user_id' => $userB->id,
            'workspace_id' => $workspace->id,
        ]);
    }

    public function test_validation_missing_space_id_returns_422(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/dashboard/favorites/toggle', []);

        $response->assertStatus(422);
    }

    public function test_validation_non_existent_space_id_returns_422(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/dashboard/favorites/toggle', [
            'space_id' => 999999,
        ]);

        $response->assertStatus(422);
    }

    public function test_get_favorites_lists_workspace_after_favoriting(): void
    {
        $user = User::factory()->create();
        $workspace = $this->makeWorkspace('Test Space');

        $this->actingAs($user, 'sanctum')->postJson('/api/dashboard/favorites/toggle', [
            'space_id' => $workspace->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/dashboard/favorites');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.space_id', $workspace->id);
        $response->assertJsonPath('data.0.title', $workspace->title);
    }
}

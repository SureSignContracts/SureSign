<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SuresignNotification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TourMilestoneControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        $org  = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        return $user;
    }

    public function test_first_tour_milestone_creates_a_personal_notification(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/tour-milestones', ['milestone' => 'first_tour']);

        $response->assertOk();
        $this->assertDatabaseCount('suresign_notifications', 1);
        $notification = SuresignNotification::first();
        $this->assertSame($user->id, $notification->user_id);
        $this->assertSame(NotificationService::TOUR_MILESTONE_FIRST, $notification->type);
        $this->assertSame('/app/help/tours', $notification->action_url);
    }

    public function test_invalid_milestone_is_rejected(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/tour-milestones', ['milestone' => 'not_a_real_milestone']);

        $response->assertStatus(422);
        $this->assertDatabaseCount('suresign_notifications', 0);
    }

    public function test_milestone_is_idempotent_and_never_duplicates(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/tour-milestones', ['milestone' => 'all_tours_complete'])->assertOk();
        $this->postJson('/api/tour-milestones', ['milestone' => 'all_tours_complete'])->assertOk();

        $this->assertDatabaseCount('suresign_notifications', 1);
    }

    public function test_milestone_notification_is_personal_only_never_seen_by_another_user(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();

        Sanctum::actingAs($userA);
        $this->postJson('/api/tour-milestones', ['milestone' => 'getting_started_complete'])->assertOk();

        $this->assertSame(1, SuresignNotification::where('user_id', $userA->id)->count());
        $this->assertSame(0, SuresignNotification::where('user_id', $userB->id)->count());
    }

    public function test_all_three_milestones_can_be_tracked_independently_for_the_same_user(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/tour-milestones', ['milestone' => 'first_tour'])->assertOk();
        $this->postJson('/api/tour-milestones', ['milestone' => 'getting_started_complete'])->assertOk();
        $this->postJson('/api/tour-milestones', ['milestone' => 'all_tours_complete'])->assertOk();

        $this->assertDatabaseCount('suresign_notifications', 3);
    }
}

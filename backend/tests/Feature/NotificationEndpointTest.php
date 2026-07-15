<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SuresignNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression coverage for GET /notifications, which previously used a
 * MySQL-only orderByRaw("FIELD(...))") and 500d under the sqlite test
 * driver — meaning this endpoint had zero test coverage. Now uses a
 * portable CASE expression (see NotificationController::index).
 */
class NotificationEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgAndUser(string $label): array
    {
        static $n = 0;
        $n++;

        $org = Organization::create(['name' => "{$label} Org {$n}", 'slug' => "org-{$label}-{$n}"]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        return compact('org', 'user');
    }

    private function makeNotification(User $user, array $overrides = []): SuresignNotification
    {
        return SuresignNotification::create(array_merge([
            'user_id'  => $user->id,
            'type'     => 'file_uploaded',
            'title'    => 'Notification',
            'message'  => 'A notification',
            'status'   => 'unread',
            'priority' => 'info',
        ], $overrides));
    }

    public function test_notifications_endpoint_returns_200_under_the_test_database(): void
    {
        $a = $this->makeOrgAndUser('a');
        $this->makeNotification($a['user']);
        Sanctum::actingAs($a['user']);

        $response = $this->getJson('/api/notifications');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'total', 'per_page', 'unread_count', 'current_page', 'last_page']);
    }

    public function test_notifications_are_ordered_critical_first_then_by_recency(): void
    {
        $a = $this->makeOrgAndUser('a');

        $info     = $this->makeNotification($a['user'], ['title' => 'Info',     'priority' => 'info']);
        $warning  = $this->makeNotification($a['user'], ['title' => 'Warning',  'priority' => 'warning']);
        $critical = $this->makeNotification($a['user'], ['title' => 'Critical', 'priority' => 'critical']);
        $reminder = $this->makeNotification($a['user'], ['title' => 'Reminder', 'priority' => 'reminder']);

        Sanctum::actingAs($a['user']);

        $response = $this->getJson('/api/notifications?filter=all');
        $response->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title')->values()->all();

        // Priority order (critical, warning, reminder, info) must win over
        // insertion/created_at order — 'Critical' was created last but must
        // still sort first.
        $this->assertEquals(['Critical', 'Warning', 'Reminder', 'Info'], $titles);
    }

    public function test_notifications_within_the_same_priority_are_ordered_most_recent_first(): void
    {
        $a = $this->makeOrgAndUser('a');

        $older = $this->makeNotification($a['user'], ['title' => 'Older', 'priority' => 'info']);
        $older->forceFill(['created_at' => now()->subDay()])->save();

        $newer = $this->makeNotification($a['user'], ['title' => 'Newer', 'priority' => 'info']);
        $newer->forceFill(['created_at' => now()])->save();

        Sanctum::actingAs($a['user']);

        $response = $this->getJson('/api/notifications?filter=all');
        $titles = collect($response->json('data'))->pluck('title')->values()->all();

        $this->assertEquals(['Newer', 'Older'], $titles);
    }

    public function test_notifications_list_is_scoped_to_the_authenticated_user_only(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');

        $this->makeNotification($a['user'], ['title' => 'Mine']);
        $this->makeNotification($b['user'], ['title' => 'Not mine']);

        Sanctum::actingAs($a['user']);

        $response = $this->getJson('/api/notifications?filter=all');
        $titles = collect($response->json('data'))->pluck('title');

        $this->assertTrue($titles->contains('Mine'));
        $this->assertFalse($titles->contains('Not mine'));
    }

    public function test_client_cannot_mark_read_dismiss_or_clear_another_users_notification(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');

        $notification = $this->makeNotification($b['user']);

        Sanctum::actingAs($a['user']);

        $this->patchJson("/api/notifications/{$notification->id}/read")->assertStatus(403);
        $this->patchJson("/api/notifications/{$notification->id}/dismiss")->assertStatus(403);
        $this->deleteJson("/api/notifications/{$notification->id}")->assertStatus(403);

        $this->assertDatabaseHas('suresign_notifications', ['id' => $notification->id, 'status' => 'unread']);
    }

    public function test_client_can_mark_read_and_clear_their_own_notification(): void
    {
        $a = $this->makeOrgAndUser('a');
        $notification = $this->makeNotification($a['user']);

        Sanctum::actingAs($a['user']);

        $this->patchJson("/api/notifications/{$notification->id}/read")->assertStatus(200);
        $this->assertDatabaseHas('suresign_notifications', ['id' => $notification->id, 'status' => 'read']);

        $this->deleteJson("/api/notifications/{$notification->id}")->assertStatus(200);
        $this->assertDatabaseMissing('suresign_notifications', ['id' => $notification->id]);
    }

    public function test_unread_count_reflects_only_the_authenticated_users_unread_notifications(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');

        $this->makeNotification($a['user'], ['status' => 'unread']);
        $this->makeNotification($a['user'], ['status' => 'read']);
        $this->makeNotification($b['user'], ['status' => 'unread']);

        Sanctum::actingAs($a['user']);

        $response = $this->getJson('/api/notifications/unread-count');
        $response->assertStatus(200);
        $response->assertJson(['count' => 1]);
    }
}

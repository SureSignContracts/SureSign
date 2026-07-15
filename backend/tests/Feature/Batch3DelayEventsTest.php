<?php

namespace Tests\Feature;

use App\Models\DelayEvent;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TradePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batch 3: Delay Events.
 *
 * Found and fixed two parent-mismatch gaps: show/update/destroy/
 * generateNotice checked the delay event's own organisation but not that
 * it belongs to the {project} in the URL, and indexByTradePackage/
 * storeForTradePackage checked the trade package's organisation but not
 * that it belongs to the {project} in the URL. Same pattern as Meetings
 * and Site Reports.
 */
class Batch3DelayEventsTest extends TestCase
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

    private function makeProject(Organization $org, User $user): Project
    {
        return Project::create([
            'organization_id' => $org->id,
            'created_by'      => $user->id,
            'name'            => "Project for {$org->name}",
            'status'          => 'active',
        ]);
    }

    private function makeTradePackage(Project $project, User $user): TradePackage
    {
        static $n = 0;
        $n++;

        return TradePackage::create([
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $user->id,
            'name'            => 'Groundworks',
            'slug'            => "groundworks-de-{$n}",
            'status'          => 'active',
        ]);
    }

    private function makeDelayEvent(Project $project, User $user, array $overrides = []): DelayEvent
    {
        static $n = 0;
        $n++;

        return DelayEvent::create(array_merge([
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $user->id,
            'event_number'    => $n,
            'title'           => 'Unforeseen ground conditions',
            'date_occurred'   => now()->toDateString(),
            'status'          => 'open',
        ], $overrides));
    }

    // ── Positive ──────────────────────────────────────────────────────────

    public function test_client_can_create_edit_and_delete_a_delay_event_in_their_own_project(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $store = $this->postJson("/api/projects/{$project->id}/delay-events", [
            'title' => 'Unforeseen ground conditions', 'date_occurred' => now()->toDateString(),
        ]);
        $store->assertStatus(201);
        $id = $store->json('id');

        $update = $this->putJson("/api/projects/{$project->id}/delay-events/{$id}", ['status' => 'under_assessment']);
        $update->assertStatus(200);
        $this->assertDatabaseHas('delay_events', ['id' => $id, 'status' => 'under_assessment']);

        $this->deleteJson("/api/projects/{$project->id}/delay-events/{$id}")->assertStatus(204);
        $this->assertSoftDeleted('delay_events', ['id' => $id]);
    }

    public function test_client_can_manage_trade_package_scoped_delay_events(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $tp = $this->makeTradePackage($project, $a['user']);
        Sanctum::actingAs($a['user']);

        $this->getJson("/api/projects/{$project->id}/trade-packages/{$tp->id}/delay-events")->assertStatus(200);

        $response = $this->postJson("/api/projects/{$project->id}/trade-packages/{$tp->id}/delay-events", [
            'title' => 'Late subcontractor mobilisation', 'date_occurred' => now()->toDateString(),
        ]);
        $response->assertStatus(201);
        $this->assertDatabaseHas('delay_events', ['trade_package_id' => $tp->id, 'title' => 'Late subcontractor mobilisation']);
    }

    // ── Negative: cross-tenant ────────────────────────────────────────────

    public function test_client_cannot_view_edit_or_delete_another_organisations_delay_event(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $eventB = $this->makeDelayEvent($projectB, $b['user']);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/projects/{$projectB->id}/delay-events/{$eventB->id}")->assertStatus(403);
        $this->putJson("/api/projects/{$projectB->id}/delay-events/{$eventB->id}", ['title' => 'Hijacked'])->assertStatus(403);
        $this->deleteJson("/api/projects/{$projectB->id}/delay-events/{$eventB->id}")->assertStatus(403);
    }

    public function test_client_cannot_address_a_delay_event_using_a_mismatched_same_organisation_project_id(): void
    {
        $a = $this->makeOrgAndUser('a');
        $projectOne = $this->makeProject($a['org'], $a['user']);
        $projectTwo = $this->makeProject($a['org'], $a['user']);
        $event = $this->makeDelayEvent($projectOne, $a['user']);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/projects/{$projectTwo->id}/delay-events/{$event->id}")->assertStatus(404);
    }

    public function test_client_cannot_access_another_organisations_trade_package_delay_events_or_spoof_parent(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectA = $this->makeProject($a['org'], $a['user']);
        $projectB = $this->makeProject($b['org'], $b['user']);
        $tpB = $this->makeTradePackage($projectB, $b['user']);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/projects/{$projectB->id}/trade-packages/{$tpB->id}/delay-events")->assertStatus(403);
        // Same-org (A) but the trade package actually belongs to Org B's project — still blocked, via the org check first.
        $this->getJson("/api/projects/{$projectA->id}/trade-packages/{$tpB->id}/delay-events")->assertStatus(403);
    }
}

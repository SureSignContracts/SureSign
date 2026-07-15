<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractProgrammeMilestone;
use App\Models\ContractRisk;
use App\Models\DelayEvent;
use App\Models\EotRequest;
use App\Models\LossAndExpenseClaim;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SuresignNotification;
use App\Models\User;
use App\Services\NotificationEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Batch 2: Programme Milestones, Delay Events, EOT Requests, Loss & Expense
 * Claims, Risks. All in-app only except EOT/L&E decisions (in-app + email).
 */
class NotificationBatch2ProgrammeRiskTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgAndClient(string $label): array
    {
        static $n = 0;
        $n++;

        $org   = Organization::create(['name' => "{$label} Org {$n}", 'slug' => "org-{$label}-{$n}"]);
        $user  = User::factory()->create(['organization_id' => $org->id]);
        $other = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        $other->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        return compact('org', 'user', 'other');
    }

    private function makeProject(Organization $org, User $user): Project
    {
        return Project::create([
            'organization_id' => $org->id, 'created_by' => $user->id,
            'name' => "Project for {$org->name}", 'status' => 'active',
        ]);
    }

    private function makeContract(Project $project, User $user): Contract
    {
        return Contract::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'created_by' => $user->id, 'title' => 'Main Contract', 'type' => 'main_contract',
            'status' => 'active', 'retention_percentage' => 5,
        ]);
    }

    private function enableBrevo(array $enabledEvents): void
    {
        \App\Models\SuresignSetting::instance()->update([
            'brevo_api_key' => 'fake-brevo-key', 'notification_settings' => $enabledEvents,
        ]);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'test'], 201)]);
    }

    // ── Programme Milestone ──────────────────────────────────────────────

    public function test_programme_milestone_status_change_notifies_but_a_date_only_edit_does_not(): void
    {
        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $milestone = ContractProgrammeMilestone::create([
            'contract_id' => $contract->id, 'project_id' => $project->id,
            'name' => 'Practical Completion', 'milestone_type' => 'completion', 'status' => 'not_started',
        ]);

        Sanctum::actingAs($a['user']);

        // Date-only edit — no notification.
        $this->putJson("/api/programme/{$milestone->id}", ['planned_date' => now()->addDays(10)->toDateString()])
            ->assertStatus(200);
        $this->assertDatabaseMissing('suresign_notifications', ['source_type' => 'programme_milestone', 'source_id' => $milestone->id]);

        // Status change — notifies the other Client user, not the actor.
        $this->putJson("/api/programme/{$milestone->id}", ['status' => 'complete'])->assertStatus(200);
        $this->assertDatabaseHas('suresign_notifications', [
            'user_id' => $a['other']->id, 'source_type' => 'programme_milestone', 'source_id' => $milestone->id,
        ]);
        $this->assertDatabaseMissing('suresign_notifications', [
            'user_id' => $a['user']->id, 'source_type' => 'programme_milestone', 'source_id' => $milestone->id,
        ]);
    }

    public function test_programme_milestone_cycling_through_the_same_status_twice_notifies_both_times(): void
    {
        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $milestone = ContractProgrammeMilestone::create([
            'contract_id' => $contract->id, 'project_id' => $project->id,
            'name' => 'M1', 'milestone_type' => 'other', 'status' => 'complete',
        ]);

        Sanctum::actingAs($a['user']);
        $this->putJson("/api/programme/{$milestone->id}", ['status' => 'delayed'])->assertStatus(200);
        $this->putJson("/api/programme/{$milestone->id}", ['status' => 'complete'])->assertStatus(200);

        $this->assertEquals(
            2,
            SuresignNotification::where('source_type', 'programme_milestone')->where('source_id', $milestone->id)->count()
        );
    }

    // ── Delay Event ───────────────────────────────────────────────────────

    public function test_delay_event_created_and_status_change_both_notify(): void
    {
        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $store = $this->postJson("/api/projects/{$project->id}/delay-events", [
            'title' => 'Weather delay', 'date_occurred' => now()->toDateString(),
        ]);
        $store->assertStatus(201);
        $id = $store->json('id');

        $this->assertTrue(
            SuresignNotification::where('user_id', $a['other']->id)
                ->where('source_type', 'delay_event')->where('source_id', $id)
                ->where('source_field', 'like', 'raised%')->exists()
        );

        $this->putJson("/api/projects/{$project->id}/delay-events/{$id}", ['status' => 'closed'])->assertStatus(200);
        $this->assertDatabaseHas('suresign_notifications', [
            'user_id' => $a['other']->id, 'source_type' => 'delay_event', 'source_id' => $id,
        ]);
        $this->assertEquals(
            2,
            SuresignNotification::where('source_type', 'delay_event')->where('source_id', $id)->count()
        );
    }

    // ── EOT Request ───────────────────────────────────────────────────────

    public function test_eot_submitted_is_in_app_only_but_decision_is_in_app_plus_email(): void
    {
        $this->enableBrevo(['eot.decided']);

        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $store = $this->postJson("/api/projects/{$project->id}/eot-requests", [
            'title' => 'EOT for weather delay', 'notice_date' => now()->toDateString(), 'status' => 'submitted',
        ]);
        $store->assertStatus(201);
        $id = $store->json('id');

        $this->assertDatabaseHas('suresign_notifications', [
            'source_type' => 'eot_request', 'source_id' => $id, 'source_field' => 'submitted',
            'action_url'  => "/app/projects/{$project->id}/delay-eot?tab=eot",
        ]);
        Http::assertNothingSent(); // submission itself must not email

        $this->postJson("/api/projects/{$project->id}/eot-requests/{$id}/decide", ['status' => 'granted', 'days_granted' => 5])
            ->assertStatus(200);

        $this->assertTrue(
            SuresignNotification::where('source_type', 'eot_request')->where('source_id', $id)
                ->where('source_field', 'like', 'decided_granted%')->exists()
        );
        Http::assertSentCount(1); // only the decision emails
    }

    public function test_eot_draft_creation_does_not_notify(): void
    {
        $a       = $this->makeOrgAndClient('a');
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $store = $this->postJson("/api/projects/{$project->id}/eot-requests", [
            'title' => 'Draft EOT', 'notice_date' => now()->toDateString(), 'status' => 'draft',
        ]);
        $store->assertStatus(201);

        $this->assertDatabaseMissing('suresign_notifications', ['source_type' => 'eot_request', 'source_id' => $store->json('id')]);
    }

    // ── Loss & Expense Claim ──────────────────────────────────────────────

    public function test_loss_and_expense_submitted_is_in_app_only_but_decision_is_in_app_plus_email(): void
    {
        $this->enableBrevo(['loss_and_expense.decided']);

        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $store = $this->postJson("/api/projects/{$project->id}/loss-and-expense-claims", [
            'title' => 'Prolongation costs', 'status' => 'submitted',
        ]);
        $store->assertStatus(201);
        $id = $store->json('id');

        $this->assertDatabaseHas('suresign_notifications', [
            'source_type' => 'loss_and_expense_claim', 'source_id' => $id, 'source_field' => 'submitted',
        ]);
        Http::assertNothingSent();

        $this->postJson("/api/projects/{$project->id}/loss-and-expense-claims/{$id}/decide", ['status' => 'agreed', 'amount_agreed' => 2500])
            ->assertStatus(200);

        $this->assertTrue(
            SuresignNotification::where('source_type', 'loss_and_expense_claim')->where('source_id', $id)
                ->where('source_field', 'like', 'decided_agreed%')->exists()
        );
        Http::assertSentCount(1);
    }

    // ── Risk ──────────────────────────────────────────────────────────────

    public function test_risk_created_and_severity_change_both_notify_but_description_edit_does_not(): void
    {
        $a       = $this->makeOrgAndClient('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        Sanctum::actingAs($a['user']);

        $store = $this->postJson("/api/projects/{$project->id}/risks", [
            'title' => 'Late design information', 'contract_id' => $contract->id, 'severity' => 'medium',
        ]);
        $store->assertStatus(201);
        $id = $store->json('id');

        $this->assertDatabaseHas('suresign_notifications', [
            'source_type' => 'contract_risk', 'source_id' => $id, 'source_field' => 'created',
            'action_url'  => "/app/projects/{$project->id}/risks",
        ]);

        $this->putJson("/api/projects/{$project->id}/risks/{$id}", ['description' => 'Updated context, no severity change'])
            ->assertStatus(200);
        $this->assertEquals(1, SuresignNotification::where('source_type', 'contract_risk')->where('source_id', $id)->count());

        $this->putJson("/api/projects/{$project->id}/risks/{$id}", ['severity' => 'critical'])->assertStatus(200);
        $this->assertEquals(2, SuresignNotification::where('source_type', 'contract_risk')->where('source_id', $id)->count());
    }

    // ── Cross-cutting: NotificationEngineService must not resolve these ────

    /**
     * Critical regression: NotificationEngineService::resolveStaleNotifications()
     * used to sweep every active notification sharing a source_type it tracks
     * (payment_application, delay_event, eot_request, contract_risk,
     * programme_milestone, final_account) regardless of who created it. Since
     * event-driven notifications from these Batch 0-2 controllers reuse those
     * same source_type strings with different source_field conventions, they
     * would never appear in the engine's activeKeys and were silently marked
     * 'resolved' the next time the engine ran for the project. Fixed by
     * restricting the sweep to type LIKE 'operational_%' (the engine's own
     * creation convention) — this proves the fix.
     */
    public function test_engine_does_not_resolve_event_driven_notifications_sharing_a_tracked_source_type(): void
    {
        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);

        $delayEvent = DelayEvent::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'created_by' => $a['user']->id, 'event_number' => 1, 'title' => 'Weather', 'cause_category' => 'weather',
            'date_occurred' => now()->toDateString(), 'status' => 'open',
        ]);

        $notification = SuresignNotification::create([
            'user_id' => $a['other']->id, 'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'type' => 'delay_event_raised', 'category' => SuresignNotification::CATEGORY_PROGRAMME,
            'priority' => SuresignNotification::PRIORITY_INFO, 'status' => SuresignNotification::STATUS_UNREAD,
            'title' => 'Delay Event #1', 'message' => 'Weather delay raised.',
            'source_type' => 'delay_event', 'source_id' => $delayEvent->id, 'source_field' => 'raised_' . now()->timestamp,
        ]);

        app(NotificationEngineService::class)->generateForProject($project->id);

        $this->assertEquals(
            SuresignNotification::STATUS_UNREAD,
            $notification->fresh()->status,
            'Event-driven notification was incorrectly auto-resolved by the deadline engine.'
        );
    }

    public function test_engine_still_resolves_its_own_stale_operational_notifications(): void
    {
        $a       = $this->makeOrgAndClient('a');
        $project = $this->makeProject($a['org'], $a['user']);

        // An engine-owned notification for a source that no longer exists —
        // simulates a milestone that was deleted since the last engine run.
        $notification = SuresignNotification::create([
            'user_id' => $a['other']->id, 'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'type' => 'operational_programme_milestone', 'category' => SuresignNotification::CATEGORY_PROGRAMME,
            'priority' => SuresignNotification::PRIORITY_INFO, 'status' => SuresignNotification::STATUS_UNREAD,
            'title' => 'Stale Milestone', 'message' => 'stale',
            'source_type' => 'programme_milestone', 'source_id' => 999999, 'source_field' => 'planned_date',
        ]);

        app(NotificationEngineService::class)->generateForProject($project->id);

        $this->assertEquals(SuresignNotification::STATUS_RESOLVED, $notification->fresh()->status);
    }
}

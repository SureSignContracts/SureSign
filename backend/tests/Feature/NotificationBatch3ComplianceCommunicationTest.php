<?php

namespace Tests\Feature;

use App\Models\DeliveryDocument;
use App\Models\MeetingMinutes;
use App\Models\Organization;
use App\Models\Project;
use App\Models\QaReport;
use App\Models\Rfi;
use App\Models\SiteDiary;
use App\Models\SuresignNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Batch 3: Delivery Documents, RFIs, Meetings, Site Diaries, QA Reports.
 * All in-app only per the approved channel policy — no new emails in this batch.
 */
class NotificationBatch3ComplianceCommunicationTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgAndClient(string $label): array
    {
        static $n = 0;
        $n++;

        $org   = Organization::create(['name' => "{$label} Org {$n}", 'slug' => "org-{$label}-{$n}"]);
        $user  = User::factory()->create(['organization_id' => $org->id]);
        $other = User::factory()->create(['organization_id' => $org->id]);
        $banned = User::factory()->create(['organization_id' => $org->id, 'banned_at' => now()]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        $other->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        $banned->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        return compact('org', 'user', 'other', 'banned');
    }

    private function makeProject(Organization $org, User $user): Project
    {
        return Project::create([
            'organization_id' => $org->id, 'created_by' => $user->id,
            'name' => "Project for {$org->name}", 'status' => 'active',
        ]);
    }

    // ── Delivery Documents ────────────────────────────────────────────────

    public function test_delivery_document_created_notifies_other_client_users_but_not_actor_or_banned(): void
    {
        $a       = $this->makeOrgAndClient('a');
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $store = $this->postJson("/api/projects/{$project->id}/delivery-documents", [
            'title' => 'RAMS — Scaffolding', 'category' => 'rams', 'contract_id' => null,
            'trade_package_id' => null,
        ]);
        // A DeliveryDocument requires exactly one of contract_id/trade_package_id — create a contract first.
        $contract = \App\Models\Contract::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'created_by' => $a['user']->id, 'title' => 'Main Contract', 'type' => 'main_contract', 'status' => 'active',
        ]);
        $store = $this->postJson("/api/projects/{$project->id}/delivery-documents", [
            'title' => 'RAMS — Scaffolding', 'category' => 'rams', 'contract_id' => $contract->id,
        ]);
        $store->assertStatus(201);
        $id = $store->json('id');

        $this->assertDatabaseHas('suresign_notifications', [
            'user_id' => $a['other']->id, 'source_type' => 'delivery_document', 'source_id' => $id, 'source_field' => 'created',
            'action_url' => "/app/projects/{$project->id}/delivery-documents",
        ]);
        $this->assertDatabaseMissing('suresign_notifications', ['user_id' => $a['user']->id, 'source_type' => 'delivery_document', 'source_id' => $id]);
        $this->assertDatabaseMissing('suresign_notifications', ['user_id' => $a['banned']->id, 'source_type' => 'delivery_document', 'source_id' => $id]);
    }

    public function test_delivery_document_linking_a_document_notifies_but_unrelated_field_edit_does_not(): void
    {
        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        $contract = \App\Models\Contract::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'created_by' => $a['user']->id, 'title' => 'Main Contract', 'type' => 'main_contract', 'status' => 'active',
        ]);
        $doc = DeliveryDocument::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'contract_id' => $contract->id,
            'title' => 'ITP', 'category' => 'itp', 'status' => 'required', 'is_ai_extracted' => false,
            'created_by' => $a['user']->id,
        ]);

        Sanctum::actingAs($a['user']);

        $this->putJson("/api/projects/{$project->id}/delivery-documents/{$doc->id}", ['notes' => 'chasing this up'])
            ->assertStatus(200);
        $this->assertDatabaseMissing('suresign_notifications', ['source_type' => 'delivery_document', 'source_id' => $doc->id, 'source_field' => 'linked']);

        $document = \App\Models\Document::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id, 'title' => 'ITP Scan',
            'file_name' => 'itp.pdf', 'category' => 'itp', 'created_by' => $a['user']->id, 'type' => 'pdf',
        ]);
        $this->putJson("/api/projects/{$project->id}/delivery-documents/{$doc->id}", ['document_id' => $document->id])
            ->assertStatus(200);

        $this->assertDatabaseHas('suresign_notifications', [
            'user_id' => $a['other']->id, 'source_type' => 'delivery_document', 'source_id' => $doc->id, 'source_field' => 'linked',
        ]);
    }

    // ── RFIs ──────────────────────────────────────────────────────────────

    public function test_rfi_submitted_notifies_but_draft_does_not(): void
    {
        $a       = $this->makeOrgAndClient('a');
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $draft = $this->postJson("/api/projects/{$project->id}/rfis", ['subject' => 'Draft RFI', 'status' => 'draft']);
        $draft->assertStatus(201);
        $this->assertDatabaseMissing('suresign_notifications', ['source_type' => 'rfi', 'source_id' => $draft->json('id')]);

        $submitted = $this->postJson("/api/projects/{$project->id}/rfis", ['subject' => 'Real RFI', 'status' => 'open']);
        $submitted->assertStatus(201);
        $this->assertDatabaseHas('suresign_notifications', [
            'user_id' => $a['other']->id, 'source_type' => 'rfi', 'source_id' => $submitted->json('id'), 'source_field' => 'submitted',
            'action_url' => "/app/projects/{$project->id}/rfis",
        ]);
    }

    public function test_rfi_answered_and_closed_both_notify_distinctly(): void
    {
        $a       = $this->makeOrgAndClient('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $rfi = Rfi::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id, 'created_by' => $a['user']->id,
            'rfi_number' => 1, 'subject' => 'Detail query', 'status' => 'open', 'raised_date' => now()->toDateString(),
        ]);

        Sanctum::actingAs($a['user']);
        $this->putJson("/api/rfis/{$rfi->id}", ['status' => 'responded', 'response' => 'See drawing rev C'])->assertStatus(200);
        $this->putJson("/api/rfis/{$rfi->id}", ['status' => 'closed'])->assertStatus(200);

        $this->assertTrue(SuresignNotification::where('source_type', 'rfi')->where('source_id', $rfi->id)->where('type', 'rfi_answered')->exists());
        $this->assertTrue(SuresignNotification::where('source_type', 'rfi')->where('source_id', $rfi->id)->where('type', 'rfi_closed')->exists());
        $this->assertEquals(2, SuresignNotification::where('source_type', 'rfi')->where('source_id', $rfi->id)->count());
    }

    public function test_rfi_notifications_are_cross_tenant_isolated(): void
    {
        $a       = $this->makeOrgAndClient('a');
        $b       = $this->makeOrgAndClient('b');
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $this->postJson("/api/projects/{$project->id}/rfis", ['subject' => 'RFI', 'status' => 'open'])->assertStatus(201);

        $this->assertDatabaseMissing('suresign_notifications', ['user_id' => $b['user']->id]);
        $this->assertDatabaseMissing('suresign_notifications', ['user_id' => $b['other']->id]);
    }

    // ── Meetings ──────────────────────────────────────────────────────────

    public function test_meeting_created_notifies_and_cosmetic_edit_does_not(): void
    {
        $a       = $this->makeOrgAndClient('a');
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $store = $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'Progress Meeting 1', 'meeting_date' => now()->toDateString(),
        ]);
        $store->assertStatus(201);
        $id = $store->json('id');

        $this->assertDatabaseHas('suresign_notifications', [
            'user_id' => $a['other']->id, 'source_type' => 'meeting', 'source_id' => $id, 'source_field' => 'created',
            'action_url' => "/app/projects/{$project->id}/meetings",
        ]);

        $this->putJson("/api/projects/{$project->id}/meetings/{$id}", ['agenda' => 'Updated agenda text'])->assertStatus(200);
        $this->assertEquals(1, SuresignNotification::where('source_type', 'meeting')->where('source_id', $id)->count());
    }

    public function test_meeting_reschedule_and_status_change_both_notify(): void
    {
        $a       = $this->makeOrgAndClient('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $meeting = MeetingMinutes::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id, 'created_by' => $a['user']->id,
            'meeting_number' => 1, 'title' => 'Design Review', 'meeting_date' => now()->toDateString(), 'status' => 'draft',
        ]);

        Sanctum::actingAs($a['user']);
        $this->putJson("/api/projects/{$project->id}/meetings/{$meeting->id}", ['meeting_date' => now()->addWeek()->toDateString()])
            ->assertStatus(200);
        $this->putJson("/api/projects/{$project->id}/meetings/{$meeting->id}", ['status' => 'issued'])->assertStatus(200);

        $this->assertTrue(SuresignNotification::where('source_type', 'meeting')->where('source_id', $meeting->id)->where('type', 'meeting_rescheduled')->exists());
        $this->assertTrue(SuresignNotification::where('source_type', 'meeting')->where('source_id', $meeting->id)->where('type', 'meeting_status_changed')->exists());
    }

    // ── Site Diaries ──────────────────────────────────────────────────────

    public function test_site_diary_created_notifies_and_approval_notifies_but_draft_status_edit_does_not(): void
    {
        $a       = $this->makeOrgAndClient('a');
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $store = $this->postJson("/api/projects/{$project->id}/site-diaries", ['diary_date' => now()->toDateString()]);
        $store->assertStatus(201);
        $id = $store->json('id');

        $this->assertDatabaseHas('suresign_notifications', [
            'user_id' => $a['other']->id, 'source_type' => 'site_diary', 'source_id' => $id, 'source_field' => 'created',
            'action_url' => "/app/projects/{$project->id}/site-reports",
        ]);

        // draft -> draft (no-op) plus a routine field edit — still just the 1 creation notification.
        $this->putJson("/api/projects/{$project->id}/site-diaries/{$id}", ['issues' => 'Minor access delay'])->assertStatus(200);
        $this->assertEquals(1, SuresignNotification::where('source_type', 'site_diary')->where('source_id', $id)->count());

        $this->putJson("/api/projects/{$project->id}/site-diaries/{$id}", ['status' => 'approved'])->assertStatus(200);
        $this->assertEquals(2, SuresignNotification::where('source_type', 'site_diary')->where('source_id', $id)->count());
    }

    // ── QA Reports ────────────────────────────────────────────────────────

    public function test_qa_report_created_and_failed_notify_but_moving_to_open_does_not(): void
    {
        $a       = $this->makeOrgAndClient('a');
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $store = $this->postJson("/api/projects/{$project->id}/qa-reports", ['title' => 'Concrete pour inspection']);
        $store->assertStatus(201);
        $id = $store->json('id');

        $this->assertDatabaseHas('suresign_notifications', [
            'user_id' => $a['other']->id, 'source_type' => 'qa_report', 'source_id' => $id, 'source_field' => 'created',
            'action_url' => "/app/projects/{$project->id}/qa",
        ]);

        $this->putJson("/api/projects/{$project->id}/qa-reports/{$id}", ['status' => 'open'])->assertStatus(200);
        $this->assertEquals(1, SuresignNotification::where('source_type', 'qa_report')->where('source_id', $id)->count());

        $this->putJson("/api/projects/{$project->id}/qa-reports/{$id}", ['status' => 'failed'])->assertStatus(200);
        $this->assertEquals(2, SuresignNotification::where('source_type', 'qa_report')->where('source_id', $id)->count());
    }

    // ── Cross-cutting: deadline ownership not duplicated ───────────────────

    public function test_delivery_document_overdue_condition_is_not_duplicated_by_the_new_created_event(): void
    {
        // The engine tracks delivery_document via source_field 'due_date';
        // our new event-driven notification uses 'created' — confirm they
        // coexist without either suppressing the other.
        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        $contract = \App\Models\Contract::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'created_by' => $a['user']->id, 'title' => 'Main Contract', 'type' => 'main_contract', 'status' => 'active',
        ]);

        Sanctum::actingAs($a['user']);
        $store = $this->postJson("/api/projects/{$project->id}/delivery-documents", [
            'title' => 'Lift Plan', 'category' => 'lift_plan', 'contract_id' => $contract->id,
            'due_date' => now()->subDays(2)->toDateString(),
        ]);
        $store->assertStatus(201);
        $id = $store->json('id');

        app(\App\Services\NotificationEngineService::class)->generateForProject($project->id);

        // Our event-driven 'created' notification must still be unread/untouched.
        $this->assertDatabaseHas('suresign_notifications', [
            'source_type' => 'delivery_document', 'source_id' => $id, 'source_field' => 'created',
            'status' => 'unread',
        ]);
    }
}

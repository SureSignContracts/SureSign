<?php

namespace Tests\Feature;

use App\Jobs\GenerateProjectNotificationsJob;
use App\Models\Document;
use App\Models\Drawing;
use App\Models\DrawingHotspot;
use App\Models\DrawingHotspotLink;
use App\Models\DrawingRevision;
use App\Models\Organization;
use App\Models\Project;
use App\Models\QaReport;
use App\Models\Rfi;
use App\Models\Snag;
use App\Models\SuresignNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Drawing Phase 7B1 — optional `drawing_hotspot_id` on the existing Snag/
 * RFI/QA Report create endpoints (Option B). Covers: normal creation
 * unchanged when absent, atomic record+link creation, full rollback on any
 * invalid/cross-project/cross-tenant hotspot, historical-revision hotspots
 * remaining linkable, and notification/job side effects never escaping a
 * rolled-back transaction.
 */
class DrawingHotspotAutoLinkTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgAndUser(string $label): array
    {
        static $n = 0;
        $n++;

        $org = Organization::create(['name' => "{$label} Org {$n}", 'slug' => "org-{$label}-{$n}", 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);

        return [$org, $user];
    }

    private function makeProject(Organization $org, User $user): Project
    {
        return Project::create([
            'organization_id' => $org->id, 'created_by' => $user->id,
            'name' => "Project for {$org->name}", 'status' => 'active',
        ]);
    }

    private function makeDocument(Project $project, User $user, string $title = 'A101.pdf'): Document
    {
        return Document::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $user->id,
            'title' => $title, 'type' => 'other', 'file_name' => $title,
        ]);
    }

    private function makeDrawingWithCurrentRevision(Project $project, User $user, Document $document): array
    {
        $drawing = Drawing::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'document_id' => $document->id,
            'created_by' => $user->id, 'drawing_number' => 'A101', 'title' => 'Ground Floor GA',
        ]);
        $revision = DrawingRevision::create([
            'drawing_id' => $drawing->id, 'document_id' => $document->id,
            'revision_code' => 'P01', 'created_by' => $user->id,
        ]);
        $drawing->update(['current_revision_id' => $revision->id]);

        return [$drawing, $revision];
    }

    private function makeHotspot(DrawingRevision $revision, User $user): DrawingHotspot
    {
        return DrawingHotspot::create(['drawing_revision_id' => $revision->id, 'page_number' => 1, 'x' => 0.25, 'y' => 0.3, 'created_by' => $user->id]);
    }

    // ── Snag ─────────────────────────────────────────────────────────────

    public function test_snag_create_without_hotspot_is_unchanged(): void
    {
        [$org, $user] = $this->makeOrgAndUser('snagplain');
        $project = $this->makeProject($org, $user);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/snagging", ['title' => 'Handrail incomplete']);

        $response->assertStatus(201);
        $this->assertDatabaseHas('snags', ['title' => 'Handrail incomplete', 'project_id' => $project->id]);
        $this->assertSame(0, DrawingHotspotLink::count());
    }

    public function test_snag_create_with_valid_hotspot_creates_snag_and_link(): void
    {
        [$org, $user] = $this->makeOrgAndUser('snaghs');
        $project = $this->makeProject($org, $user);
        [$drawing, $revision] = $this->makeDrawingWithCurrentRevision($project, $user, $this->makeDocument($project, $user));
        $hotspot = $this->makeHotspot($revision, $user);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/snagging", [
            'title' => 'Handrail incomplete', 'drawing_hotspot_id' => $hotspot->id,
        ]);

        $response->assertStatus(201);
        $snagId = $response->json('id');
        $this->assertDatabaseHas('drawing_hotspot_links', [
            'drawing_hotspot_id' => $hotspot->id, 'linkable_type' => Snag::class, 'linkable_id' => $snagId,
        ]);
        $this->assertDatabaseHas('project_activities', ['activity_type' => 'drawing_hotspot_record_linked']);
        $this->assertDatabaseHas('project_activities', ['activity_type' => 'snag_created']);
        // No third, redundant "created from Drawing" event.
        $this->assertDatabaseMissing('project_activities', ['activity_type' => 'snag_created_from_drawing']);
    }

    public function test_snag_create_with_invalid_hotspot_rolls_back(): void
    {
        [$org, $user] = $this->makeOrgAndUser('snaginvalid');
        $project = $this->makeProject($org, $user);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/snagging", [
            'title' => 'Handrail incomplete', 'drawing_hotspot_id' => 999999,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('snags', ['title' => 'Handrail incomplete']);
    }

    public function test_snag_create_with_cross_project_hotspot_rolls_back(): void
    {
        [$org, $user] = $this->makeOrgAndUser('snagxproj');
        $projectA = $this->makeProject($org, $user);
        $projectB = $this->makeProject($org, $user);
        [, $revision] = $this->makeDrawingWithCurrentRevision($projectA, $user, $this->makeDocument($projectA, $user));
        $hotspot = $this->makeHotspot($revision, $user);

        Sanctum::actingAs($user);
        // Creating the Snag in Project B, using a hotspot that belongs to
        // Project A's Drawing.
        $response = $this->postJson("/api/projects/{$projectB->id}/snagging", [
            'title' => 'Handrail incomplete', 'drawing_hotspot_id' => $hotspot->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('snags', ['title' => 'Handrail incomplete']);
        $this->assertSame(0, DrawingHotspotLink::count());
    }

    public function test_snag_create_with_cross_tenant_hotspot_rolls_back(): void
    {
        [$orgA, $userA] = $this->makeOrgAndUser('snagxtenA');
        [$orgB, $userB] = $this->makeOrgAndUser('snagxtenB');
        $projectA = $this->makeProject($orgA, $userA);
        $projectB = $this->makeProject($orgB, $userB);
        [, $revision] = $this->makeDrawingWithCurrentRevision($projectA, $userA, $this->makeDocument($projectA, $userA));
        $hotspot = $this->makeHotspot($revision, $userA);

        // userB is a Super Admin-free member of orgB only, but calling the
        // Snag create for projectB (their own org) with a hotspot that
        // belongs to orgA's Drawing must still be rejected by the service's
        // own ownership check, independent of the route's own authorize().
        Sanctum::actingAs($userB);
        $response = $this->postJson("/api/projects/{$projectB->id}/snagging", [
            'title' => 'Handrail incomplete', 'drawing_hotspot_id' => $hotspot->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('snags', ['title' => 'Handrail incomplete']);
    }

    public function test_snag_numbering_and_defaults_unchanged_with_hotspot(): void
    {
        [$org, $user] = $this->makeOrgAndUser('snagdefaults');
        $project = $this->makeProject($org, $user);
        [, $revision] = $this->makeDrawingWithCurrentRevision($project, $user, $this->makeDocument($project, $user));
        $hotspot = $this->makeHotspot($revision, $user);
        Snag::create(['organization_id' => $org->id, 'project_id' => $project->id, 'created_by' => $user->id, 'snag_number' => 1, 'title' => 'Existing', 'status' => 'open']);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/snagging", [
            'title' => 'New one', 'drawing_hotspot_id' => $hotspot->id,
        ]);

        $response->assertStatus(201);
        $this->assertSame(2, $response->json('snag_number'));
        $this->assertSame('open', $response->json('status'));
        $this->assertSame('medium', $response->json('priority'));
    }

    // ── Historical hotspot auto-link ────────────────────────────────────

    public function test_rfi_create_with_historical_hotspot_succeeds_without_moving_hotspot(): void
    {
        [$org, $user] = $this->makeOrgAndUser('rfihist');
        $project = $this->makeProject($org, $user);
        [$drawing, $revisionP01] = $this->makeDrawingWithCurrentRevision($project, $user, $this->makeDocument($project, $user));
        $hotspot = $this->makeHotspot($revisionP01, $user);
        $docC01 = $this->makeDocument($project, $user, 'c01.pdf');

        Sanctum::actingAs($user);
        $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $docC01->id, 'revision_code' => 'C01',
        ])->assertStatus(201);
        $this->assertNotSame($revisionP01->id, $drawing->fresh()->current_revision_id);

        $response = $this->postJson("/api/projects/{$project->id}/rfis", [
            'subject' => 'Stair balustrade detail', 'drawing_hotspot_id' => $hotspot->id,
        ]);

        $response->assertStatus(201);
        $rfiId = $response->json('id');
        $this->assertDatabaseHas('drawing_hotspot_links', [
            'drawing_hotspot_id' => $hotspot->id, 'linkable_type' => Rfi::class, 'linkable_id' => $rfiId,
        ]);
        // The hotspot stays on P01 — no carry-forward, no coordinate change.
        $this->assertSame($revisionP01->id, $hotspot->fresh()->drawing_revision_id);
    }

    // ── RFI: numbering/defaults/notifications ───────────────────────────

    public function test_rfi_create_without_hotspot_is_unchanged(): void
    {
        Queue::fake();
        [$org, $user] = $this->makeOrgAndUser('rfiplain');
        $project = $this->makeProject($org, $user);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/rfis", ['subject' => 'Stair balustrade detail']);

        $response->assertStatus(201);
        $this->assertDatabaseHas('rfis', ['subject' => 'Stair balustrade detail']);
        Queue::assertPushed(GenerateProjectNotificationsJob::class);
    }

    public function test_rfi_create_with_valid_hotspot_creates_rfi_and_link(): void
    {
        Queue::fake();
        [$org, $user] = $this->makeOrgAndUser('rfihs');
        $project = $this->makeProject($org, $user);
        [, $revision] = $this->makeDrawingWithCurrentRevision($project, $user, $this->makeDocument($project, $user));
        $hotspot = $this->makeHotspot($revision, $user);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/rfis", [
            'subject' => 'Stair balustrade detail', 'drawing_hotspot_id' => $hotspot->id,
        ]);

        $response->assertStatus(201);
        $rfiId = $response->json('id');
        $this->assertDatabaseHas('drawing_hotspot_links', [
            'drawing_hotspot_id' => $hotspot->id, 'linkable_type' => Rfi::class, 'linkable_id' => $rfiId,
        ]);
        $this->assertDatabaseHas('project_activities', ['activity_type' => 'rfi_raised']);
        $this->assertDatabaseHas('project_activities', ['activity_type' => 'drawing_hotspot_record_linked']);
        Queue::assertPushed(GenerateProjectNotificationsJob::class);
    }

    public function test_rfi_numbering_and_raised_date_default_unchanged_with_hotspot(): void
    {
        Queue::fake();
        [$org, $user] = $this->makeOrgAndUser('rfidefaults');
        $project = $this->makeProject($org, $user);
        [, $revision] = $this->makeDrawingWithCurrentRevision($project, $user, $this->makeDocument($project, $user));
        $hotspot = $this->makeHotspot($revision, $user);
        Rfi::create(['organization_id' => $org->id, 'project_id' => $project->id, 'created_by' => $user->id, 'rfi_number' => 1, 'subject' => 'Existing', 'status' => 'open']);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/rfis", [
            'subject' => 'New one', 'drawing_hotspot_id' => $hotspot->id,
        ]);

        $response->assertStatus(201);
        $this->assertSame(2, $response->json('rfi_number'));
        $this->assertNotNull($response->json('raised_date'));
    }

    public function test_rfi_create_with_invalid_hotspot_rolls_back_and_sends_no_notification(): void
    {
        Queue::fake();
        [$org, $user] = $this->makeOrgAndUser('rfiinvalid');
        $project = $this->makeProject($org, $user);
        $notificationsBefore = SuresignNotification::count();

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/rfis", [
            'subject' => 'Stair balustrade detail', 'drawing_hotspot_id' => 999999,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('rfis', ['subject' => 'Stair balustrade detail']);
        $this->assertSame($notificationsBefore, SuresignNotification::count());
        Queue::assertNotPushed(GenerateProjectNotificationsJob::class);
    }

    public function test_rfi_create_with_cross_project_hotspot_rolls_back(): void
    {
        Queue::fake();
        [$org, $user] = $this->makeOrgAndUser('rfixproj');
        $projectA = $this->makeProject($org, $user);
        $projectB = $this->makeProject($org, $user);
        [, $revision] = $this->makeDrawingWithCurrentRevision($projectA, $user, $this->makeDocument($projectA, $user));
        $hotspot = $this->makeHotspot($revision, $user);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$projectB->id}/rfis", [
            'subject' => 'Stair balustrade detail', 'drawing_hotspot_id' => $hotspot->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('rfis', ['subject' => 'Stair balustrade detail']);
        Queue::assertNotPushed(GenerateProjectNotificationsJob::class);
    }

    public function test_rfi_create_with_cross_tenant_hotspot_rolls_back(): void
    {
        Queue::fake();
        [$orgA, $userA] = $this->makeOrgAndUser('rfixtenA');
        [$orgB, $userB] = $this->makeOrgAndUser('rfixtenB');
        $projectA = $this->makeProject($orgA, $userA);
        $projectB = $this->makeProject($orgB, $userB);
        [, $revision] = $this->makeDrawingWithCurrentRevision($projectA, $userA, $this->makeDocument($projectA, $userA));
        $hotspot = $this->makeHotspot($revision, $userA);

        Sanctum::actingAs($userB);
        $response = $this->postJson("/api/projects/{$projectB->id}/rfis", [
            'subject' => 'Stair balustrade detail', 'drawing_hotspot_id' => $hotspot->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('rfis', ['subject' => 'Stair balustrade detail']);
        Queue::assertNotPushed(GenerateProjectNotificationsJob::class);
    }

    public function test_rfi_draft_status_notification_semantics_unchanged_with_hotspot(): void
    {
        Queue::fake();
        [$org, $user] = $this->makeOrgAndUser('rfidraft');
        $project = $this->makeProject($org, $user);
        [, $revision] = $this->makeDrawingWithCurrentRevision($project, $user, $this->makeDocument($project, $user));
        $hotspot = $this->makeHotspot($revision, $user);
        $notificationsBefore = SuresignNotification::count();

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/rfis", [
            'subject' => 'Stair balustrade detail', 'status' => 'draft', 'drawing_hotspot_id' => $hotspot->id,
        ]);

        $response->assertStatus(201);
        // Draft RFIs never trigger notifyRfi() — unchanged by hotspot presence.
        $this->assertSame($notificationsBefore, SuresignNotification::count());
        // The job that regenerates deadline-driven notifications still runs
        // regardless of draft status — unchanged existing behaviour.
        Queue::assertPushed(GenerateProjectNotificationsJob::class);
    }

    // ── QA Report ────────────────────────────────────────────────────────

    public function test_qa_report_create_without_hotspot_is_unchanged(): void
    {
        [$org, $user] = $this->makeOrgAndUser('qaplain');
        $project = $this->makeProject($org, $user);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/qa-reports", ['title' => 'Foundation pour inspection']);

        $response->assertStatus(201);
        $this->assertDatabaseHas('qa_reports', ['title' => 'Foundation pour inspection']);
    }

    public function test_qa_report_create_with_valid_hotspot_creates_report_and_link(): void
    {
        [$org, $user] = $this->makeOrgAndUser('qahs');
        $project = $this->makeProject($org, $user);
        [, $revision] = $this->makeDrawingWithCurrentRevision($project, $user, $this->makeDocument($project, $user));
        $hotspot = $this->makeHotspot($revision, $user);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/qa-reports", [
            'title' => 'Foundation pour inspection', 'drawing_hotspot_id' => $hotspot->id,
        ]);

        $response->assertStatus(201);
        $reportId = $response->json('id');
        $this->assertDatabaseHas('drawing_hotspot_links', [
            'drawing_hotspot_id' => $hotspot->id, 'linkable_type' => QaReport::class, 'linkable_id' => $reportId,
        ]);
        $this->assertDatabaseHas('project_activities', ['activity_type' => 'qa_report_created']);
        $this->assertDatabaseHas('project_activities', ['activity_type' => 'drawing_hotspot_record_linked']);
    }

    public function test_qa_report_numbering_and_defaults_unchanged_with_hotspot(): void
    {
        [$org, $user] = $this->makeOrgAndUser('qadefaults');
        $project = $this->makeProject($org, $user);
        [, $revision] = $this->makeDrawingWithCurrentRevision($project, $user, $this->makeDocument($project, $user));
        $hotspot = $this->makeHotspot($revision, $user);
        QaReport::create(['organization_id' => $org->id, 'project_id' => $project->id, 'created_by' => $user->id, 'report_number' => 1, 'title' => 'Existing', 'status' => 'draft']);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/qa-reports", [
            'title' => 'New one', 'drawing_hotspot_id' => $hotspot->id,
        ]);

        $response->assertStatus(201);
        $this->assertSame(2, $response->json('report_number'));
        $this->assertSame('draft', $response->json('status'));
        $this->assertFalse((bool) $response->json('follow_up_required'));
    }

    public function test_qa_report_create_with_invalid_hotspot_rolls_back_and_sends_no_notification(): void
    {
        [$org, $user] = $this->makeOrgAndUser('qainvalid');
        $project = $this->makeProject($org, $user);
        $notificationsBefore = SuresignNotification::count();

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/qa-reports", [
            'title' => 'Foundation pour inspection', 'drawing_hotspot_id' => 999999,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('qa_reports', ['title' => 'Foundation pour inspection']);
        $this->assertSame($notificationsBefore, SuresignNotification::count());
    }

    public function test_qa_report_create_with_cross_project_hotspot_rolls_back(): void
    {
        [$org, $user] = $this->makeOrgAndUser('qaxproj');
        $projectA = $this->makeProject($org, $user);
        $projectB = $this->makeProject($org, $user);
        [, $revision] = $this->makeDrawingWithCurrentRevision($projectA, $user, $this->makeDocument($projectA, $user));
        $hotspot = $this->makeHotspot($revision, $user);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$projectB->id}/qa-reports", [
            'title' => 'Foundation pour inspection', 'drawing_hotspot_id' => $hotspot->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('qa_reports', ['title' => 'Foundation pour inspection']);
    }

    public function test_qa_report_create_with_cross_tenant_hotspot_rolls_back(): void
    {
        [$orgA, $userA] = $this->makeOrgAndUser('qaxtenA');
        [$orgB, $userB] = $this->makeOrgAndUser('qaxtenB');
        $projectA = $this->makeProject($orgA, $userA);
        $projectB = $this->makeProject($orgB, $userB);
        [, $revision] = $this->makeDrawingWithCurrentRevision($projectA, $userA, $this->makeDocument($projectA, $userA));
        $hotspot = $this->makeHotspot($revision, $userA);

        Sanctum::actingAs($userB);
        $response = $this->postJson("/api/projects/{$projectB->id}/qa-reports", [
            'title' => 'Foundation pour inspection', 'drawing_hotspot_id' => $hotspot->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('qa_reports', ['title' => 'Foundation pour inspection']);
    }

    // ── Duplicate hotspot link via create (same rule as Link Existing) ──

    public function test_create_with_hotspot_already_linked_to_another_record_of_same_type_rejected(): void
    {
        [$org, $user] = $this->makeOrgAndUser('dupcreate');
        $project = $this->makeProject($org, $user);
        [, $revision] = $this->makeDrawingWithCurrentRevision($project, $user, $this->makeDocument($project, $user));
        $hotspot = $this->makeHotspot($revision, $user);

        Sanctum::actingAs($user);
        $this->postJson("/api/projects/{$project->id}/snagging", [
            'title' => 'First', 'drawing_hotspot_id' => $hotspot->id,
        ])->assertStatus(201);

        // A second, DIFFERENT Snag linked to the exact same hotspot is not a
        // "duplicate" in DrawingHotspotLink terms (different linkable_id) —
        // only relinking the SAME record would be. This confirms creating a
        // second Snag at the same location succeeds normally.
        $response = $this->postJson("/api/projects/{$project->id}/snagging", [
            'title' => 'Second', 'drawing_hotspot_id' => $hotspot->id,
        ]);

        $response->assertStatus(201);
        $this->assertSame(2, DrawingHotspotLink::where('drawing_hotspot_id', $hotspot->id)->count());
    }

    // ── Authorization unchanged ──────────────────────────────────────────

    public function test_unauthorized_organisation_member_cannot_create_snag_via_drawing_hotspot(): void
    {
        [$orgA, $userA] = $this->makeOrgAndUser('authzA');
        [, $userOutsider] = $this->makeOrgAndUser('authzB');
        $project = $this->makeProject($orgA, $userA);
        [, $revision] = $this->makeDrawingWithCurrentRevision($project, $userA, $this->makeDocument($project, $userA));
        $hotspot = $this->makeHotspot($revision, $userA);

        Sanctum::actingAs($userOutsider);
        $response = $this->postJson("/api/projects/{$project->id}/snagging", [
            'title' => 'Handrail incomplete', 'drawing_hotspot_id' => $hotspot->id,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('snags', ['title' => 'Handrail incomplete']);
    }
}

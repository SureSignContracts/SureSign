<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractProgrammeMilestone;
use App\Models\ContractRisk;
use App\Models\DeliveryDocument;
use App\Models\Organization;
use App\Models\PaymentApplication;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Rfi;
use App\Models\User;
use App\Models\Variation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Global Dashboard Phase 2 — GET /dashboard/action-centre.
 *
 * Covers: tenant isolation, overdue/due-today/due-soon/upcoming
 * classification (shared with Global Commercial via DeadlineClassifier),
 * deterministic urgency ordering, record-type normalisation, action URLs,
 * portfolio-health counts derived from the same dataset, mixed-currency
 * commercial snapshot, empty/no-project handling, and record limits.
 */
class OrganisationDashboardActionCentreTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(string $label, array $overrides = []): Organization
    {
        static $n = 0;
        $n++;

        return Organization::create(array_merge([
            'name' => "{$label} Org {$n}", 'slug' => "org-{$label}-{$n}", 'timezone' => 'Europe/London',
        ], $overrides));
    }

    private function makeProject(Organization $org, User $user, array $overrides = []): Project
    {
        static $n = 0;
        $n++;

        return Project::create(array_merge([
            'organization_id' => $org->id, 'created_by' => $user->id,
            'name' => "Project {$n}", 'status' => 'active', 'currency' => 'GBP',
        ], $overrides));
    }

    private function makeContract(Project $project, User $user): Contract
    {
        return Contract::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'created_by' => $user->id, 'title' => 'Main Contract', 'type' => 'main_contract',
            'status' => 'active', 'retention_percentage' => 5,
        ]);
    }

    private function makeRfi(Project $project, User $user, array $overrides = []): Rfi
    {
        static $n = 0;
        $n++;

        return Rfi::create(array_merge([
            'project_id' => $project->id, 'organization_id' => $project->organization_id, 'created_by' => $user->id,
            'rfi_number' => $n, 'subject' => "RFI Subject {$n}", 'status' => 'open',
            'raised_date' => now()->toDateString(),
        ], $overrides));
    }

    private function makeApplication(Project $project, array $overrides = []): PaymentApplication
    {
        static $n = 0;
        $n++;

        return PaymentApplication::create(array_merge([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'created_by' => $project->created_by,
            'application_number' => $n, 'status' => 'draft',
            'application_date' => now()->toDateString(),
            'gross_valuation' => 10000, 'amount_due' => 9500,
        ], $overrides));
    }

    // ── Tenant isolation ──────────────────────────────────────────────────

    public function test_client_only_receives_their_own_organisations_action_items(): void
    {
        $orgA = $this->makeOrg('a');
        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $projectA = $this->makeProject($orgA, $userA, ['name' => 'Alpha Tower']);
        $this->makeRfi($projectA, $userA, ['response_due_date' => now()->subDays(2)->toDateString()]);

        $orgB = $this->makeOrg('b');
        $userB = User::factory()->create(['organization_id' => $orgB->id]);
        $projectB = $this->makeProject($orgB, $userB, ['name' => 'Beta Wharf']);
        $this->makeRfi($projectB, $userB, ['response_due_date' => now()->subDays(2)->toDateString(), 'subject' => 'Secret Beta RFI']);

        Sanctum::actingAs($userA);
        $response = $this->getJson('/api/dashboard/action-centre')->assertStatus(200);

        $summaries = collect($response->json('needs_attention.items'))->pluck('summary')->all();
        $this->assertContains('RFI Subject 1', $summaries);
        $this->assertNotContains('Secret Beta RFI', $summaries);

        $projectNames = collect($response->json('needs_attention.items'))->pluck('project_name')->unique()->all();
        $this->assertNotContains('Beta Wharf', $projectNames);
    }

    // ── Deadline classification ───────────────────────────────────────────

    public function test_items_are_classified_overdue_due_today_due_soon_and_upcoming(): void
    {
        $org = $this->makeOrg('deadline');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);

        $this->makeRfi($project, $user, ['response_due_date' => now()->subDays(3)->toDateString()]);
        $this->makeRfi($project, $user, ['response_due_date' => now()->toDateString()]);
        $this->makeRfi($project, $user, ['response_due_date' => now()->addDays(4)->toDateString()]);
        $this->makeRfi($project, $user, ['response_due_date' => now()->addDays(20)->toDateString()]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/dashboard/action-centre')->assertStatus(200);

        $counts = $response->json('needs_attention.counts');
        $this->assertEquals(1, $counts['overdue']);
        $this->assertEquals(1, $counts['due_today']);
        $this->assertEquals(1, $counts['due_soon']);
        $this->assertEquals(1, $counts['upcoming']);
        $this->assertEquals(7, $response->json('meta.due_soon_threshold_days'));
    }

    public function test_resolved_rfis_are_excluded(): void
    {
        $org = $this->makeOrg('resolved');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);

        $this->makeRfi($project, $user, ['response_due_date' => now()->subDays(5)->toDateString(), 'status' => 'closed']);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/dashboard/action-centre')->assertStatus(200);

        $this->assertEquals(0, $response->json('needs_attention.counts.overdue'));
    }

    // ── Deterministic ordering ────────────────────────────────────────────

    public function test_items_are_sorted_by_urgency_then_deterministically(): void
    {
        $org = $this->makeOrg('order');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);

        $this->makeRfi($project, $user, ['response_due_date' => now()->subDays(1)->toDateString(), 'subject' => 'Less overdue']);
        $this->makeRfi($project, $user, ['response_due_date' => now()->subDays(10)->toDateString(), 'subject' => 'Most overdue']);
        $this->makeRfi($project, $user, ['response_due_date' => now()->addDays(20)->toDateString(), 'subject' => 'Upcoming']);
        $this->makeRfi($project, $user, ['response_due_date' => now()->toDateString(), 'subject' => 'Today']);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/dashboard/action-centre')->assertStatus(200);

        $summaries = collect($response->json('needs_attention.items'))->pluck('summary')->all();
        $this->assertEquals(['Most overdue', 'Less overdue', 'Today', 'Upcoming'], $summaries);
    }

    // ── Record-type coverage and normalisation ────────────────────────────

    public function test_variation_risk_delivery_document_and_milestone_items_are_included(): void
    {
        $org = $this->makeOrg('types');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);

        Variation::create([
            'project_id' => $project->id, 'contract_id' => $contract->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'variation_number' => 'V1', 'title' => 'Groundworks variation', 'status' => 'instructed',
            'quotation_due_date' => now()->subDays(1)->toDateString(), 'quoted_amount' => 500,
        ]);

        ContractRisk::create([
            'project_id' => $project->id, 'contract_id' => $contract->id, 'organization_id' => $org->id,
            'title' => 'Asbestos risk', 'status' => 'open', 'review_date' => now()->subDays(2)->toDateString(),
        ]);

        DeliveryDocument::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'title' => 'RAMS for scaffolding', 'status' => 'pending', 'due_date' => now()->toDateString(),
        ]);

        ContractProgrammeMilestone::create([
            'project_id' => $project->id, 'contract_id' => $contract->id,
            'name' => 'Practical Completion', 'planned_date' => now()->addDays(3)->toDateString(),
        ]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/dashboard/action-centre')->assertStatus(200);

        $types = collect($response->json('needs_attention.items'))->pluck('type')->unique()->all();
        sort($types);
        $this->assertEquals(['contract_risk', 'delivery_document', 'programme_milestone', 'variation'], $types);
    }

    // ── Action URLs ───────────────────────────────────────────────────────

    public function test_action_urls_resolve_via_workspace_navigation_resolver(): void
    {
        $org = $this->makeOrg('links');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);
        $this->makeRfi($project, $user, ['response_due_date' => now()->toDateString()]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/dashboard/action-centre')->assertStatus(200);

        $item = $response->json('needs_attention.items.0');
        $this->assertEquals("/app/projects/{$project->id}/rfis", $item['action_url']);
    }

    // ── Portfolio Health ──────────────────────────────────────────────────

    public function test_portfolio_health_counts_come_from_the_same_action_dataset(): void
    {
        $org = $this->makeOrg('health');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $projectA = $this->makeProject($org, $user, ['status' => 'active']);
        $projectB = $this->makeProject($org, $user, ['status' => 'active']);
        $this->makeProject($org, $user, ['status' => 'completed']);

        $this->makeRfi($projectA, $user, ['response_due_date' => now()->subDays(1)->toDateString()]);
        $this->makeRfi($projectB, $user, ['response_due_date' => now()->addDays(3)->toDateString()]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/dashboard/action-centre')->assertStatus(200);

        $health = $response->json('portfolio_health');
        $this->assertEquals(2, $health['active_projects']);
        $this->assertEquals(1, $health['projects_with_overdue_items']);
        $this->assertEquals(1, $health['total_overdue_items']);
        $this->assertEquals(1, $health['items_due_soon']);
    }

    // ── Commercial Snapshot ───────────────────────────────────────────────

    public function test_commercial_snapshot_matches_aggregation_service_and_separates_currencies(): void
    {
        $org = $this->makeOrg('snapshot');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $gbpProject = $this->makeProject($org, $user, ['currency' => 'GBP']);
        $usdProject = $this->makeProject($org, $user, ['currency' => 'USD']);

        $this->makeApplication($gbpProject, ['status' => 'certified', 'certified_amount' => 5000, 'paid_amount' => 2000]);
        $this->makeApplication($usdProject, ['status' => 'certified', 'certified_amount' => 8000, 'paid_amount' => 8000]);
        $this->makeApplication($gbpProject, ['status' => 'submitted']);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/dashboard/action-centre')->assertStatus(200);

        $byCurrency = collect($response->json('commercial_snapshot.by_currency'));
        $this->assertCount(2, $byCurrency);
        $this->assertEquals(3000.0, $byCurrency->firstWhere('currency', 'GBP')['outstanding_total']);
        $this->assertEquals(0.0, $byCurrency->firstWhere('currency', 'USD')['outstanding_total']);
        $this->assertEquals(1, $response->json('commercial_snapshot.awaiting_certification_count'));
        $this->assertEquals('/app/commercial', $response->json('commercial_snapshot.action_url'));
    }

    // ── No-project / empty states ─────────────────────────────────────────

    public function test_organisation_with_no_projects_is_handled(): void
    {
        $org = $this->makeOrg('empty');
        $user = User::factory()->create(['organization_id' => $org->id]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/dashboard/action-centre')->assertStatus(200);

        $this->assertEquals(0, count($response->json('needs_attention.items')));
        $this->assertFalse($response->json('meta.has_projects'));
    }

    public function test_records_without_valid_deadlines_are_excluded(): void
    {
        $org = $this->makeOrg('nodeadline');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);

        $this->makeRfi($project, $user, ['response_due_date' => null]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/dashboard/action-centre')->assertStatus(200);

        $this->assertEquals(0, count($response->json('needs_attention.items')));
    }

    // ── Recent Activity ───────────────────────────────────────────────────

    public function test_recent_activity_is_organisation_scoped(): void
    {
        $orgA = $this->makeOrg('activity-a');
        $userA = User::factory()->create(['organization_id' => $orgA->id, 'name' => 'Alice']);
        $projectA = $this->makeProject($orgA, $userA);

        ProjectActivity::create([
            'organization_id' => $orgA->id, 'project_id' => $projectA->id, 'user_id' => $userA->id,
            'activity_type' => 'document_uploaded', 'title' => 'Uploaded a file',
        ]);

        $orgB = $this->makeOrg('activity-b');
        $userB = User::factory()->create(['organization_id' => $orgB->id]);
        $projectB = $this->makeProject($orgB, $userB);

        ProjectActivity::create([
            'organization_id' => $orgB->id, 'project_id' => $projectB->id, 'user_id' => $userB->id,
            'activity_type' => 'document_uploaded', 'title' => 'Secret Beta activity',
        ]);

        Sanctum::actingAs($userA);
        $response = $this->getJson('/api/dashboard/action-centre')->assertStatus(200);

        $descriptions = collect($response->json('recent_activity'))->pluck('description')->all();
        $this->assertContains('Uploaded a file', $descriptions);
        $this->assertNotContains('Secret Beta activity', $descriptions);
    }

    // ── Project Map ───────────────────────────────────────────────────────

    public function test_project_map_only_includes_projects_with_both_coordinates(): void
    {
        $org = $this->makeOrg('map');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $mapped = $this->makeProject($org, $user, ['name' => 'Mapped Site', 'latitude' => 51.5074, 'longitude' => -0.1278]);
        $this->makeProject($org, $user, ['name' => 'No Coordinates']);
        $this->makeProject($org, $user, ['name' => 'Latitude Only', 'latitude' => 51.5]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/dashboard/action-centre')->assertStatus(200);

        $map = $response->json('project_map');
        $this->assertEquals(3, $map['total_projects']);
        $this->assertEquals(1, $map['mapped_projects']);
        $this->assertCount(1, $map['projects']);
        $this->assertEquals('Mapped Site', $map['projects'][0]['name']);
        $this->assertEquals(51.5074, $map['projects'][0]['latitude']);
        $this->assertEquals(-0.1278, $map['projects'][0]['longitude']);
        $this->assertEquals($mapped->id, $map['projects'][0]['id']);
        $this->assertEquals("/app/projects/{$mapped->id}/overview", $map['projects'][0]['action_url']);
    }

    public function test_project_map_is_tenant_scoped(): void
    {
        $orgA = $this->makeOrg('map-a');
        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $this->makeProject($orgA, $userA, ['name' => 'Visible Site', 'latitude' => 10, 'longitude' => 10]);

        $orgB = $this->makeOrg('map-b');
        $userB = User::factory()->create(['organization_id' => $orgB->id]);
        $this->makeProject($orgB, $userB, ['name' => 'Hidden Site', 'latitude' => 20, 'longitude' => 20]);

        Sanctum::actingAs($userA);
        $response = $this->getJson('/api/dashboard/action-centre')->assertStatus(200);

        $names = collect($response->json('project_map.projects'))->pluck('name')->all();
        $this->assertContains('Visible Site', $names);
        $this->assertNotContains('Hidden Site', $names);
    }

    public function test_project_map_reports_overdue_and_due_soon_counts_from_existing_items(): void
    {
        $org = $this->makeOrg('map-counts');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user, ['latitude' => 1, 'longitude' => 1]);
        $this->makeRfi($project, $user, ['response_due_date' => now()->subDays(1)->toDateString()]);
        $this->makeRfi($project, $user, ['response_due_date' => now()->addDays(1)->toDateString()]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/dashboard/action-centre')->assertStatus(200);

        $marker = $response->json('project_map.projects.0');
        $this->assertEquals(1, $marker['overdue_count']);
        $this->assertEquals(1, $marker['due_soon_count']);
    }
}

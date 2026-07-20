<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Organization;
use App\Models\PaymentApplication;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Rfi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Global Projects Phase 3 — GET /projects/portfolio.
 *
 * Covers: tenant isolation, search/status/attention/currency filtering,
 * deterministic default sorting, attention parity with the Dashboard rule,
 * commercial figures matching CommercialAggregationService, mixed-currency
 * separation, calendar-date semantics, pagination, and navigation URLs.
 */
class ProjectPortfolioTest extends TestCase
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

    // ── Tenant isolation ──────────────────────────────────────────────────

    public function test_client_only_sees_their_own_organisations_projects(): void
    {
        $orgA = $this->makeOrg('a');
        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $this->makeProject($orgA, $userA, ['name' => 'Alpha Tower']);

        $orgB = $this->makeOrg('b');
        $userB = User::factory()->create(['organization_id' => $orgB->id]);
        $this->makeProject($orgB, $userB, ['name' => 'Beta Wharf']);

        Sanctum::actingAs($userA);
        $response = $this->getJson('/api/projects/portfolio')->assertStatus(200);

        $names = collect($response->json('projects.data'))->pluck('name')->all();
        $this->assertContains('Alpha Tower', $names);
        $this->assertNotContains('Beta Wharf', $names);
        $this->assertEquals(1, $response->json('summary.total_projects'));
    }

    // ── Search ────────────────────────────────────────────────────────────

    public function test_search_matches_name_and_reference(): void
    {
        $org = $this->makeOrg('search');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->makeProject($org, $user, ['name' => 'Station Redevelopment', 'code' => 'PRJ-100']);
        $this->makeProject($org, $user, ['name' => 'High Street Fitout', 'code' => 'PRJ-200']);

        Sanctum::actingAs($user);

        $byName = $this->getJson('/api/projects/portfolio?search=station')->assertStatus(200);
        $this->assertCount(1, $byName->json('projects.data'));

        $byRef = $this->getJson('/api/projects/portfolio?search=PRJ-200')->assertStatus(200);
        $this->assertEquals('High Street Fitout', $byRef->json('projects.data.0.name'));
    }

    // ── Status filter ─────────────────────────────────────────────────────

    public function test_status_filter_works(): void
    {
        $org = $this->makeOrg('status');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->makeProject($org, $user, ['status' => 'active']);
        $this->makeProject($org, $user, ['status' => 'completed']);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/projects/portfolio?status=completed')->assertStatus(200);

        $this->assertCount(1, $response->json('projects.data'));
        $this->assertEquals('completed', $response->json('projects.data.0.status'));
    }

    // ── Attention filter and parity with Dashboard rule ───────────────────

    public function test_attention_filter_and_dashboard_parity(): void
    {
        $org = $this->makeOrg('attention');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $urgentProject = $this->makeProject($org, $user, ['name' => 'Urgent Project']);
        $calmProject   = $this->makeProject($org, $user, ['name' => 'Calm Project']);

        $this->makeRfi($urgentProject, $user, ['response_due_date' => now()->subDays(2)->toDateString()]);

        Sanctum::actingAs($user);

        $dashboard = $this->getJson('/api/dashboard/action-centre')->assertStatus(200);
        $portfolio = $this->getJson('/api/projects/portfolio')->assertStatus(200);

        $this->assertEquals(1, $dashboard->json('portfolio_health.projects_with_overdue_items'));
        $this->assertEquals(1, $portfolio->json('summary.projects_requiring_attention'));

        $rows = collect($portfolio->json('projects.data'))->keyBy('name');
        $this->assertTrue($rows['Urgent Project']['attention']['requires_attention']);
        $this->assertEquals(1, $rows['Urgent Project']['attention']['overdue_count']);
        $this->assertFalse($rows['Calm Project']['attention']['requires_attention']);

        $filtered = $this->getJson('/api/projects/portfolio?attention=requires_attention')->assertStatus(200);
        $this->assertCount(1, $filtered->json('projects.data'));
        $this->assertEquals('Urgent Project', $filtered->json('projects.data.0.name'));
    }

    // ── Default sort: attention first ─────────────────────────────────────

    public function test_default_sort_puts_attention_projects_first(): void
    {
        $org = $this->makeOrg('sort');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->makeProject($org, $user, ['name' => 'Aardvark Project']);
        $urgent = $this->makeProject($org, $user, ['name' => 'Zebra Project']);
        $this->makeRfi($urgent, $user, ['response_due_date' => now()->subDays(1)->toDateString()]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/projects/portfolio')->assertStatus(200);

        $this->assertEquals('Zebra Project', $response->json('projects.data.0.name'));
    }

    // ── Commercial figures match CommercialAggregationService ─────────────

    public function test_commercial_figures_match_aggregation_service(): void
    {
        $org = $this->makeOrg('commercial');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user, ['currency' => 'GBP']);
        $this->makeApplication($project, ['status' => 'certified', 'certified_amount' => 5000, 'paid_amount' => 2000]);

        Sanctum::actingAs($user);

        $portfolio = $this->getJson('/api/projects/portfolio')->assertStatus(200);
        $commercial = $this->getJson('/api/commercial/overview')->assertStatus(200);

        $row = $portfolio->json('projects.data.0.commercial');
        $this->assertEquals(5000.0, $row['certified']);
        $this->assertEquals(2000.0, $row['paid']);
        $this->assertEquals(3000.0, $row['outstanding']);
        $this->assertEquals(
            collect($commercial->json('summary'))->sum('outstanding_total'),
            $row['outstanding']
        );
    }

    // ── Mixed currency ─────────────────────────────────────────────────────

    public function test_currency_filter_and_no_mixed_totals(): void
    {
        $org = $this->makeOrg('mixed');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->makeProject($org, $user, ['name' => 'GBP Project', 'currency' => 'GBP']);
        $this->makeProject($org, $user, ['name' => 'USD Project', 'currency' => 'USD']);

        Sanctum::actingAs($user);

        $all = $this->getJson('/api/projects/portfolio')->assertStatus(200);
        $this->assertEqualsCanonicalizing(['GBP', 'USD'], $all->json('filters.currencies'));

        $filtered = $this->getJson('/api/projects/portfolio?currency=USD')->assertStatus(200);
        $this->assertCount(1, $filtered->json('projects.data'));
        $this->assertEquals('USD Project', $filtered->json('projects.data.0.name'));
    }

    // ── Pagination ─────────────────────────────────────────────────────────

    public function test_pagination_metadata_is_correct(): void
    {
        $org = $this->makeOrg('page');
        $user = User::factory()->create(['organization_id' => $org->id]);
        for ($i = 0; $i < 5; $i++) {
            $this->makeProject($org, $user);
        }

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/projects/portfolio?per_page=2&page=2')->assertStatus(200);

        $pagination = $response->json('projects.pagination');
        $this->assertEquals(2, $pagination['current_page']);
        $this->assertEquals(2, $pagination['per_page']);
        $this->assertEquals(5, $pagination['total']);
        $this->assertEquals(3, $pagination['last_page']);
        $this->assertCount(2, $response->json('projects.data'));
    }

    // ── No-project / no-filter-result ──────────────────────────────────────

    public function test_organisation_with_no_projects_is_handled(): void
    {
        $org = $this->makeOrg('empty');
        $user = User::factory()->create(['organization_id' => $org->id]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/projects/portfolio')->assertStatus(200);

        $this->assertEquals(0, $response->json('summary.total_projects'));
        $this->assertCount(0, $response->json('projects.data'));
    }

    public function test_no_filter_results_returns_empty_data_not_error(): void
    {
        $org = $this->makeOrg('nofilter');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->makeProject($org, $user, ['name' => 'Only Project']);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/projects/portfolio?search=nonexistent')->assertStatus(200);

        $this->assertCount(0, $response->json('projects.data'));
        $this->assertEquals(1, $response->json('summary.total_projects'));
    }

    // ── Navigation URLs ─────────────────────────────────────────────────────

    public function test_navigation_urls_are_correct(): void
    {
        $org = $this->makeOrg('nav');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/projects/portfolio')->assertStatus(200);

        $urls = $response->json('projects.data.0.urls');
        $this->assertEquals("/app/projects/{$project->id}/overview", $urls['workspace']);
        $this->assertEquals("/app/projects/{$project->id}/commercial", $urls['commercial']);
        $this->assertEquals("/app/projects/{$project->id}/documents", $urls['documents']);
    }

    // ── Last activity ─────────────────────────────────────────────────────

    public function test_last_activity_reflects_the_most_recent_project_activity(): void
    {
        $org = $this->makeOrg('activity');
        $user = User::factory()->create(['organization_id' => $org->id, 'name' => 'Alice']);
        $project = $this->makeProject($org, $user);

        ProjectActivity::create([
            'organization_id' => $org->id, 'project_id' => $project->id, 'user_id' => $user->id,
            'activity_type' => 'document_uploaded', 'title' => 'First upload',
        ]);
        ProjectActivity::create([
            'organization_id' => $org->id, 'project_id' => $project->id, 'user_id' => $user->id,
            'activity_type' => 'document_uploaded', 'title' => 'Latest upload',
        ]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/projects/portfolio')->assertStatus(200);

        $activity = $response->json('projects.data.0.last_activity');
        $this->assertEquals('Latest upload', $activity['description']);
        $this->assertEquals('Alice', $activity['actor']);
    }

    // ── Calendar-date semantics ─────────────────────────────────────────────

    public function test_completion_date_is_not_shifted_by_timezone(): void
    {
        $org = $this->makeOrg('dates', ['timezone' => 'Pacific/Auckland']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->makeProject($org, $user, ['end_date' => '2026-12-25']);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/projects/portfolio')->assertStatus(200);

        $this->assertEquals('2026-12-25', $response->json('projects.data.0.completion_date'));
    }
}

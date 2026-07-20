<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PaymentApplication;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reports Phase 5 — GET /reports/commercial-summary-report (+ PDF/Excel
 * exports).
 *
 * Covers: tenant isolation, parity with Global Commercial's totals when a
 * period covers all data, date-range filtering, mixed-currency sectioning,
 * metadata completeness, custom range handling, and export responses.
 */
class ReportsCommercialSummaryReportTest extends TestCase
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

    // ── Tenant isolation ──────────────────────────────────────────────────

    public function test_client_only_receives_their_own_organisations_report_data(): void
    {
        $orgA = $this->makeOrg('a');
        $userA = User::factory()->create(['organization_id' => $orgA->id, 'name' => 'Alice']);
        $projectA = $this->makeProject($orgA, $userA, ['name' => 'Alpha Tower']);
        $this->makeApplication($projectA, ['status' => 'certified', 'certified_amount' => 5000]);

        $orgB = $this->makeOrg('b');
        $userB = User::factory()->create(['organization_id' => $orgB->id]);
        $projectB = $this->makeProject($orgB, $userB, ['name' => 'Beta Wharf']);
        $this->makeApplication($projectB, ['status' => 'certified', 'certified_amount' => 99999]);

        Sanctum::actingAs($userA);
        $response = $this->getJson('/api/reports/commercial-summary-report?period=this_month')->assertStatus(200);

        $projectNames = collect($response->json('projects'))->pluck('project_name')->all();
        $this->assertContains('Alpha Tower', $projectNames);
        $this->assertNotContains('Beta Wharf', $projectNames);
        $this->assertEquals('Alice', $response->json('metadata.generated_by'));
    }

    // ── Parity with Global Commercial ────────────────────────────────────

    public function test_report_totals_match_global_commercial_when_period_covers_all_data(): void
    {
        $org = $this->makeOrg('parity');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);

        $this->makeApplication($project, ['status' => 'certified', 'certified_amount' => 4200, 'application_date' => now()->toDateString()]);

        Sanctum::actingAs($user);

        $commercial = $this->getJson('/api/commercial/overview')->assertStatus(200);
        $report     = $this->getJson('/api/reports/commercial-summary-report?period=year')->assertStatus(200);

        $this->assertEquals(
            collect($commercial->json('summary'))->sum('certified_total'),
            collect($report->json('currency_sections'))->sum('financial_position.certified_total'),
            'Reports and Global Commercial must derive certified totals from the same shared aggregation service.'
        );
    }

    // ── Date filtering ────────────────────────────────────────────────────

    public function test_applications_outside_the_period_are_excluded(): void
    {
        $org = $this->makeOrg('period');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);

        $this->makeApplication($project, [
            'status' => 'certified', 'certified_amount' => 1000,
            'application_date' => now()->subMonths(3)->toDateString(),
        ]);
        $this->makeApplication($project, [
            'status' => 'certified', 'certified_amount' => 2000,
            'application_date' => now()->toDateString(),
        ]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/reports/commercial-summary-report?period=this_month')->assertStatus(200);

        $this->assertEquals(2000.0, $response->json('currency_sections.0.financial_position.certified_total'));
    }

    public function test_custom_range_is_honoured_and_reversed_dates_are_swapped(): void
    {
        $org = $this->makeOrg('custom');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);

        $this->makeApplication($project, [
            'status' => 'certified', 'certified_amount' => 1500,
            'application_date' => now()->subDays(10)->toDateString(),
        ]);

        Sanctum::actingAs($user);

        $from = now()->subDays(20)->toDateString();
        $to   = now()->toDateString();

        // Reversed on purpose — from/to swapped.
        $response = $this->getJson("/api/reports/commercial-summary-report?period=custom&from={$to}&to={$from}")->assertStatus(200);

        $this->assertEquals(1500.0, $response->json('currency_sections.0.financial_position.certified_total'));
        $this->assertEquals('Custom Range', $response->json('metadata.period.label'));
    }

    // ── Currency grouping ─────────────────────────────────────────────────

    public function test_mixed_currency_projects_produce_separate_sections(): void
    {
        $org = $this->makeOrg('mixed');
        $user = User::factory()->create(['organization_id' => $org->id]);

        $gbpProject = $this->makeProject($org, $user, ['currency' => 'GBP']);
        $usdProject = $this->makeProject($org, $user, ['currency' => 'USD']);

        $this->makeApplication($gbpProject, ['status' => 'certified', 'certified_amount' => 1000]);
        $this->makeApplication($usdProject, ['status' => 'certified', 'certified_amount' => 2000]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/reports/commercial-summary-report?period=this_month')->assertStatus(200);

        $sections = collect($response->json('currency_sections'));
        $this->assertCount(2, $sections);
        $this->assertEquals(1000.0, $sections->firstWhere('currency', 'GBP')['financial_position']['certified_total']);
        $this->assertEquals(2000.0, $sections->firstWhere('currency', 'USD')['financial_position']['certified_total']);
    }

    // ── Metadata ──────────────────────────────────────────────────────────

    public function test_metadata_contains_all_required_fields(): void
    {
        $org = $this->makeOrg('meta');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->makeProject($org, $user);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/reports/commercial-summary-report?period=this_month')->assertStatus(200);

        $metadata = $response->json('metadata');
        foreach (['report_type', 'organisation', 'period', 'generated_date', 'generated_time', 'effective_timezone', 'generated_by', 'currency_context'] as $key) {
            $this->assertArrayHasKey($key, $metadata);
        }
        $this->assertEquals('Commercial Summary', $metadata['report_type']);
    }

    // ── Exports ───────────────────────────────────────────────────────────

    public function test_pdf_export_returns_a_pdf_response(): void
    {
        $org = $this->makeOrg('pdf');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);
        $this->makeApplication($project, ['status' => 'certified', 'certified_amount' => 1000]);

        Sanctum::actingAs($user);
        $response = $this->get('/api/reports/commercial-summary-report/export/pdf?period=this_month');

        $response->assertStatus(200);
        $this->assertEquals('application/pdf', $response->headers->get('content-type'));
    }

    public function test_excel_export_returns_an_xlsx_response(): void
    {
        $org = $this->makeOrg('excel');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);
        $this->makeApplication($project, ['status' => 'certified', 'certified_amount' => 1000]);

        Sanctum::actingAs($user);
        $response = $this->get('/api/reports/commercial-summary-report/export/excel?period=this_month');

        $response->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
    }

    // ── Existing endpoints unaffected ─────────────────────────────────────

    public function test_existing_reports_summary_endpoint_is_unaffected(): void
    {
        $org = $this->makeOrg('unaffected');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);
        $this->makeApplication($project, ['status' => 'certified', 'certified_amount' => 750]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/reports/summary')->assertStatus(200);

        $this->assertEquals(750.0, $response->json('certified_to_date'));
    }
}

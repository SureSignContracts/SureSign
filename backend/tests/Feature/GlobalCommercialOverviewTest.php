<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Organization;
use App\Models\PaymentApplication;
use App\Models\Project;
use App\Models\RetentionRelease;
use App\Models\User;
use App\Models\Variation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Global Commercial Batch 1 — GET /commercial/overview.
 *
 * Covers: tenant isolation, the canonical certified/paid/outstanding/
 * retention calculations, deadline classification against organisation
 * timezone, mixed-currency handling, and action URL correctness.
 */
class GlobalCommercialOverviewTest extends TestCase
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

    public function test_client_only_receives_their_own_organisations_commercial_data(): void
    {
        $orgA = $this->makeOrg('a');
        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $projectA = $this->makeProject($orgA, $userA, ['name' => 'Alpha Tower']);
        $this->makeApplication($projectA, ['status' => 'certified', 'certified_amount' => 5000, 'paid_amount' => null]);

        $orgB = $this->makeOrg('b');
        $userB = User::factory()->create(['organization_id' => $orgB->id]);
        $projectB = $this->makeProject($orgB, $userB, ['name' => 'Beta Wharf']);
        $this->makeApplication($projectB, ['status' => 'certified', 'certified_amount' => 99999, 'paid_amount' => null]);

        Sanctum::actingAs($userA);
        $response = $this->getJson('/api/commercial/overview')->assertStatus(200);

        $projectNames = collect($response->json('projects'))->pluck('project_name')->all();
        $this->assertContains('Alpha Tower', $projectNames);
        $this->assertNotContains('Beta Wharf', $projectNames);

        $totalCertified = collect($response->json('summary'))->sum('certified_total');
        $this->assertEquals(5000.0, $totalCertified);
    }

    // ── Calculations ──────────────────────────────────────────────────────

    public function test_certified_paid_outstanding_and_retention_totals_use_canonical_rule(): void
    {
        $org = $this->makeOrg('calc');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);

        $this->makeApplication($project, [
            'status' => 'paid', 'certified_amount' => 10000, 'paid_amount' => 8000, 'less_retention' => 1000,
        ]);
        $this->makeApplication($project, [
            'status' => 'certified', 'certified_amount' => 5000, 'paid_amount' => null, 'less_retention' => 500,
        ]);
        // Cancelled applications must never contribute to any total.
        $this->makeApplication($project, [
            'status' => 'cancelled', 'certified_amount' => 999999, 'paid_amount' => 999999, 'less_retention' => 999999,
        ]);

        RetentionRelease::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'release_amount' => 300, 'release_date' => now()->toDateString(), 'release_reason' => 'Practical completion',
        ]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/commercial/overview')->assertStatus(200);

        $summary = $response->json('summary.0');
        $this->assertEquals(15000.0, $summary['certified_total']);
        $this->assertEquals(8000.0, $summary['paid_total']);
        $this->assertEquals(7000.0, $summary['outstanding_total']);
        // withheld (1000+500) - released (300) = 1200
        $this->assertEquals(1200.0, $summary['retention_total']);
    }

    public function test_outstanding_is_not_clamped_when_negative(): void
    {
        $org = $this->makeOrg('neg');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);

        // Paid more than certified (e.g. an adjustment) — outstanding must
        // surface as a genuine negative figure, never clamped to zero.
        $this->makeApplication($project, ['status' => 'paid', 'certified_amount' => 1000, 'paid_amount' => 4000]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/commercial/overview')->assertStatus(200);

        $this->assertEquals(-3000.0, $response->json('summary.0.outstanding_total'));
    }

    public function test_retention_held_is_clamped_at_zero(): void
    {
        $org = $this->makeOrg('clamp');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);

        $this->makeApplication($project, ['status' => 'paid', 'certified_amount' => 1000, 'paid_amount' => 1000, 'less_retention' => 100]);

        RetentionRelease::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'release_amount' => 500, 'release_date' => now()->toDateString(), 'release_reason' => 'Correction',
        ]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/commercial/overview')->assertStatus(200);

        $this->assertEquals(0.0, $response->json('summary.0.retention_total'));
    }

    public function test_variation_pending_and_approved_values_are_distinct(): void
    {
        $org = $this->makeOrg('var');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);

        Variation::create([
            'project_id' => $project->id, 'contract_id' => $contract->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'variation_number' => 'V1', 'title' => 'Extra groundworks', 'status' => 'quoted', 'quoted_amount' => 2000,
        ]);
        Variation::create([
            'project_id' => $project->id, 'contract_id' => $contract->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'variation_number' => 'V2', 'title' => 'Approved change', 'status' => 'approved', 'agreed_amount' => 3000,
        ]);
        Variation::create([
            'project_id' => $project->id, 'contract_id' => $contract->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'variation_number' => 'V3', 'title' => 'Rejected change', 'status' => 'rejected', 'quoted_amount' => 9999,
        ]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/commercial/overview')->assertStatus(200);

        $row = $response->json('projects.0');
        $this->assertEquals(2000.0, $row['pending_variation_value']);
        $this->assertEquals(3000.0, $row['approved_variation_value']);

        $decisions = $response->json('awaiting_action.variations.awaiting_decision');
        $this->assertCount(1, $decisions);
        $this->assertEquals('V1', $decisions[0]['reference']);
    }

    // ── Deadline classification ───────────────────────────────────────────

    public function test_deadlines_are_classified_overdue_due_today_due_soon_and_upcoming(): void
    {
        $org = $this->makeOrg('deadline', ['timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);

        $today = now('Europe/London')->toDateString();

        $this->makeApplication($project, ['status' => 'submitted', 'final_date_for_payment' => now('Europe/London')->subDays(2)->toDateString()]);
        $this->makeApplication($project, ['status' => 'submitted', 'final_date_for_payment' => $today]);
        $this->makeApplication($project, ['status' => 'submitted', 'final_date_for_payment' => now('Europe/London')->addDays(3)->toDateString()]);
        $this->makeApplication($project, ['status' => 'submitted', 'final_date_for_payment' => now('Europe/London')->addDays(20)->toDateString()]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/commercial/overview')->assertStatus(200);

        $this->assertCount(1, $response->json('deadlines.overdue'));
        $this->assertCount(1, $response->json('deadlines.due_today'));
        $this->assertCount(1, $response->json('deadlines.due_soon'));
        $this->assertCount(1, $response->json('deadlines.upcoming'));
        $this->assertEquals(7, $response->json('deadlines.due_soon_threshold_days'));
    }

    public function test_paid_and_cancelled_applications_do_not_generate_deadline_items(): void
    {
        $org = $this->makeOrg('resolved');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);

        $this->makeApplication($project, ['status' => 'paid', 'final_date_for_payment' => now()->subDays(10)->toDateString()]);
        $this->makeApplication($project, ['status' => 'cancelled', 'final_date_for_payment' => now()->subDays(10)->toDateString()]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/commercial/overview')->assertStatus(200);

        $this->assertCount(0, $response->json('deadlines.overdue'));
    }

    // ── Currency ──────────────────────────────────────────────────────────

    public function test_mixed_currency_projects_are_never_summed_together(): void
    {
        $org = $this->makeOrg('mixed');
        $user = User::factory()->create(['organization_id' => $org->id]);

        $gbpProject = $this->makeProject($org, $user, ['currency' => 'GBP']);
        $usdProject = $this->makeProject($org, $user, ['currency' => 'USD']);

        $this->makeApplication($gbpProject, ['status' => 'certified', 'certified_amount' => 1000]);
        $this->makeApplication($usdProject, ['status' => 'certified', 'certified_amount' => 2000]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/commercial/overview')->assertStatus(200);

        $summary = collect($response->json('summary'));
        $this->assertCount(2, $summary, 'One cash-position block per currency, never merged.');

        $gbp = $summary->firstWhere('currency', 'GBP');
        $usd = $summary->firstWhere('currency', 'USD');
        $this->assertEquals(1000.0, $gbp['certified_total']);
        $this->assertEquals(2000.0, $usd['certified_total']);
    }

    // ── Action URLs ───────────────────────────────────────────────────────

    public function test_deadline_items_and_project_rows_link_to_the_correct_project(): void
    {
        $org = $this->makeOrg('links');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);

        $this->makeApplication($project, ['status' => 'submitted', 'final_date_for_payment' => now()->toDateString()]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/commercial/overview')->assertStatus(200);

        $deadline = $response->json('deadlines.due_today.0');
        $this->assertStringContainsString("/app/projects/{$project->id}/", $deadline['action_url']);

        $row = $response->json('projects.0');
        $this->assertEquals("/app/projects/{$project->id}/commercial", $row['action_url']);
    }

    // ── Reports parity ────────────────────────────────────────────────────

    public function test_reports_summary_and_commercial_overview_agree_on_certified_total(): void
    {
        $org = $this->makeOrg('parity');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);

        $this->makeApplication($project, ['status' => 'certified', 'certified_amount' => 4200]);

        Sanctum::actingAs($user);

        $reports    = $this->getJson('/api/reports/summary')->assertStatus(200);
        $commercial = $this->getJson('/api/commercial/overview')->assertStatus(200);

        $this->assertEquals(
            $reports->json('certified_to_date'),
            collect($commercial->json('summary'))->sum('certified_total'),
            'Reports and Global Commercial must derive certified totals from the same shared aggregation service.'
        );
    }
}

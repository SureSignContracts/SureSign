<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\AI\AiCreditSimulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
 * Deliberately does NOT use Storage::fake('local') — this environment has a
 * pre-existing, unrelated root-owned leftover directory under
 * storage/framework/testing/disks/local/contracts that Storage::fake()'s own
 * cleanup step cannot remove (permission denied), which fails ANY test using
 * that fake regardless of what it's testing (confirmed against unrelated
 * pre-existing tests, e.g. SupportTicketControllerTest, hitting the same
 * error). These tests write to a scoped subpath on the REAL local disk and
 * delete it in tearDown() instead.
 */

/**
 * Phase G4C.2D, Workstream 3 — the Commercial Approval Pack command.
 * Confirms: it generates a markdown report from existing telemetry/
 * simulation data only (no provider call, no mutation), and that it never
 * states or implies an approved commercial rate regardless of how much
 * data is fed into it.
 */
class AiCreditCalibrationReportTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_ROOT = 'test-internal-reports';

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory(self::TEST_ROOT);
        parent::tearDown();
    }

    private function makeAnalysis(Organization $org, User $user, float $cost, ?string $documentHash = null): ContractAiAnalysis
    {
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P' . uniqid()]);
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id,
            'created_by' => $user->id, 'type' => 'main_contract', 'title' => 'C',
        ]);

        return ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'completed', 'workflow' => 'contract_analysis',
            'model' => 'claude-sonnet-5', 'provider' => 'anthropic', 'provider_called' => true,
            // G4C.2G — a real analysis always has a document_hash by the time
            // it's terminal; a distinct default per call keeps unrelated test
            // fixtures from colliding into the same "document".
            'document_hash' => $documentHash ?? hash('sha256', uniqid('doc-', true)),
            'estimated_cost' => $cost, 'completed_at' => now(),
        ]);
    }

    public function test_report_is_written_to_the_local_disk_with_observed_facts(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $analysis = $this->makeAnalysis($org, $user, 1.5);

        app(AiCreditSimulator::class)->simulate($analysis, 'contract_analysis', 25000, now(), AiCreditSimulator::SOURCE_PROSPECTIVE);

        $path = self::TEST_ROOT . '/observed-facts.md';
        $this->artisan('ai:credits:calibration-report', ['--output' => $path])->assertSuccessful();

        $content = Storage::disk('local')->get($path);

        $this->assertStringContainsString('AI Credit Commercial Calibration Report', $content);
        $this->assertStringContainsString('candidate_a', $content);
        $this->assertStringContainsString('This report makes **no commercial rate recommendation**', $content);
    }

    public function test_report_never_recommends_a_rate_even_with_no_data(): void
    {
        $path = self::TEST_ROOT . '/no-data.md';
        $this->artisan('ai:credits:calibration-report', ['--output' => $path])->assertSuccessful();

        $content = Storage::disk('local')->get($path);

        $this->assertStringContainsString('no simulation data in this window', $content);
        $this->assertStringContainsString('This report makes **no commercial rate recommendation**', $content);
    }

    public function test_output_option_writes_to_a_custom_path(): void
    {
        $path = self::TEST_ROOT . '/custom/report.md';
        $this->artisan('ai:credits:calibration-report', ['--output' => $path])->assertSuccessful();

        $this->assertTrue(Storage::disk('local')->exists($path));
    }

    // ── Phase G4C.2E, Parts 3 & 5 — Founder Approval Package + Readiness Gate ──

    public function test_report_contains_founder_approval_package_sections(): void
    {
        $path = self::TEST_ROOT . '/founder-package.md';
        $this->artisan('ai:credits:calibration-report', ['--output' => $path])->assertSuccessful();

        $content = Storage::disk('local')->get($path);

        $this->assertStringContainsString('## 2. Founder Approval Package', $content);
        $this->assertStringContainsString('### Commercial Risks', $content);
        $this->assertStringContainsString('### Recommended Next Steps', $content);
        $this->assertStringContainsString('### Unknowns', $content);
        $this->assertStringContainsString('### Founder Decisions Required', $content);
        $this->assertStringContainsString('### Approval Status', $content);
        $this->assertStringContainsString('Not submitted.', $content);
    }

    public function test_report_contains_readiness_gate_with_all_ten_requirements(): void
    {
        $path = self::TEST_ROOT . '/readiness-gate.md';
        $this->artisan('ai:credits:calibration-report', ['--output' => $path])->assertSuccessful();

        $content = Storage::disk('local')->get($path);

        $this->assertStringContainsString('## 3. G4C.3 Readiness Gate', $content);
        $this->assertStringContainsString('Founder Approval', $content);
        $this->assertStringContainsString('Entitlement Migration Readiness', $content);
        $this->assertStringContainsString('Trade Package Coverage', $content);
        $this->assertStringContainsString('Organization Diversity', $content);
        $this->assertStringContainsString('**Overall status:** BLOCKED', $content);
        $this->assertStringContainsString('G4C.3 remains blocked', $content);
    }

    public function test_report_never_reproduces_the_superseded_four_item_checklist(): void
    {
        $path = self::TEST_ROOT . '/no-old-checklist.md';
        $this->artisan('ai:credits:calibration-report', ['--output' => $path])->assertSuccessful();

        $content = Storage::disk('local')->get($path);

        $this->assertStringNotContainsString('Calibration Readiness Checklist', $content);
    }

    public function test_simulation_coverage_section_reports_percentage(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $analysis = $this->makeAnalysis($org, $user, 1.0);
        app(AiCreditSimulator::class)->simulate($analysis, 'contract_analysis', 15000, now(), AiCreditSimulator::SOURCE_PROSPECTIVE);

        $path = self::TEST_ROOT . '/simulation-coverage.md';
        $this->artisan('ai:credits:calibration-report', ['--output' => $path])->assertSuccessful();

        $content = Storage::disk('local')->get($path);

        $this->assertStringContainsString('### Simulation Coverage', $content);
        $this->assertStringContainsString('100%', $content);
    }
}

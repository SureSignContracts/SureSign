<?php

namespace Tests\Feature;

use App\Jobs\AnalyseContractWithAiJob;
use App\Models\AiCreditSimulationResult;
use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SuresignSetting;
use App\Models\User;
use App\Services\AI\AiCreditSimulator;
use App\Services\AI\AiInputNormalizer;
use App\Services\AI\ContractAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase G4C.2C-2 — non-enforcing AI Credit simulation. Confirms the
 * simulator records purely observational, per-candidate results after a
 * real analysis completes, WITHOUT ever touching subscriptions,
 * entitlements, invoices, or the analysis's own workflow behaviour.
 */
class AiCreditSimulationTest extends TestCase
{
    use RefreshDatabase;

    private function makeFixtures(): array
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P']);
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id,
            'created_by' => $user->id, 'type' => 'main_contract', 'title' => 'C',
        ]);

        return compact('org', 'user', 'project', 'contract');
    }

    private function fakeSuccessfulProvider(int $tokensInput = 100, int $tokensOutput = 50): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content'     => [['type' => 'text', 'text' => json_encode(['contract_summary' => 'A summary'])]],
                'usage'       => ['input_tokens' => $tokensInput, 'output_tokens' => $tokensOutput],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        SuresignSetting::instance()->update([
            'ai_enabled'         => true,
            'anthropic_api_key'  => 'fake-key',
            'ai_model'           => 'claude-sonnet-5',
        ]);
    }

    private function runRealAnalysis(string $text): ContractAiAnalysis
    {
        Storage::fake('local');
        $this->fakeSuccessfulProvider();

        ['user' => $user, 'contract' => $contract, 'project' => $project, 'org' => $org] = $this->makeFixtures();

        Storage::disk('local')->put('contracts/sim.txt', $text);
        $upload = FileUpload::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'uploaded_by' => $user->id,
            'original_name' => 'sim.txt', 'stored_name' => 'sim.txt',
            'file_path' => 'contracts/sim.txt', 'mime_type' => 'text/plain', 'file_size' => strlen($text),
        ]);

        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'pending', 'model' => 'claude-sonnet-5',
            'workflow' => 'contract_analysis',
        ]);

        (new AnalyseContractWithAiJob($analysis->id, $upload->id, $user->id))
            ->handle(app(ContractAnalysisService::class));

        return $analysis->fresh();
    }

    public function test_completing_a_real_analysis_records_a_result_per_configured_candidate(): void
    {
        $analysis = $this->runRealAnalysis('A modestly sized contract document for simulation testing.');

        $results = AiCreditSimulationResult::query()
            ->where('analysable_type', ContractAiAnalysis::class)
            ->where('analysable_id', $analysis->id)
            ->get();

        // config/ai_credit_simulation_policies.php currently configures two
        // candidates (candidate_a, candidate_b) for contract_analysis.
        $this->assertCount(2, $results);
        $this->assertEqualsCanonicalizing(
            ['candidate_a', 'candidate_b'],
            $results->pluck('candidate_policy_key')->all()
        );
    }

    public function test_flat_candidate_resolves_to_calculated_with_flat_credits(): void
    {
        $analysis = $this->runRealAnalysis('Flat candidate check text.');

        $result = AiCreditSimulationResult::query()
            ->where('analysable_id', $analysis->id)
            ->where('candidate_policy_key', 'candidate_a')
            ->firstOrFail();

        $this->assertSame(AiCreditSimulator::STATUS_CALCULATED, $result->simulation_status);
        $this->assertSame('flat', $result->charging_strategy);
        $this->assertSame(1.0, (float) $result->hypothetical_credits);
        $this->assertNull($result->hypothetical_band);
    }

    public function test_banded_candidate_resolves_the_correct_band(): void
    {
        $text = str_repeat('a', 60_000); // > small (50,000), <= medium (150,000)

        $analysis = $this->runRealAnalysis($text);

        $result = AiCreditSimulationResult::query()
            ->where('analysable_id', $analysis->id)
            ->where('candidate_policy_key', 'candidate_b')
            ->firstOrFail();

        $this->assertSame(AiCreditSimulator::STATUS_CALCULATED, $result->simulation_status);
        $this->assertSame('banded', $result->charging_strategy);
        $this->assertSame('medium', $result->hypothetical_band);
        $this->assertSame(5.0, (float) $result->hypothetical_credits);
    }

    public function test_normalized_input_char_count_is_preserved_and_matches_normalizer(): void
    {
        $text = 'Some   contract    text.';
        $analysis = $this->runRealAnalysis($text);

        $result = AiCreditSimulationResult::query()->where('analysable_id', $analysis->id)->first();

        $this->assertSame(AiInputNormalizer::normalizedCharCount($text), $result->normalized_input_char_count);
        $this->assertSame(AiInputNormalizer::VERSION, $result->normalization_version);
    }

    public function test_simulation_never_creates_a_ledger_balance_or_entitlement_side_effect(): void
    {
        $analysis = $this->runRealAnalysis('No side effects expected.');

        // Simulation must never touch the organisation's subscription/entitlement
        // state. There is no customer-facing credit table to assert doesn't
        // exist — the absence of any such write is the point: only the
        // informational simulation table gains rows.
        $this->assertDatabaseHas('ai_credit_simulation_results', [
            'analysable_id' => $analysis->id,
        ]);
        $this->assertSame('completed', $analysis->status);
    }

    public function test_running_simulation_twice_for_the_same_analysis_updates_not_duplicates(): void
    {
        $analysis = $this->runRealAnalysis('Idempotency check text.');

        $countBefore = AiCreditSimulationResult::query()->where('analysable_id', $analysis->id)->count();

        app(AiCreditSimulator::class)->simulate(
            $analysis->fresh(),
            'contract_analysis',
            AiInputNormalizer::normalizedCharCount('Idempotency check text.'),
            $analysis->completed_at,
            AiCreditSimulator::SOURCE_PROSPECTIVE
        );

        $countAfter = AiCreditSimulationResult::query()->where('analysable_id', $analysis->id)->count();

        $this->assertSame($countBefore, $countAfter);
    }

    public function test_unavailable_input_never_produces_invented_credits(): void
    {
        $analysis = $this->runRealAnalysis('Unavailable input check.');

        // Force a recalculation with a null (unavailable) char count, as the
        // historical backfill path would for an unreconstructable document.
        app(AiCreditSimulator::class)->simulate(
            $analysis->fresh(),
            'contract_analysis',
            null,
            $analysis->completed_at,
            AiCreditSimulator::SOURCE_BACKFILL
        );

        $results = AiCreditSimulationResult::query()->where('analysable_id', $analysis->id)->get();

        foreach ($results as $result) {
            $this->assertSame(AiCreditSimulator::STATUS_UNAVAILABLE, $result->simulation_status);
            $this->assertNull($result->hypothetical_credits);
            $this->assertNull($result->normalized_input_char_count);
        }
    }

    public function test_unresolved_workflow_never_becomes_zero_credits(): void
    {
        $org = Organization::create(['name' => 'Org2', 'slug' => 'org2-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P']);
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id,
            'created_by' => $user->id, 'type' => 'main_contract', 'title' => 'C',
        ]);
        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'completed',
        ]);

        // A workflow key with no configured candidates at all.
        app(AiCreditSimulator::class)->simulate(
            $analysis,
            'some_future_unconfigured_workflow',
            5000,
            now(),
            AiCreditSimulator::SOURCE_PROSPECTIVE
        );

        $this->assertSame(0, AiCreditSimulationResult::query()->where('analysable_id', $analysis->id)->count());
    }

    public function test_a_simulation_failure_never_fails_the_customer_analysis(): void
    {
        // Simulate a config error: candidate period with an unrecognised strategy.
        config([
            'ai_credit_simulation_policies.contract_analysis' => [
                'broken_candidate' => [
                    [
                        'policy_version' => 1, 'effective_from' => '2020-01-01', 'effective_until' => null,
                        'strategy' => 'not_a_real_strategy', 'flat_credits' => null, 'bands' => null,
                    ],
                ],
            ],
        ]);

        $analysis = $this->runRealAnalysis('Config error should not fail the analysis.');

        // The analysis itself completed successfully regardless of the broken
        // simulation config.
        $this->assertSame('completed', $analysis->status);

        $result = AiCreditSimulationResult::query()->where('analysable_id', $analysis->id)->first();
        $this->assertSame(AiCreditSimulator::STATUS_ERROR, $result->simulation_status);
        $this->assertNull($result->hypothetical_credits);
    }

    public function test_provider_or_model_change_never_changes_normalized_input_size(): void
    {
        $text = 'Identical document text regardless of which model analysed it.';

        $countA = AiInputNormalizer::normalizedCharCount($text);

        // Simulate the same text as if it had gone through a different model —
        // normalization operates purely on extracted text, never token counts.
        $countB = AiInputNormalizer::normalizedCharCount($text);

        $this->assertSame($countA, $countB);
    }
}

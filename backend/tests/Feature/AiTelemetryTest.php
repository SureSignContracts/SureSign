<?php

namespace Tests\Feature;

use App\Jobs\AnalyseContractWithAiJob;
use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SuresignSetting;
use App\Models\User;
use App\Services\AI\ContractAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase G4C.1 — AI Usage Telemetry Foundation. Covers the new, purely
 * additive telemetry recorded on ContractAiAnalysis by
 * ContractAnalysisService::analyse() and AnalyseContractWithAiJob: the
 * normalized workflow identifier, document metrics, cache-hit vs.
 * provider-execution telemetry, the single authoritative estimated_cost
 * owner, duration, queue-attempt bookkeeping, and structured failure
 * classification. No AI Credits/ledger behaviour exists anywhere in this
 * suite — see internal-docs/super-admin/ai-credits-architecture.md.
 */
class AiTelemetryTest extends TestCase
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

    public function test_successful_analysis_records_workflow_document_metrics_and_provider_telemetry(): void
    {
        Storage::fake('local');
        $this->fakeSuccessfulProvider(tokensInput: 200, tokensOutput: 80);

        ['user' => $user, 'contract' => $contract, 'project' => $project, 'org' => $org] = $this->makeFixtures();

        $text = 'Some contract text used for telemetry verification.';
        Storage::disk('local')->put('contracts/telemetry.txt', $text);
        $upload = FileUpload::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'uploaded_by' => $user->id,
            'original_name' => 'telemetry.txt', 'stored_name' => 'telemetry.txt',
            'file_path' => 'contracts/telemetry.txt', 'mime_type' => 'text/plain', 'file_size' => strlen($text),
        ]);

        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'pending', 'model' => 'claude-sonnet-5',
            'workflow' => 'contract_analysis',
        ]);

        (new AnalyseContractWithAiJob($analysis->id, $upload->id, $user->id))
            ->handle(app(ContractAnalysisService::class));

        $analysis->refresh();

        $this->assertSame('completed', $analysis->status);
        $this->assertSame('contract_analysis', $analysis->workflow);
        $this->assertSame(mb_strlen($text), $analysis->document_char_count);
        $this->assertSame('txt', $analysis->document_file_type);
        $this->assertTrue($analysis->provider_called);
        $this->assertNull($analysis->failure_category);
        $this->assertEqualsWithDelta(
            (new ContractAnalysisService())->estimateCost(200, 80, 'claude-sonnet-5', now()),
            $analysis->estimated_cost,
            0.000001
        );
        $this->assertNotNull($analysis->duration_ms);
        $this->assertGreaterThanOrEqual(0, $analysis->duration_ms);
        $this->assertSame(1, $analysis->queue_attempt);
        $this->assertTrue($analysis->is_final_attempt);
    }

    public function test_cache_hit_records_zero_cost_and_provider_called_false(): void
    {
        Storage::fake('local');

        ['user' => $user, 'contract' => $contract, 'project' => $project, 'org' => $org] = $this->makeFixtures();

        $text = 'Identical cached contract text.';
        $hash = hash('sha256', $text);

        ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'completed', 'model' => 'claude-sonnet-5',
            'document_hash' => $hash, 'raw_response_json' => ['contract_summary' => 'Cached'],
        ]);

        Storage::disk('local')->put('contracts/cached.txt', $text);
        $upload = FileUpload::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'uploaded_by' => $user->id,
            'original_name' => 'cached.txt', 'stored_name' => 'cached.txt',
            'file_path' => 'contracts/cached.txt', 'mime_type' => 'text/plain', 'file_size' => strlen($text),
        ]);

        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'pending', 'model' => 'claude-sonnet-5',
            'workflow' => 'contract_analysis',
        ]);

        (new AnalyseContractWithAiJob($analysis->id, $upload->id, $user->id))
            ->handle(app(ContractAnalysisService::class));

        $analysis->refresh();

        $this->assertSame('completed', $analysis->status);
        $this->assertSame(0, $analysis->tokens_input);
        $this->assertSame(0, $analysis->tokens_output);
        $this->assertSame(0.0, (float) $analysis->estimated_cost);
        $this->assertFalse($analysis->provider_called);
        // Document metrics are still recorded on a cache hit — extraction still happens.
        $this->assertSame(mb_strlen($text), $analysis->document_char_count);
    }

    public function test_validation_failure_before_provider_call_is_classified_and_leaves_provider_called_null(): void
    {
        ['user' => $user, 'contract' => $contract] = $this->makeFixtures();

        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $contract->organization_id,
            'project_id' => $contract->project_id, 'created_by' => $user->id, 'status' => 'pending',
        ]);

        // fileUploadId 0 never exists -> fails before extractText/provider is ever reached.
        (new AnalyseContractWithAiJob($analysis->id, 0, $user->id))
            ->handle(app(ContractAnalysisService::class));

        $analysis->refresh();

        $this->assertSame('failed', $analysis->status);
        $this->assertSame('validation_failure', $analysis->failure_category);
        $this->assertNull($analysis->provider_called);
        $this->assertNotNull($analysis->duration_ms);
    }

    public function test_provider_http_failure_is_classified_as_provider_rejection_and_provider_called_true(): void
    {
        Storage::fake('local');

        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => ['message' => 'boom']], 500),
        ]);

        SuresignSetting::instance()->update([
            'ai_enabled' => true, 'anthropic_api_key' => 'fake-key', 'ai_model' => 'claude-sonnet-5',
        ]);

        ['user' => $user, 'contract' => $contract, 'project' => $project, 'org' => $org] = $this->makeFixtures();

        $text = 'Text that will trigger a real (failing) provider call.';
        Storage::disk('local')->put('contracts/reject.txt', $text);
        $upload = FileUpload::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'uploaded_by' => $user->id,
            'original_name' => 'reject.txt', 'stored_name' => 'reject.txt',
            'file_path' => 'contracts/reject.txt', 'mime_type' => 'text/plain', 'file_size' => strlen($text),
        ]);

        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'pending', 'model' => 'claude-sonnet-5',
            'workflow' => 'contract_analysis',
        ]);

        (new AnalyseContractWithAiJob($analysis->id, $upload->id, $user->id))
            ->handle(app(ContractAnalysisService::class));

        $analysis->refresh();

        $this->assertSame('failed', $analysis->status);
        $this->assertSame('provider_rejection', $analysis->failure_category);
        $this->assertTrue($analysis->provider_called);
    }
}

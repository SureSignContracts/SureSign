<?php

namespace Tests\Feature;

use App\Jobs\AnalyseTradePackageWithAiJob;
use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SuresignSetting;
use App\Models\TradePackage;
use App\Models\TradePackageAiAnalysis;
use App\Models\User;
use App\Services\AI\ContractAnalysisService;
use App\Services\AI\TradePackageAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase G4C.1 — mirrors AiTelemetryTest for the Trade Package (subcontract)
 * AI Analysis workflow, confirming the two real provider-backed workflows
 * record telemetry consistently with each other (see
 * internal-docs/super-admin/ai-credits-architecture.md §6).
 */
class TradePackageAiTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private function makeFixtures(): array
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P']);
        $tradePackage = TradePackage::create([
            'organization_id' => $org->id, 'project_id' => $project->id,
            'name' => 'Package', 'slug' => 'package-' . uniqid(), 'created_by' => $user->id,
        ]);

        return compact('org', 'user', 'project', 'tradePackage');
    }

    public function test_successful_analysis_records_workflow_and_document_metrics(): void
    {
        Storage::fake('local');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content'     => [['type' => 'text', 'text' => json_encode(['general' => ['subcontract_title' => 'Sub']])]],
                'usage'       => ['input_tokens' => 300, 'output_tokens' => 120],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        SuresignSetting::instance()->update([
            'ai_enabled' => true, 'anthropic_api_key' => 'fake-key', 'ai_model' => 'claude-sonnet-5',
        ]);

        ['user' => $user, 'tradePackage' => $tradePackage, 'org' => $org, 'project' => $project] = $this->makeFixtures();

        $text = 'Subcontract text used for telemetry verification.';
        Storage::disk('local')->put('trade-packages/telemetry.txt', $text);
        $upload = FileUpload::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'uploaded_by' => $user->id,
            'trade_package_id' => $tradePackage->id,
            'original_name' => 'telemetry.txt', 'stored_name' => 'telemetry.txt',
            'file_path' => 'trade-packages/telemetry.txt', 'mime_type' => 'text/plain', 'file_size' => strlen($text),
        ]);

        $analysis = TradePackageAiAnalysis::create([
            'trade_package_id' => $tradePackage->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'pending', 'model' => 'claude-sonnet-5',
            'workflow' => 'trade_package_analysis',
        ]);

        (new AnalyseTradePackageWithAiJob($analysis->id, $upload->id, $user->id))
            ->handle(app(TradePackageAnalysisService::class));

        $analysis->refresh();

        $this->assertSame('completed', $analysis->status);
        $this->assertSame('trade_package_analysis', $analysis->workflow);
        $this->assertSame(mb_strlen($text), $analysis->document_char_count);
        $this->assertSame('txt', $analysis->document_file_type);
        $this->assertTrue($analysis->provider_called);
        $this->assertEqualsWithDelta(
            (new ContractAnalysisService())->estimateCost(300, 120, 'claude-sonnet-5', now()),
            $analysis->estimated_cost,
            0.000001
        );
        $this->assertNotNull($analysis->duration_ms);
        $this->assertSame(1, $analysis->queue_attempt);
        $this->assertTrue($analysis->is_final_attempt);
        $this->assertNull($analysis->failure_category);
    }

    public function test_validation_failure_is_classified_and_provider_never_called(): void
    {
        ['user' => $user, 'tradePackage' => $tradePackage] = $this->makeFixtures();

        $analysis = TradePackageAiAnalysis::create([
            'trade_package_id' => $tradePackage->id, 'organization_id' => $tradePackage->organization_id,
            'project_id' => $tradePackage->project_id, 'created_by' => $user->id, 'status' => 'pending',
        ]);

        (new AnalyseTradePackageWithAiJob($analysis->id, 0, $user->id))
            ->handle(app(TradePackageAnalysisService::class));

        $analysis->refresh();

        $this->assertSame('failed', $analysis->status);
        $this->assertSame('validation_failure', $analysis->failure_category);
        $this->assertNull($analysis->provider_called);
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\AnalyseContractWithAiJob;
use App\Jobs\AnalyseTradePackageWithAiJob;
use App\Models\AiCreditLedgerEntry;
use App\Models\AiCreditSimulationResult;
use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SuresignSetting;
use App\Models\TradePackage;
use App\Models\TradePackageAiAnalysis;
use App\Models\User;
use App\Services\AI\AiCreditBalanceService;
use App\Services\AI\AiCreditLedgerService;
use App\Services\AI\ContractAnalysisService;
use App\Services\AI\TradePackageAnalysisService;
use App\Support\AI\AiCreditOperatingMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase G4C.3BC — AI Credits workflow integration. Confirms the reserve →
 * settle/release lifecycle is correctly wired into both real AI workflows,
 * remains commercially dormant (shadow mode never blocks AI execution),
 * and never leaves an orphaned reservation across every exit path.
 */
class AiCreditWorkflowIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function makeContractFixtures(): array
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

    private function makeTradePackageFixtures(): array
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P']);
        $tradePackage = TradePackage::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'name' => 'TP', 'slug' => 'tp-' . uniqid(),
        ]);

        return compact('org', 'user', 'project', 'tradePackage');
    }

    private function uploadText(Organization $org, User $user, Project $project, string $text, string $name = 'doc.txt'): FileUpload
    {
        Storage::disk('local')->put("contracts/{$name}", $text);

        return FileUpload::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'uploaded_by' => $user->id,
            'original_name' => $name, 'stored_name' => $name,
            'file_path' => "contracts/{$name}", 'mime_type' => 'text/plain', 'file_size' => strlen($text),
        ]);
    }

    private function fakeSuccessfulProvider(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content'     => [['type' => 'text', 'text' => json_encode(['contract_summary' => 'A summary', 'general' => ['subcontract_title' => 'A package']])]],
                'usage'       => ['input_tokens' => 100, 'output_tokens' => 50],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        SuresignSetting::instance()->update([
            'ai_enabled' => true, 'anthropic_api_key' => 'fake-key', 'ai_model' => 'claude-sonnet-5',
        ]);
    }

    private function enableShadowCandidate(string $key = 'candidate_a'): void
    {
        config(['ai_credit_shadow.active_candidate' => $key]);
    }

    private function balanceFor(int $organizationId): array
    {
        return app(AiCreditBalanceService::class)->balanceFor($organizationId);
    }

    // ── Contract Analysis ────────────────────────────────────────────────

    public function test_contract_success_reserves_then_settles_leaving_available_unchanged(): void
    {
        Storage::fake('local');
        $this->fakeSuccessfulProvider();
        $this->enableShadowCandidate();

        ['user' => $user, 'contract' => $contract, 'project' => $project, 'org' => $org] = $this->makeContractFixtures();
        app(AiCreditLedgerService::class)->grant($org->id, 100, 'Test grant', 'grant-' . uniqid(), $user->id);

        $upload = $this->uploadText($org, $user, $project, 'Some contract text for shadow lifecycle verification.');
        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'pending', 'model' => 'claude-sonnet-5', 'workflow' => 'contract_analysis',
        ]);

        (new AnalyseContractWithAiJob($analysis->id, $upload->id, $user->id))->handle(app(ContractAnalysisService::class));

        $analysis->refresh();
        $this->assertSame('completed', $analysis->status);
        $this->assertSame('sufficient', $analysis->shadow_enforcement_result);
        $this->assertNotNull($analysis->credit_reservation_amount);

        $balance = $this->balanceFor($org->id);
        $this->assertSame(0.0, $balance['reserved'], 'No orphaned reservation after a successful settle.');
        // available = issued - consumed - reserved = 100 - amount - 0. This must NOT
        // bounce back up to 100 — that was exactly the G4C.3A accounting bug.
        $this->assertEqualsWithDelta(100.0 - (float) $analysis->credit_reservation_amount, $balance['available'], 0.001);
        $this->assertSame((float) $analysis->credit_reservation_amount, $balance['consumed']);

        $this->assertSame(1, AiCreditLedgerEntry::where('reference_id', $analysis->id)->where('transaction_type', 'reserve')->count());
        $this->assertSame(1, AiCreditLedgerEntry::where('reference_id', $analysis->id)->where('transaction_type', 'settle')->count());
    }

    public function test_contract_provider_failure_releases_the_reservation(): void
    {
        Storage::fake('local');
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);
        SuresignSetting::instance()->update(['ai_enabled' => true, 'anthropic_api_key' => 'fake-key', 'ai_model' => 'claude-sonnet-5']);
        $this->enableShadowCandidate();

        ['user' => $user, 'contract' => $contract, 'project' => $project, 'org' => $org] = $this->makeContractFixtures();
        app(AiCreditLedgerService::class)->grant($org->id, 100, 'Test grant', 'grant-' . uniqid(), $user->id);

        $upload = $this->uploadText($org, $user, $project, 'Text that triggers a real, failing provider call.');
        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'pending', 'model' => 'claude-sonnet-5', 'workflow' => 'contract_analysis',
        ]);

        (new AnalyseContractWithAiJob($analysis->id, $upload->id, $user->id))->handle(app(ContractAnalysisService::class));

        $analysis->refresh();
        $this->assertSame('failed', $analysis->status);

        $balance = $this->balanceFor($org->id);
        $this->assertSame(0.0, $balance['reserved'], 'A released reservation leaves nothing outstanding.');
        $this->assertSame(0.0, $balance['consumed'], 'A failure must never settle — nothing was actually delivered.');
        $this->assertSame(100.0, $balance['available'], 'A release must fully restore the reserved amount.');
        $this->assertSame(1, AiCreditLedgerEntry::where('reference_id', $analysis->id)->where('transaction_type', 'release')->count());
    }

    public function test_contract_validation_failure_before_extraction_never_opens_a_reservation(): void
    {
        $this->enableShadowCandidate();
        ['user' => $user, 'contract' => $contract] = $this->makeContractFixtures();

        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $contract->organization_id,
            'project_id' => $contract->project_id, 'created_by' => $user->id, 'status' => 'pending',
        ]);

        // fileUploadId 0 never exists -> fails before extraction/reservation is ever reached.
        (new AnalyseContractWithAiJob($analysis->id, 0, $user->id))->handle(app(ContractAnalysisService::class));

        $analysis->refresh();
        $this->assertSame('failed', $analysis->status);
        $this->assertNull($analysis->credit_reservation_amount);
        $this->assertSame(0, AiCreditLedgerEntry::where('reference_id', $analysis->id)->count());
    }

    public function test_contract_timeout_via_failed_handler_releases_and_recovers_the_stuck_analysis(): void
    {
        Storage::fake('local');
        $this->enableShadowCandidate();

        ['user' => $user, 'contract' => $contract, 'project' => $project, 'org' => $org] = $this->makeContractFixtures();
        app(AiCreditLedgerService::class)->grant($org->id, 100, 'Test grant', 'grant-' . uniqid(), $user->id);

        $upload = $this->uploadText($org, $user, $project, 'Text for a simulated timeout scenario.');
        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'pending', 'model' => 'claude-sonnet-5', 'workflow' => 'contract_analysis',
        ]);

        // Simulate the reservation having already opened (as handle() would have done)
        // then the queue worker killing the process before handle() could finish —
        // Laravel then calls failed() directly, never handle()'s own catch block.
        app(AiCreditLedgerService::class)->reserve($org->id, 'contract_analysis', ContractAiAnalysis::class, $analysis->id, 10, 'Reserved before timeout', 'contract_analysis:reserve:' . $analysis->id);
        $analysis->update(['status' => 'processing', 'started_at' => now(), 'credit_reservation_amount' => 10, 'shadow_enforcement_result' => 'sufficient']);

        (new AnalyseContractWithAiJob($analysis->id, $upload->id, $user->id))->failed(new \RuntimeException('Simulated timeout'));

        $analysis->refresh();
        $this->assertSame('failed', $analysis->status);
        $this->assertNotNull($analysis->completed_at);

        $balance = $this->balanceFor($org->id);
        $this->assertSame(0.0, $balance['reserved'], 'The failed() handler must release an open reservation on a hard timeout.');
        $this->assertSame(1, AiCreditLedgerEntry::where('reference_id', $analysis->id)->where('transaction_type', 'release')->count());
    }

    public function test_contract_duplicate_delivery_never_opens_a_second_reservation(): void
    {
        Storage::fake('local');
        $this->fakeSuccessfulProvider();
        $this->enableShadowCandidate();

        ['user' => $user, 'contract' => $contract, 'project' => $project, 'org' => $org] = $this->makeContractFixtures();
        app(AiCreditLedgerService::class)->grant($org->id, 100, 'Test grant', 'grant-' . uniqid(), $user->id);

        $upload = $this->uploadText($org, $user, $project, 'Text for a duplicate-delivery scenario.');
        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'pending', 'model' => 'claude-sonnet-5', 'workflow' => 'contract_analysis',
        ]);

        $job = new AnalyseContractWithAiJob($analysis->id, $upload->id, $user->id);
        $job->handle(app(ContractAnalysisService::class));
        // A second, duplicate delivery of the exact same job — the job's own
        // pending-status guard must skip it before the credit lifecycle runs.
        $job->handle(app(ContractAnalysisService::class));

        $this->assertSame(1, AiCreditLedgerEntry::where('reference_id', $analysis->id)->where('transaction_type', 'reserve')->count());
        $this->assertSame(1, AiCreditLedgerEntry::where('reference_id', $analysis->id)->where('transaction_type', 'settle')->count());
    }

    public function test_contract_cached_result_still_reserves_and_settles_using_the_shared_document_amount(): void
    {
        Storage::fake('local');
        $this->enableShadowCandidate();

        ['user' => $user, 'contract' => $contract, 'project' => $project, 'org' => $org] = $this->makeContractFixtures();
        app(AiCreditLedgerService::class)->grant($org->id, 100, 'Test grant', 'grant-' . uniqid(), $user->id);

        $text = 'Identical cached contract text for shadow lifecycle.';
        ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'completed', 'model' => 'claude-sonnet-5',
            'document_hash' => hash('sha256', $text), 'raw_response_json' => ['contract_summary' => 'Cached'],
        ]);

        $upload = $this->uploadText($org, $user, $project, $text, 'cached.txt');
        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'pending', 'model' => 'claude-sonnet-5', 'workflow' => 'contract_analysis',
        ]);

        (new AnalyseContractWithAiJob($analysis->id, $upload->id, $user->id))->handle(app(ContractAnalysisService::class));

        $analysis->refresh();
        $this->assertSame('completed', $analysis->status);
        $this->assertFalse((bool) $analysis->provider_called);
        $this->assertNotNull($analysis->credit_reservation_amount, 'A cache hit still consumes shadow credits for the document.');
        $this->assertSame(1, AiCreditLedgerEntry::where('reference_id', $analysis->id)->where('transaction_type', 'settle')->count());
    }

    public function test_contract_insufficient_balance_never_blocks_execution(): void
    {
        Storage::fake('local');
        $this->fakeSuccessfulProvider();
        $this->enableShadowCandidate();

        ['user' => $user, 'contract' => $contract, 'project' => $project, 'org' => $org] = $this->makeContractFixtures();
        // Deliberately no grant — this organisation has a zero balance.

        $upload = $this->uploadText($org, $user, $project, 'Text analysed despite an empty shadow balance.');
        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'pending', 'model' => 'claude-sonnet-5', 'workflow' => 'contract_analysis',
        ]);

        (new AnalyseContractWithAiJob($analysis->id, $upload->id, $user->id))->handle(app(ContractAnalysisService::class));

        $analysis->refresh();
        $this->assertSame('completed', $analysis->status, 'Shadow mode must never block real AI execution.');
        $this->assertSame('insufficient', $analysis->shadow_enforcement_result);
        $this->assertNotNull($analysis->credit_reservation_amount);
    }

    // ── Phase G4C.3I — real enforcement (off by default) ─────────────────

    public function test_contract_enforcement_blocks_before_calling_the_provider_when_insufficient(): void
    {
        Storage::fake('local');
        Http::fake(); // no matcher for api.anthropic.com — asserted unreached below
        SuresignSetting::instance()->update(['ai_enabled' => true, 'anthropic_api_key' => 'fake-key', 'ai_model' => 'claude-sonnet-5']);
        $this->enableShadowCandidate();
        SuresignSetting::instance()->update(['ai_credit_operating_mode' => AiCreditOperatingMode::ENFORCED]);

        ['user' => $user, 'contract' => $contract, 'project' => $project, 'org' => $org] = $this->makeContractFixtures();
        // Deliberately no grant — a zero balance is insufficient for any resolved amount.

        $upload = $this->uploadText($org, $user, $project, 'Text that must never reach the provider once enforcement is on.');
        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'pending', 'model' => 'claude-sonnet-5', 'workflow' => 'contract_analysis',
        ]);

        (new AnalyseContractWithAiJob($analysis->id, $upload->id, $user->id))->handle(app(ContractAnalysisService::class));

        Http::assertNothingSent();

        $analysis->refresh();
        $this->assertSame('failed', $analysis->status);
        $this->assertSame('insufficient_credits', $analysis->failure_category);
        $this->assertStringNotContainsString('credit', strtolower($analysis->error_message));
        $this->assertStringNotContainsString('ledger', strtolower($analysis->error_message));

        $balance = $this->balanceFor($org->id);
        $this->assertSame(0.0, $balance['reserved'], 'A blocked analysis must release its reservation, not leave it open.');
        $this->assertSame(1, AiCreditLedgerEntry::where('reference_id', $analysis->id)->where('transaction_type', 'reserve')->count());
        $this->assertSame(1, AiCreditLedgerEntry::where('reference_id', $analysis->id)->where('transaction_type', 'release')->count());
    }

    public function test_contract_enforcement_does_not_block_a_sufficient_balance(): void
    {
        Storage::fake('local');
        $this->fakeSuccessfulProvider();
        $this->enableShadowCandidate();
        SuresignSetting::instance()->update(['ai_credit_operating_mode' => AiCreditOperatingMode::ENFORCED]);

        ['user' => $user, 'contract' => $contract, 'project' => $project, 'org' => $org] = $this->makeContractFixtures();
        app(AiCreditLedgerService::class)->grant($org->id, 100, 'Test grant', 'grant-' . uniqid(), $user->id);

        $upload = $this->uploadText($org, $user, $project, 'Text analysed normally with a sufficient balance under enforcement.');
        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'pending', 'model' => 'claude-sonnet-5', 'workflow' => 'contract_analysis',
        ]);

        (new AnalyseContractWithAiJob($analysis->id, $upload->id, $user->id))->handle(app(ContractAnalysisService::class));

        $analysis->refresh();
        $this->assertSame('completed', $analysis->status, 'Enforcement must never block a sufficient balance.');
        $this->assertSame('sufficient', $analysis->shadow_enforcement_result);
    }

    public function test_contract_enforcement_never_blocks_an_unresolved_shadow_policy(): void
    {
        Storage::fake('local');
        $this->fakeSuccessfulProvider();
        config(['ai_credit_shadow.active_candidate' => null]); // no shadow policy configured -> unresolved
        SuresignSetting::instance()->update(['ai_credit_operating_mode' => AiCreditOperatingMode::ENFORCED]);

        ['user' => $user, 'contract' => $contract, 'project' => $project, 'org' => $org] = $this->makeContractFixtures();

        $upload = $this->uploadText($org, $user, $project, 'Text analysed with enforcement on but no shadow policy at all.');
        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'pending', 'model' => 'claude-sonnet-5', 'workflow' => 'contract_analysis',
        ]);

        (new AnalyseContractWithAiJob($analysis->id, $upload->id, $user->id))->handle(app(ContractAnalysisService::class));

        $analysis->refresh();
        $this->assertSame('completed', $analysis->status, 'An unresolved shadow policy must never be enforced against — there is no real number to enforce.');
        $this->assertSame('unresolved', $analysis->shadow_enforcement_result);
    }

    public function test_trade_package_enforcement_blocks_before_calling_the_provider_when_insufficient(): void
    {
        Storage::fake('local');
        Http::fake();
        SuresignSetting::instance()->update(['ai_enabled' => true, 'anthropic_api_key' => 'fake-key', 'ai_model' => 'claude-sonnet-5']);
        $this->enableShadowCandidate();
        SuresignSetting::instance()->update(['ai_credit_operating_mode' => AiCreditOperatingMode::ENFORCED]);

        ['user' => $user, 'tradePackage' => $tradePackage, 'project' => $project, 'org' => $org] = $this->makeTradePackageFixtures();

        $upload = $this->uploadText($org, $user, $project, 'Subcontract text that must never reach the provider under enforcement.');
        $analysis = TradePackageAiAnalysis::create([
            'trade_package_id' => $tradePackage->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'pending', 'model' => 'claude-sonnet-5', 'workflow' => 'trade_package_analysis',
        ]);

        (new AnalyseTradePackageWithAiJob($analysis->id, $upload->id, $user->id))->handle(app(TradePackageAnalysisService::class));

        Http::assertNothingSent();

        $analysis->refresh();
        $this->assertSame('failed', $analysis->status);
        $this->assertSame('insufficient_credits', $analysis->failure_category);

        $balance = $this->balanceFor($org->id);
        $this->assertSame(0.0, $balance['reserved']);
    }

    public function test_contract_unresolved_shadow_policy_records_unresolved_and_opens_no_reservation(): void
    {
        Storage::fake('local');
        $this->fakeSuccessfulProvider();
        config(['ai_credit_shadow.active_candidate' => null]); // explicitly disabled

        ['user' => $user, 'contract' => $contract, 'project' => $project, 'org' => $org] = $this->makeContractFixtures();

        $upload = $this->uploadText($org, $user, $project, 'Text analysed with no shadow policy configured.');
        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'pending', 'model' => 'claude-sonnet-5', 'workflow' => 'contract_analysis',
        ]);

        (new AnalyseContractWithAiJob($analysis->id, $upload->id, $user->id))->handle(app(ContractAnalysisService::class));

        $analysis->refresh();
        $this->assertSame('completed', $analysis->status);
        $this->assertSame('unresolved', $analysis->shadow_enforcement_result);
        $this->assertNull($analysis->credit_reservation_amount);
        $this->assertSame(0, AiCreditLedgerEntry::where('reference_id', $analysis->id)->count(), 'No shadow policy configured means no reservation is ever opened.');
    }

    // ── Trade Package Analysis — confirms identical lifecycle behaviour ──

    public function test_trade_package_success_reserves_then_settles_identically_to_contract(): void
    {
        Storage::fake('local');
        $this->fakeSuccessfulProvider();
        $this->enableShadowCandidate();

        ['user' => $user, 'tradePackage' => $tradePackage, 'project' => $project, 'org' => $org] = $this->makeTradePackageFixtures();
        app(AiCreditLedgerService::class)->grant($org->id, 100, 'Test grant', 'grant-' . uniqid(), $user->id);

        $upload = $this->uploadText($org, $user, $project, 'Some subcontract text for shadow lifecycle verification.');
        $analysis = TradePackageAiAnalysis::create([
            'trade_package_id' => $tradePackage->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'pending', 'model' => 'claude-sonnet-5', 'workflow' => 'trade_package_analysis',
        ]);

        (new AnalyseTradePackageWithAiJob($analysis->id, $upload->id, $user->id))->handle(app(TradePackageAnalysisService::class));

        $analysis->refresh();
        $this->assertSame('completed', $analysis->status);
        $this->assertSame('sufficient', $analysis->shadow_enforcement_result);

        $balance = $this->balanceFor($org->id);
        $this->assertSame(0.0, $balance['reserved']);
        $this->assertEqualsWithDelta(100.0 - (float) $analysis->credit_reservation_amount, $balance['available'], 0.001);
        $this->assertSame(1, AiCreditLedgerEntry::where('reference_id', $analysis->id)->where('transaction_type', 'settle')->count());
    }

    public function test_trade_package_provider_failure_releases_the_reservation(): void
    {
        Storage::fake('local');
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);
        SuresignSetting::instance()->update(['ai_enabled' => true, 'anthropic_api_key' => 'fake-key', 'ai_model' => 'claude-sonnet-5']);
        $this->enableShadowCandidate();

        ['user' => $user, 'tradePackage' => $tradePackage, 'project' => $project, 'org' => $org] = $this->makeTradePackageFixtures();
        app(AiCreditLedgerService::class)->grant($org->id, 100, 'Test grant', 'grant-' . uniqid(), $user->id);

        $upload = $this->uploadText($org, $user, $project, 'Subcontract text that triggers a failing provider call.');
        $analysis = TradePackageAiAnalysis::create([
            'trade_package_id' => $tradePackage->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'pending', 'model' => 'claude-sonnet-5', 'workflow' => 'trade_package_analysis',
        ]);

        (new AnalyseTradePackageWithAiJob($analysis->id, $upload->id, $user->id))->handle(app(TradePackageAnalysisService::class));

        $analysis->refresh();
        $this->assertSame('failed', $analysis->status);

        $balance = $this->balanceFor($org->id);
        $this->assertSame(0.0, $balance['reserved']);
        $this->assertSame(100.0, $balance['available']);
    }

    // ── Organisation isolation at the integration level ─────────────────

    public function test_two_organisations_reservations_never_interact_during_real_analyses(): void
    {
        Storage::fake('local');
        $this->fakeSuccessfulProvider();
        $this->enableShadowCandidate();

        ['user' => $userA, 'contract' => $contractA, 'project' => $projectA, 'org' => $orgA] = $this->makeContractFixtures();
        ['user' => $userB, 'contract' => $contractB, 'project' => $projectB, 'org' => $orgB] = $this->makeContractFixtures();
        app(AiCreditLedgerService::class)->grant($orgA->id, 100, 'Grant A', 'grant-a-' . uniqid(), $userA->id);
        app(AiCreditLedgerService::class)->grant($orgB->id, 40, 'Grant B', 'grant-b-' . uniqid(), $userB->id);

        $uploadA = $this->uploadText($orgA, $userA, $projectA, 'Org A contract text.', 'a.txt');
        $analysisA = ContractAiAnalysis::create([
            'contract_id' => $contractA->id, 'organization_id' => $orgA->id, 'project_id' => $projectA->id,
            'created_by' => $userA->id, 'status' => 'pending', 'model' => 'claude-sonnet-5', 'workflow' => 'contract_analysis',
        ]);
        (new AnalyseContractWithAiJob($analysisA->id, $uploadA->id, $userA->id))->handle(app(ContractAnalysisService::class));

        $this->assertEqualsWithDelta(100.0 - (float) $analysisA->refresh()->credit_reservation_amount, $this->balanceFor($orgA->id)['available'], 0.001);
        $this->assertSame(40.0, $this->balanceFor($orgB->id)['available'], "Org B's balance must be untouched by Org A's analysis.");
    }

    // ── Operating mode: DISABLED — no reservation, simulation, settlement, ──
    // ── release, or enforcement evaluation is attempted at all ──────────

    public function test_default_operating_mode_after_migration_is_shadow(): void
    {
        $this->assertSame(AiCreditOperatingMode::SHADOW, SuresignSetting::instance()->ai_credit_operating_mode);
    }

    public function test_contract_disabled_mode_never_reserves_simulates_or_blocks(): void
    {
        Storage::fake('local');
        $this->fakeSuccessfulProvider();
        $this->enableShadowCandidate();
        SuresignSetting::instance()->update(['ai_credit_operating_mode' => AiCreditOperatingMode::DISABLED]);

        ['user' => $user, 'contract' => $contract, 'project' => $project, 'org' => $org] = $this->makeContractFixtures();
        // Deliberately no grant — DISABLED mode must never even check the balance.

        $upload = $this->uploadText($org, $user, $project, 'Text analysed while the credit lifecycle is fully disabled.');
        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'pending', 'model' => 'claude-sonnet-5', 'workflow' => 'contract_analysis',
        ]);

        (new AnalyseContractWithAiJob($analysis->id, $upload->id, $user->id))->handle(app(ContractAnalysisService::class));

        $analysis->refresh();
        $this->assertSame('completed', $analysis->status, 'DISABLED mode must never affect the AI workflow itself.');
        $this->assertNull($analysis->credit_reservation_amount, 'DISABLED means null, never "unresolved" — the lifecycle was not evaluated at all.');
        $this->assertNull($analysis->shadow_enforcement_result);

        $this->assertSame(0, AiCreditLedgerEntry::where('reference_id', $analysis->id)->count(), 'No reserve/settle/release row of any kind while disabled.');
        $this->assertSame(0, AiCreditSimulationResult::where('analysable_id', $analysis->id)->count(), 'No candidate-policy simulation while disabled.');
    }

    public function test_trade_package_disabled_mode_never_reserves_simulates_or_blocks(): void
    {
        Storage::fake('local');
        $this->fakeSuccessfulProvider();
        $this->enableShadowCandidate();
        SuresignSetting::instance()->update(['ai_credit_operating_mode' => AiCreditOperatingMode::DISABLED]);

        ['user' => $user, 'tradePackage' => $tradePackage, 'project' => $project, 'org' => $org] = $this->makeTradePackageFixtures();

        $upload = $this->uploadText($org, $user, $project, 'Subcontract text analysed while the credit lifecycle is fully disabled.');
        $analysis = TradePackageAiAnalysis::create([
            'trade_package_id' => $tradePackage->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'pending', 'model' => 'claude-sonnet-5', 'workflow' => 'trade_package_analysis',
        ]);

        (new AnalyseTradePackageWithAiJob($analysis->id, $upload->id, $user->id))->handle(app(TradePackageAnalysisService::class));

        $analysis->refresh();
        $this->assertSame('completed', $analysis->status);
        $this->assertNull($analysis->credit_reservation_amount);
        $this->assertNull($analysis->shadow_enforcement_result);
        $this->assertSame(0, AiCreditLedgerEntry::where('reference_id', $analysis->id)->count());
        $this->assertSame(0, AiCreditSimulationResult::where('analysable_id', $analysis->id)->count());
    }

    public function test_contract_disabled_mode_release_and_settle_are_harmless_no_ops(): void
    {
        Storage::fake('local');
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);
        SuresignSetting::instance()->update([
            'ai_enabled' => true, 'anthropic_api_key' => 'fake-key', 'ai_model' => 'claude-sonnet-5',
            'ai_credit_operating_mode' => AiCreditOperatingMode::DISABLED,
        ]);
        $this->enableShadowCandidate();

        ['user' => $user, 'contract' => $contract, 'project' => $project, 'org' => $org] = $this->makeContractFixtures();

        $upload = $this->uploadText($org, $user, $project, 'Text for a disabled-mode provider failure scenario.');
        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'pending', 'model' => 'claude-sonnet-5', 'workflow' => 'contract_analysis',
        ]);

        // A provider failure exercises releaseFor() — must remain a harmless
        // no-op with nothing to release, exactly as it is when no reservation
        // was ever opened in SHADOW mode's own "unresolved" case.
        (new AnalyseContractWithAiJob($analysis->id, $upload->id, $user->id))->handle(app(ContractAnalysisService::class));

        $analysis->refresh();
        $this->assertSame('failed', $analysis->status);
        $this->assertSame(0, AiCreditLedgerEntry::where('reference_id', $analysis->id)->count());
    }
}

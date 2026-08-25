<?php

namespace Tests\Feature;

use App\Jobs\AnalyseContractWithAiJob;
use App\Jobs\AnalyseTradePackageWithAiJob;
use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SuresignSetting;
use App\Models\TradePackage;
use App\Models\TradePackageAiAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P2 AI Analysis TOCTOU / Duplicate Execution Race — application-logic
 * regression coverage for the fix in AiController::startAnalysis() /
 * TradePackageAiController::startAnalysis() (lockForUpdate() on the parent
 * Contract/TradePackage row, re-check + create inside one short
 * transaction, dispatch only after the transaction returns).
 *
 * This suite runs against the default SQLite (:memory:) test database and
 * deliberately does NOT claim to prove real InnoDB row-level lock
 * contention — see AiAnalysisConcurrencyMysqlTest for the genuine
 * multi-connection concurrency proof, gated to MySQL only. These tests
 * cover ordinary sequential/application-logic behaviour: existing
 * safeguards, retry semantics, and resource independence.
 *
 * test_concurrent_analysis_protection_still_functions() in
 * AiAnalysisRateLimitingTest already covers the sequential
 * pending-blocks-second-request + single-dispatch case for Contract
 * Analysis — not duplicated here.
 */
class AiAnalysisToctouTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgAndUser(string $suffix): array
    {
        $org = Organization::create(['name' => "Org {$suffix}", 'slug' => "org-{$suffix}"]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        SuresignSetting::instance()->update(['ai_enabled' => true]);

        return [$org, $user];
    }

    private function makeContract(Organization $org, User $user, string $suffix): Contract
    {
        $project = Project::create([
            'organization_id' => $org->id,
            'created_by'      => $user->id,
            'name'            => "Project {$suffix}",
        ]);

        return Contract::create([
            'project_id'      => $project->id,
            'organization_id' => $org->id,
            'created_by'      => $user->id,
            'type'            => 'main_contract',
            'title'           => "Contract {$suffix}",
        ]);
    }

    private function makeTradePackage(Organization $org, User $user, string $suffix): TradePackage
    {
        $project = Project::create([
            'organization_id' => $org->id,
            'created_by'      => $user->id,
            'name'            => "TP Project {$suffix}",
        ]);

        return TradePackage::create([
            'organization_id' => $org->id,
            'project_id'      => $project->id,
            'name'            => "Package {$suffix}",
            'slug'            => "package-{$suffix}-" . uniqid(),
            'created_by'      => $user->id,
        ]);
    }

    private function makeContractUpload(Contract $contract, Organization $org, User $user): FileUpload
    {
        return FileUpload::create([
            'project_id'      => $contract->project_id,
            'organization_id' => $org->id,
            'uploaded_by'     => $user->id,
            'attachable_type' => Contract::class,
            'attachable_id'   => $contract->id,
            'original_name'   => 'contract.pdf',
            'stored_name'     => 'stored-' . uniqid() . '.pdf',
            'file_path'       => 'contracts/stored-' . uniqid() . '.pdf',
            'mime_type'       => 'application/pdf',
            'file_size'       => 100,
        ]);
    }

    private function makePackageUpload(TradePackage $tradePackage, Organization $org, User $user): FileUpload
    {
        return FileUpload::create([
            'project_id'       => $tradePackage->project_id,
            'organization_id'  => $org->id,
            'uploaded_by'      => $user->id,
            'trade_package_id' => $tradePackage->id,
            'original_name'    => 'package.pdf',
            'stored_name'      => 'stored-' . uniqid() . '.pdf',
            'file_path'        => 'packages/stored-' . uniqid() . '.pdf',
            'mime_type'        => 'application/pdf',
            'file_size'        => 100,
        ]);
    }

    // ── Contract: force_new / completed reuse / terminal-state retry ────────

    public function test_completed_analysis_is_offered_for_reuse_and_creates_no_new_row(): void
    {
        Bus::fake();
        [$org, $user] = $this->makeOrgAndUser('ca1');
        $contract = $this->makeContract($org, $user, 'ca1');
        $upload = $this->makeContractUpload($contract, $org, $user);

        ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id,
            'project_id' => $contract->project_id, 'created_by' => $user->id,
            'status' => 'completed',
        ]);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/contracts/{$contract->id}/ai-analysis", ['file_upload_id' => $upload->id]);

        $response->assertStatus(200)->assertJsonStructure(['existing_analysis', 'message']);
        $this->assertSame(1, ContractAiAnalysis::where('contract_id', $contract->id)->count());
        Bus::assertNotDispatched(AnalyseContractWithAiJob::class);
    }

    public function test_force_new_starts_a_fresh_analysis_even_when_a_completed_one_exists(): void
    {
        Bus::fake();
        [$org, $user] = $this->makeOrgAndUser('ca2');
        $contract = $this->makeContract($org, $user, 'ca2');
        $upload = $this->makeContractUpload($contract, $org, $user);

        ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id,
            'project_id' => $contract->project_id, 'created_by' => $user->id,
            'status' => 'completed',
        ]);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/contracts/{$contract->id}/ai-analysis", [
            'file_upload_id' => $upload->id,
            'force_new'      => true,
        ]);

        $response->assertStatus(201);
        $this->assertSame(2, ContractAiAnalysis::where('contract_id', $contract->id)->count());
        Bus::assertDispatchedTimes(AnalyseContractWithAiJob::class, 1);
    }

    public function test_failed_analysis_permits_a_legitimate_retry(): void
    {
        Bus::fake();
        [$org, $user] = $this->makeOrgAndUser('ca3');
        $contract = $this->makeContract($org, $user, 'ca3');
        $upload = $this->makeContractUpload($contract, $org, $user);

        ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id,
            'project_id' => $contract->project_id, 'created_by' => $user->id,
            'status' => 'failed',
        ]);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/contracts/{$contract->id}/ai-analysis", ['file_upload_id' => $upload->id]);

        $response->assertStatus(201);
        Bus::assertDispatchedTimes(AnalyseContractWithAiJob::class, 1);
    }

    public function test_cancelled_analysis_permits_a_legitimate_retry(): void
    {
        Bus::fake();
        [$org, $user] = $this->makeOrgAndUser('ca4');
        $contract = $this->makeContract($org, $user, 'ca4');
        $upload = $this->makeContractUpload($contract, $org, $user);

        ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id,
            'project_id' => $contract->project_id, 'created_by' => $user->id,
            'status' => 'cancelled',
        ]);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/contracts/{$contract->id}/ai-analysis", ['file_upload_id' => $upload->id]);

        $response->assertStatus(201);
        Bus::assertDispatchedTimes(AnalyseContractWithAiJob::class, 1);
    }

    public function test_two_different_contracts_do_not_block_each_other(): void
    {
        Bus::fake();
        [$org, $user] = $this->makeOrgAndUser('ca5');
        $contractA = $this->makeContract($org, $user, 'ca5a');
        $contractB = $this->makeContract($org, $user, 'ca5b');
        $uploadA = $this->makeContractUpload($contractA, $org, $user);
        $uploadB = $this->makeContractUpload($contractB, $org, $user);

        // Contract A already has an active ('processing') analysis.
        ContractAiAnalysis::create([
            'contract_id' => $contractA->id, 'organization_id' => $org->id,
            'project_id' => $contractA->project_id, 'created_by' => $user->id,
            'status' => 'processing',
        ]);

        Sanctum::actingAs($user);

        // A is correctly blocked...
        $this->postJson("/api/contracts/{$contractA->id}/ai-analysis", ['file_upload_id' => $uploadA->id])
            ->assertStatus(409);

        // ...but B, a completely different contract, is entirely unaffected.
        $this->postJson("/api/contracts/{$contractB->id}/ai-analysis", ['file_upload_id' => $uploadB->id])
            ->assertStatus(201);

        Bus::assertDispatchedTimes(AnalyseContractWithAiJob::class, 1);
    }

    // ── Trade Package: mirrors the Contract coverage above ──────────────────

    public function test_trade_package_pending_analysis_blocks_a_second_start(): void
    {
        Bus::fake();
        [$org, $user] = $this->makeOrgAndUser('tp1');
        $tradePackage = $this->makeTradePackage($org, $user, 'tp1');
        $upload = $this->makePackageUpload($tradePackage, $org, $user);

        Sanctum::actingAs($user);

        $this->postJson("/api/trade-packages/{$tradePackage->id}/ai-analysis", ['file_upload_id' => $upload->id])
            ->assertStatus(201);
        $this->postJson("/api/trade-packages/{$tradePackage->id}/ai-analysis", ['file_upload_id' => $upload->id])
            ->assertStatus(409);

        Bus::assertDispatchedTimes(AnalyseTradePackageWithAiJob::class, 1);
        $this->assertSame(1, TradePackageAiAnalysis::where('trade_package_id', $tradePackage->id)->count());
    }

    public function test_trade_package_failed_analysis_permits_a_legitimate_retry(): void
    {
        Bus::fake();
        [$org, $user] = $this->makeOrgAndUser('tp2');
        $tradePackage = $this->makeTradePackage($org, $user, 'tp2');
        $upload = $this->makePackageUpload($tradePackage, $org, $user);

        TradePackageAiAnalysis::create([
            'trade_package_id' => $tradePackage->id, 'organization_id' => $org->id,
            'project_id' => $tradePackage->project_id, 'created_by' => $user->id,
            'status' => 'failed',
        ]);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/trade-packages/{$tradePackage->id}/ai-analysis", ['file_upload_id' => $upload->id]);

        $response->assertStatus(201);
        Bus::assertDispatchedTimes(AnalyseTradePackageWithAiJob::class, 1);
    }

    public function test_two_different_trade_packages_do_not_block_each_other(): void
    {
        Bus::fake();
        [$org, $user] = $this->makeOrgAndUser('tp3');
        $packageA = $this->makeTradePackage($org, $user, 'tp3a');
        $packageB = $this->makeTradePackage($org, $user, 'tp3b');
        $uploadA = $this->makePackageUpload($packageA, $org, $user);
        $uploadB = $this->makePackageUpload($packageB, $org, $user);

        TradePackageAiAnalysis::create([
            'trade_package_id' => $packageA->id, 'organization_id' => $org->id,
            'project_id' => $packageA->project_id, 'created_by' => $user->id,
            'status' => 'processing',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/trade-packages/{$packageA->id}/ai-analysis", ['file_upload_id' => $uploadA->id])
            ->assertStatus(409);
        $this->postJson("/api/trade-packages/{$packageB->id}/ai-analysis", ['file_upload_id' => $uploadB->id])
            ->assertStatus(201);

        Bus::assertDispatchedTimes(AnalyseTradePackageWithAiJob::class, 1);
    }
}

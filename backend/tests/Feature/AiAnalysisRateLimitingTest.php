<?php

namespace Tests\Feature;

use App\Jobs\AnalyseContractWithAiJob;
use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SuresignSetting;
use App\Models\TradePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the new `ai-analysis` named limiter (M6): AI analysis initiation
 * previously inherited only the general 120/min `api` limiter, letting an
 * authenticated client repeatedly create contracts with slightly modified
 * documents to trigger a large number of genuinely-billed Claude requests.
 * document_hash dedup helps but doesn't stop the initial request rate.
 *
 * AI is left disabled (the test default) for the pure rate-limiting cases —
 * the throttle middleware runs before the controller's isEnabled() check, so
 * a 403 counts against the bucket exactly the same as a 201 would. This
 * keeps these tests fast and hermetic (no Claude calls, no queued jobs).
 */
class AiAnalysisRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeOrgAndUser(string $suffix): array
    {
        $org = Organization::create(['name' => "Org {$suffix}", 'slug' => "org-{$suffix}"]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        return [$org, $user];
    }

    private function makeContract(Organization $org, User $user): Contract
    {
        $project = Project::create([
            'organization_id' => $org->id,
            'created_by'      => $user->id,
            'name'            => 'Project ' . $org->id,
        ]);

        return Contract::create([
            'project_id'      => $project->id,
            'organization_id' => $org->id,
            'created_by'      => $user->id,
            'type'            => 'main_contract',
            'title'           => 'Contract ' . $org->id,
        ]);
    }

    private function makeTradePackage(Organization $org, User $user): TradePackage
    {
        $project = Project::create([
            'organization_id' => $org->id,
            'created_by'      => $user->id,
            'name'            => 'TP Project ' . $org->id,
        ]);

        return TradePackage::create([
            'organization_id' => $org->id,
            'project_id'      => $project->id,
            'name'            => 'Package ' . $org->id,
            'slug'            => 'package-' . $org->id,
            'created_by'      => $user->id,
        ]);
    }

    // ── Threshold + 429 shape ────────────────────────────────────────────────

    public function test_contract_ai_analysis_requests_are_allowed_up_to_the_threshold(): void
    {
        [$org, $user] = $this->makeOrgAndUser('a');
        $contract = $this->makeContract($org, $user);
        Sanctum::actingAs($user);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/contracts/{$contract->id}/ai-analysis")
                ->assertStatus(403); // AI disabled — but NOT rate-limited yet
        }
    }

    public function test_the_next_contract_ai_analysis_request_returns_429_with_the_expected_message(): void
    {
        [$org, $user] = $this->makeOrgAndUser('b');
        $contract = $this->makeContract($org, $user);
        Sanctum::actingAs($user);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/contracts/{$contract->id}/ai-analysis")->assertStatus(403);
        }

        $this->postJson("/api/contracts/{$contract->id}/ai-analysis")
            ->assertStatus(429)
            ->assertJson(['message' => 'AI analysis rate limit exceeded. Please try again later.'])
            ->assertHeader('Retry-After');
    }

    public function test_trade_package_ai_analysis_also_returns_429_after_threshold(): void
    {
        [$org, $user] = $this->makeOrgAndUser('c');
        $package = $this->makeTradePackage($org, $user);
        Sanctum::actingAs($user);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/trade-packages/{$package->id}/ai-analysis")->assertStatus(403);
        }

        $this->postJson("/api/trade-packages/{$package->id}/ai-analysis")
            ->assertStatus(429)
            ->assertJson(['message' => 'AI analysis rate limit exceeded. Please try again later.']);
    }

    public function test_different_users_have_separate_buckets(): void
    {
        [$orgA, $userA] = $this->makeOrgAndUser('d');
        [$orgB, $userB] = $this->makeOrgAndUser('e');
        $contractA = $this->makeContract($orgA, $userA);
        $contractB = $this->makeContract($orgB, $userB);

        Sanctum::actingAs($userA);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/contracts/{$contractA->id}/ai-analysis")->assertStatus(403);
        }
        // userA is now exhausted for their own bucket
        $this->postJson("/api/contracts/{$contractA->id}/ai-analysis")->assertStatus(429);

        // userB (different user, different org) is unaffected
        Sanctum::actingAs($userB);
        $this->postJson("/api/contracts/{$contractB->id}/ai-analysis")->assertStatus(403);
    }

    public function test_contract_and_trade_package_analysis_share_the_same_per_user_bucket(): void
    {
        // Both routes are wired to the same named limiter, keyed per-user —
        // exhausting one endpoint exhausts the other for that user too,
        // since the underlying cost driver (calls to Claude) is the same
        // regardless of which entity triggered it.
        [$org, $user] = $this->makeOrgAndUser('f');
        $contract = $this->makeContract($org, $user);
        $package = $this->makeTradePackage($org, $user);
        Sanctum::actingAs($user);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/contracts/{$contract->id}/ai-analysis")->assertStatus(403);
        }

        $this->postJson("/api/trade-packages/{$package->id}/ai-analysis")->assertStatus(429);
    }

    // ── Reading AI history is unaffected ─────────────────────────────────────

    public function test_reading_analysis_history_is_not_rate_limited_by_the_ai_analysis_bucket(): void
    {
        [$org, $user] = $this->makeOrgAndUser('g');
        $contract = $this->makeContract($org, $user);
        Sanctum::actingAs($user);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/contracts/{$contract->id}/ai-analysis")->assertStatus(403);
        }
        $this->postJson("/api/contracts/{$contract->id}/ai-analysis")->assertStatus(429);

        // GET routes (read history) are not behind throttle:ai-analysis at all.
        $this->getJson("/api/contracts/{$contract->id}/ai-analysis")->assertStatus(200);
        $this->getJson("/api/contracts/{$contract->id}/ai-analyses")->assertStatus(200);
    }

    // ── Existing safeguards still function alongside the new limiter ────────

    public function test_concurrent_analysis_protection_still_functions(): void
    {
        Bus::fake(); // AnalyseContractWithAiJob must not actually run (no Claude call)

        [$org, $user] = $this->makeOrgAndUser('h');
        $contract = $this->makeContract($org, $user);
        $upload = FileUpload::create([
            'project_id'      => $contract->project_id,
            'organization_id' => $org->id,
            'uploaded_by'     => $user->id,
            'attachable_type' => Contract::class,
            'attachable_id'   => $contract->id,
            'original_name'   => 'contract.pdf',
            'stored_name'     => 'stored.pdf',
            'file_path'       => 'contracts/stored.pdf',
            'mime_type'       => 'application/pdf',
            'file_size'       => 100,
        ]);

        SuresignSetting::instance()->update(['ai_enabled' => true]);
        Sanctum::actingAs($user);

        $first = $this->postJson("/api/contracts/{$contract->id}/ai-analysis", ['file_upload_id' => $upload->id]);
        $first->assertStatus(201);

        Bus::assertDispatched(AnalyseContractWithAiJob::class);
        $this->assertSame('pending', ContractAiAnalysis::where('contract_id', $contract->id)->latest()->first()->status);

        // A second request while the first is still 'pending' must be
        // rejected as a conflict — not consume a second AI call.
        $second = $this->postJson("/api/contracts/{$contract->id}/ai-analysis", ['file_upload_id' => $upload->id]);
        $second->assertStatus(409);

        Bus::assertDispatchedTimes(AnalyseContractWithAiJob::class, 1);
    }
}

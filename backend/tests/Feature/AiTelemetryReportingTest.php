<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase G4C.2C-2 — internal AI execution / non-enforcing AI Credit
 * simulation reporting (GET /admin/ai-telemetry/{summary,detail,export}).
 * Confirms: server-side authorisation (not just frontend hiding), correct
 * cross-organisation aggregation, missing-cost exclusion from spend
 * totals, and that every candidate-policy figure is clearly non-enforcing.
 */
class AiTelemetryReportingTest extends TestCase
{
    use RefreshDatabase;

    private function makeAnalysis(Organization $org, User $user, ?float $cost, string $status = 'completed', ?string $documentHash = null): ContractAiAnalysis
    {
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P' . uniqid()]);
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id,
            'created_by' => $user->id, 'type' => 'main_contract', 'title' => 'C',
        ]);

        return ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => $status, 'workflow' => 'contract_analysis',
            'model' => 'claude-sonnet-5', 'provider' => 'anthropic', 'provider_called' => true,
            // Every real analysis has a document_hash by the time it reaches a
            // terminal status (see ContractAnalysisService::analyse()) — a
            // distinct default per call so unrelated test fixtures never
            // accidentally collide into the same "document" under the
            // G4C.2G unique-document dedup.
            'document_hash' => $documentHash ?? hash('sha256', uniqid('doc-', true)),
            'estimated_cost' => $cost, 'completed_at' => now(),
        ]);
    }

    private function makeAnalysisWithProviderCalled(Organization $org, User $user, ?float $cost, ?bool $providerCalled, ?string $documentHash = null): ContractAiAnalysis
    {
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P' . uniqid()]);
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id,
            'created_by' => $user->id, 'type' => 'main_contract', 'title' => 'C',
        ]);

        return ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'completed', 'workflow' => 'contract_analysis',
            'model' => 'claude-sonnet-5', 'provider' => 'anthropic', 'provider_called' => $providerCalled,
            'document_hash' => $documentHash ?? hash('sha256', uniqid('doc-', true)),
            'estimated_cost' => $cost, 'completed_at' => now(),
        ]);
    }

    private function superAdmin(): User
    {
        $org = Organization::create(['name' => 'Platform', 'slug' => 'platform-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        return $user;
    }

    private function admin(): User
    {
        $org = Organization::create(['name' => 'Platform2', 'slug' => 'platform2-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));
        return $user;
    }

    public function test_super_admin_can_access_summary(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/admin/ai-telemetry/summary')->assertOk();
    }

    public function test_admin_can_access_summary(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/ai-telemetry/summary')->assertOk();
    }

    public function test_client_user_is_denied(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $client = User::factory()->create(['organization_id' => $org->id]);
        $client->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        Sanctum::actingAs($client);

        $this->getJson('/api/admin/ai-telemetry/summary')->assertForbidden();
        $this->getJson('/api/admin/ai-telemetry/detail')->assertForbidden();
        $this->getJson('/api/admin/ai-telemetry/export')->assertForbidden();
    }

    public function test_unauthenticated_request_is_denied(): void
    {
        $this->getJson('/api/admin/ai-telemetry/summary')->assertUnauthorized();
    }

    public function test_summary_excludes_missing_cost_from_spend_total_but_counts_it_separately(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->makeAnalysis($org, $user, 1.5);
        $this->makeAnalysis($org, $user, null); // missing cost (e.g. no pricing schedule covered it)

        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson('/api/admin/ai-telemetry/summary')->assertOk();

        $response->assertJsonPath('total_estimated_cost', 1.5);
        $response->assertJsonPath('analyses_missing_cost', 1);
        $response->assertJsonPath('total_analyses', 2);
    }

    public function test_summary_filters_by_organization(): void
    {
        $orgA = Organization::create(['name' => 'OrgA', 'slug' => 'orga-' . uniqid()]);
        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $orgB = Organization::create(['name' => 'OrgB', 'slug' => 'orgb-' . uniqid()]);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);

        $this->makeAnalysis($orgA, $userA, 2.0);
        $this->makeAnalysis($orgB, $userB, 5.0);

        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson("/api/admin/ai-telemetry/summary?organization_id={$orgA->id}")->assertOk();

        $response->assertJsonPath('total_analyses', 1);
        $response->assertJsonPath('total_estimated_cost', 2);
    }

    public function test_simulation_summary_is_approved_policy_reflects_only_the_configured_approved_candidate(): void
    {
        config(['ai_credit_shadow.approved_candidate' => 'candidate_b']);

        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $analysis = $this->makeAnalysis($org, $user, 1.0);

        app(\App\Services\AI\AiCreditSimulator::class)->simulate(
            $analysis, 'contract_analysis', 5000, now(), \App\Services\AI\AiCreditSimulator::SOURCE_PROSPECTIVE
        );

        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson('/api/admin/ai-telemetry/summary')->assertOk();

        $simulation = collect($response->json('simulation'))->keyBy('candidate_policy_key');
        $this->assertNotEmpty($simulation);
        $this->assertTrue($simulation['candidate_b']['is_approved_policy']);
        $this->assertFalse($simulation['candidate_a']['is_approved_policy']);
    }

    public function test_simulation_summary_is_approved_policy_is_false_for_every_candidate_when_none_configured(): void
    {
        config(['ai_credit_shadow.approved_candidate' => null]);

        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $analysis = $this->makeAnalysis($org, $user, 1.0);

        app(\App\Services\AI\AiCreditSimulator::class)->simulate(
            $analysis, 'contract_analysis', 5000, now(), \App\Services\AI\AiCreditSimulator::SOURCE_PROSPECTIVE
        );

        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson('/api/admin/ai-telemetry/summary')->assertOk();

        $simulation = $response->json('simulation');
        $this->assertNotEmpty($simulation);
        foreach ($simulation as $candidate) {
            $this->assertFalse($candidate['is_approved_policy']);
        }
    }

    public function test_detail_endpoint_returns_paginated_internal_shaped_rows(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->makeAnalysis($org, $user, 1.0);

        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson('/api/admin/ai-telemetry/detail')->assertOk();

        $response->assertJsonStructure(['data' => [['id', 'workflow', 'organization_id', 'organization_name', 'model', 'estimated_cost', 'simulations']], 'total', 'per_page', 'current_page']);
    }

    public function test_export_returns_csv_with_header_row(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->makeAnalysis($org, $user, 1.0);

        Sanctum::actingAs($this->superAdmin());

        $response = $this->get('/api/admin/ai-telemetry/export')->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('organization_name', $content);
    }

    // ── Phase G4C.2D — Commercial Calibration Dashboard ─────────────────

    public function test_summary_calibration_block_reports_cache_hit_rate_and_org_count(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->makeAnalysis($org, $user, 1.0); // provider_called = true (default in helper)
        $this->makeAnalysisWithProviderCalled($org, $user, 0.0, false);

        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson('/api/admin/ai-telemetry/summary')->assertOk();

        $response->assertJsonPath('calibration.cache_hit_rate', 0.5);
        $response->assertJsonPath('calibration.organizations_using_ai', 1);
        $response->assertJsonPath('calibration.completed_executions', 2);
        $response->assertJsonPath('calibration.excluded_from_calibration', 0);
    }

    public function test_summary_normalized_input_size_percentiles_deduplicate_per_analysis_across_candidates(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $analysis = $this->makeAnalysis($org, $user, 1.0);

        // Two candidate policies simulating the SAME analysis must not
        // double-count its normalized input size in the percentile sample.
        app(\App\Services\AI\AiCreditSimulator::class)->simulate(
            $analysis, 'contract_analysis', 10000, now(), \App\Services\AI\AiCreditSimulator::SOURCE_PROSPECTIVE
        );

        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson('/api/admin/ai-telemetry/summary')->assertOk();

        $response->assertJsonPath('calibration.normalized_input_size.sample_size', 1);
        $response->assertJsonPath('calibration.normalized_input_size.p50', 10000);
    }

    public function test_health_endpoint_flags_incomplete_telemetry_and_missing_simulation(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        // Terminal, but provider_called was never recorded — incomplete.
        $this->makeAnalysisWithProviderCalled($org, $user, 1.0, null);

        // Completed but never simulated — missing simulation.
        $unsimulated = $this->makeAnalysis($org, $user, 1.0);

        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson('/api/admin/ai-telemetry/health')->assertOk();

        $response->assertJsonPath('incomplete_telemetry', 1);
        $response->assertJsonPath('missing_normalized_input_or_simulation', 2);
        $response->assertJsonPath('duplicated_simulations', 0);
    }

    public function test_health_endpoint_reports_simulation_errors(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $analysis = $this->makeAnalysis($org, $user, 1.0);

        \App\Models\AiCreditSimulationResult::create([
            'analysable_type' => \App\Models\ContractAiAnalysis::class,
            'analysable_id' => $analysis->id,
            'workflow' => 'contract_analysis',
            'organization_id' => $org->id,
            'candidate_policy_key' => 'candidate_a',
            'candidate_policy_version' => 1,
            'charging_strategy' => 'unresolved',
            'normalization_version' => 'v1',
            'normalized_input_char_count' => 5000,
            'simulation_status' => 'error',
            'resolution_reason' => 'test-induced failure',
            'source' => 'prospective',
            'calculated_at' => now(),
        ]);

        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson('/api/admin/ai-telemetry/health')->assertOk();

        $response->assertJsonPath('simulation_errors', 1);
    }

    public function test_health_endpoint_requires_super_admin_or_admin(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $client = User::factory()->create(['organization_id' => $org->id]);
        $client->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        Sanctum::actingAs($client);

        $this->getJson('/api/admin/ai-telemetry/health')->assertForbidden();
    }

    // ── Phase G4C.2G — Unique-Document Metric Correction ────────────────

    public function test_provider_backed_execution_plus_cache_hit_of_same_hash_produces_one_document_size_sample(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $hash = hash('sha256', 'shared-document');

        $real = $this->makeAnalysisWithProviderCalled($org, $user, 0.5, true, $hash);
        $cacheHit = $this->makeAnalysisWithProviderCalled($org, $user, 0.0, false, $hash);

        $simulator = app(\App\Services\AI\AiCreditSimulator::class);
        $simulator->simulate($real, 'contract_analysis', 20000, now(), \App\Services\AI\AiCreditSimulator::SOURCE_PROSPECTIVE);
        $simulator->simulate($cacheHit, 'contract_analysis', 20000, now(), \App\Services\AI\AiCreditSimulator::SOURCE_PROSPECTIVE);

        Sanctum::actingAs($this->superAdmin());
        $response = $this->getJson('/api/admin/ai-telemetry/summary')->assertOk();

        // Two executions, but ONE unique document — the cache-hit reuse of
        // the exact same document_hash must not double-weight the size sample.
        $response->assertJsonPath('calibration.completed_executions', 2);
        $response->assertJsonPath('calibration.unique_documents', 1);
        $response->assertJsonPath('calibration.normalized_input_size.sample_size', 1);
        $response->assertJsonPath('calibration.normalized_input_size.p50', 20000);
    }

    public function test_repeated_cache_hits_do_not_change_percentiles(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $hash = hash('sha256', 'repeatedly-cached-document');

        $real = $this->makeAnalysisWithProviderCalled($org, $user, 0.3, true, $hash);
        app(\App\Services\AI\AiCreditSimulator::class)->simulate(
            $real, 'contract_analysis', 12000, now(), \App\Services\AI\AiCreditSimulator::SOURCE_PROSPECTIVE
        );

        Sanctum::actingAs($this->superAdmin());
        $before = $this->getJson('/api/admin/ai-telemetry/summary')->assertOk()->json('calibration.normalized_input_size');

        // Three more cache-hit reuses of the exact same document.
        foreach (range(1, 3) as $_) {
            $cacheHit = $this->makeAnalysisWithProviderCalled($org, $user, 0.0, false, $hash);
            app(\App\Services\AI\AiCreditSimulator::class)->simulate(
                $cacheHit, 'contract_analysis', 12000, now(), \App\Services\AI\AiCreditSimulator::SOURCE_PROSPECTIVE
            );
        }

        $after = $this->getJson('/api/admin/ai-telemetry/summary')->assertOk()->json('calibration.normalized_input_size');

        $this->assertSame($before, $after);
        $this->assertSame(1, $after['sample_size']);
    }

    public function test_two_genuinely_different_hashes_produce_real_size_spread(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $small = $this->makeAnalysisWithProviderCalled($org, $user, 0.1, true, hash('sha256', 'small-doc'));
        $large = $this->makeAnalysisWithProviderCalled($org, $user, 0.4, true, hash('sha256', 'large-doc'));
        $largeCacheHit = $this->makeAnalysisWithProviderCalled($org, $user, 0.0, false, hash('sha256', 'large-doc'));

        $simulator = app(\App\Services\AI\AiCreditSimulator::class);
        $simulator->simulate($small, 'contract_analysis', 5000, now(), \App\Services\AI\AiCreditSimulator::SOURCE_PROSPECTIVE);
        $simulator->simulate($large, 'contract_analysis', 280000, now(), \App\Services\AI\AiCreditSimulator::SOURCE_PROSPECTIVE);
        $simulator->simulate($largeCacheHit, 'contract_analysis', 280000, now(), \App\Services\AI\AiCreditSimulator::SOURCE_PROSPECTIVE);

        Sanctum::actingAs($this->superAdmin());
        $response = $this->getJson('/api/admin/ai-telemetry/summary')->assertOk();

        // 3 executions, but only 2 unique documents — before the fix this
        // would have sampled [5000, 280000, 280000], giving P50 = P99. With
        // the fix it samples [5000, 280000] — a genuine spread.
        $response->assertJsonPath('calibration.unique_documents', 2);
        $response->assertJsonPath('calibration.normalized_input_size.sample_size', 2);
        $response->assertJsonPath('calibration.normalized_input_size.p50', 5000);
        $response->assertJsonPath('calibration.normalized_input_size.p99', 280000);
    }

    public function test_cache_hits_do_not_contribute_a_zero_cost_provider_sample(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->makeAnalysisWithProviderCalled($org, $user, 0.40, true, hash('sha256', 'real-call'));
        // A cache hit's real, correctly-recorded $0 cost must not drag the
        // average provider cost down — it isn't a $0 provider call, it's no
        // provider call at all.
        $this->makeAnalysisWithProviderCalled($org, $user, 0.0, false, hash('sha256', 'cache-hit'));

        Sanctum::actingAs($this->superAdmin());
        $response = $this->getJson('/api/admin/ai-telemetry/summary')->assertOk();

        $response->assertJsonPath('calibration.average_provider_cost', 0.4);
    }

    public function test_provider_backed_cost_remains_counted_once_in_total_spend(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->makeAnalysisWithProviderCalled($org, $user, 0.40, true, hash('sha256', 'real-call'));
        $this->makeAnalysisWithProviderCalled($org, $user, 0.0, false, hash('sha256', 'cache-hit'));

        Sanctum::actingAs($this->superAdmin());
        $response = $this->getJson('/api/admin/ai-telemetry/summary')->assertOk();

        $response->assertJsonPath('calibration.total_provider_spend', 0.4);
    }

    public function test_failed_analyses_do_not_contribute_size_or_cost_samples(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $hash = hash('sha256', 'failed-then-succeeded');

        $failed = $this->makeAnalysis($org, $user, null, 'failed', $hash);
        app(\App\Services\AI\AiCreditSimulator::class)->simulate(
            $failed, 'contract_analysis', 99999, now(), \App\Services\AI\AiCreditSimulator::SOURCE_PROSPECTIVE
        );

        Sanctum::actingAs($this->superAdmin());
        $response = $this->getJson('/api/admin/ai-telemetry/summary')->assertOk();

        // The failed analysis has a simulation row (a legitimate operational
        // possibility — see AiCreditSimulator's own docblock), but a failed
        // execution is never calibration-eligible and must not appear as a
        // unique document or contribute to the size sample.
        $response->assertJsonPath('calibration.unique_documents', 0);
        $response->assertJsonPath('calibration.normalized_input_size.sample_size', 0);
    }

    public function test_missing_document_hash_is_excluded_from_unique_documents_not_treated_as_zero(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P' . uniqid()]);
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id,
            'created_by' => $user->id, 'type' => 'main_contract', 'title' => 'C',
        ]);

        // A legacy-style row that reached a terminal status without ever
        // recording a document_hash.
        ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'completed', 'workflow' => 'contract_analysis',
            'model' => 'claude-sonnet-5', 'provider' => 'anthropic', 'provider_called' => true,
            'document_hash' => null, 'estimated_cost' => 0.2, 'completed_at' => now(),
        ]);

        Sanctum::actingAs($this->superAdmin());
        $response = $this->getJson('/api/admin/ai-telemetry/summary')->assertOk();

        $response->assertJsonPath('calibration.completed_executions', 1);
        $response->assertJsonPath('calibration.unique_documents', 0);
    }

    public function test_contract_and_trade_package_analyses_never_collapse_even_with_the_same_hash(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $sharedHash = hash('sha256', 'coincidentally-identical-bytes');

        $this->makeAnalysisWithProviderCalled($org, $user, 0.1, true, $sharedHash);

        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P' . uniqid()]);
        $tradePackage = \App\Models\TradePackage::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'name' => 'TP', 'slug' => 'tp-' . uniqid(),
        ]);
        \App\Models\TradePackageAiAnalysis::create([
            'trade_package_id' => $tradePackage->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'completed', 'workflow' => 'trade_package_analysis',
            'model' => 'claude-sonnet-5', 'provider' => 'anthropic', 'provider_called' => true,
            'document_hash' => $sharedHash, 'estimated_cost' => 0.1, 'completed_at' => now(),
        ]);

        Sanctum::actingAs($this->superAdmin());
        $response = $this->getJson('/api/admin/ai-telemetry/summary')->assertOk();

        // Same hash, but a Contract Analysis and a Trade Package Analysis are
        // never the same "document" — each workflow must report its own
        // unique-document count independently.
        $response->assertJsonPath('by_workflow.contract_analysis.unique_documents', 1);
        $response->assertJsonPath('by_workflow.trade_package_analysis.unique_documents', 1);
        $response->assertJsonPath('calibration.unique_documents', 2);
    }

    public function test_organization_diversity_remains_based_on_real_distinct_organization_ids(): void
    {
        $orgA = Organization::create(['name' => 'OrgA', 'slug' => 'orga-' . uniqid()]);
        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $orgB = Organization::create(['name' => 'OrgB', 'slug' => 'orgb-' . uniqid()]);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);

        // Same document, submitted by two different real organisations —
        // this is the one case where a shared hash legitimately should NOT
        // collapse cross-organisation counting (unique-document dedup is
        // per-workflow only, never merged across organisations).
        $hash = hash('sha256', 'multi-org-document');
        $this->makeAnalysisWithProviderCalled($orgA, $userA, 0.2, true, $hash);
        $this->makeAnalysisWithProviderCalled($orgB, $userB, 0.2, true, $hash);

        Sanctum::actingAs($this->superAdmin());
        $response = $this->getJson('/api/admin/ai-telemetry/summary')->assertOk();

        $response->assertJsonPath('calibration.organizations_using_ai', 2);
    }

    public function test_representative_telemetry_reads_ready_once_real_unique_document_spread_exists_despite_cache_hit_duplicates(): void
    {
        $orgA = Organization::create(['name' => 'OrgA', 'slug' => 'orga-' . uniqid()]);
        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $orgB = Organization::create(['name' => 'OrgB', 'slug' => 'orgb-' . uniqid()]);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);

        $simulator = app(\App\Services\AI\AiCreditSimulator::class);

        // Contract Analysis: one large document, real call + 2 cache-hit
        // reuses — exactly the shape that broke the pre-fix formula (a
        // duplicated document weighting P50 toward itself).
        $large = $this->makeAnalysisWithProviderCalled($orgA, $userA, 0.4, true, hash('sha256', 'large-contract'));
        $simulator->simulate($large, 'contract_analysis', 280000, now(), \App\Services\AI\AiCreditSimulator::SOURCE_PROSPECTIVE);
        foreach (range(1, 2) as $_) {
            $reuse = $this->makeAnalysisWithProviderCalled($orgA, $userA, 0.0, false, hash('sha256', 'large-contract'));
            $simulator->simulate($reuse, 'contract_analysis', 280000, now(), \App\Services\AI\AiCreditSimulator::SOURCE_PROSPECTIVE);
        }

        // Trade Package Analysis: one small document, second organisation.
        $project = Project::create(['organization_id' => $orgB->id, 'created_by' => $userB->id, 'name' => 'P' . uniqid()]);
        $tradePackage = \App\Models\TradePackage::create([
            'project_id' => $project->id, 'organization_id' => $orgB->id, 'created_by' => $userB->id,
            'name' => 'TP', 'slug' => 'tp-' . uniqid(),
        ]);
        $small = \App\Models\TradePackageAiAnalysis::create([
            'trade_package_id' => $tradePackage->id, 'organization_id' => $orgB->id, 'project_id' => $project->id,
            'created_by' => $userB->id, 'status' => 'completed', 'workflow' => 'trade_package_analysis',
            'model' => 'claude-sonnet-5', 'provider' => 'anthropic', 'provider_called' => true,
            'document_hash' => hash('sha256', 'small-trade-package'), 'estimated_cost' => 0.09, 'completed_at' => now(),
        ]);
        $simulator->simulate($small, 'trade_package_analysis', 47000, now(), \App\Services\AI\AiCreditSimulator::SOURCE_PROSPECTIVE);

        $service = app(\App\Services\Monitoring\AiTelemetryReportingService::class);
        $summary = $service->summary([]);
        $health = $service->telemetryHealth([]);

        $this->assertSame(2, $summary['calibration']['unique_documents']);
        $this->assertSame(2, $summary['calibration']['normalized_input_size']['sample_size']);

        $readiness = \App\Support\AI\AiCreditReadinessGate::evaluate($summary, $health, config('ai_credit_readiness', []));

        // No readiness threshold was touched — this is the exact existing
        // formula (sample_size > 1 && P50 !== P99) now fed correct,
        // deduplicated input.
        $this->assertSame(\App\Support\AI\AiCreditReadinessGate::STATUS_READY, $readiness['items']['representative_telemetry']['status']);
    }
}

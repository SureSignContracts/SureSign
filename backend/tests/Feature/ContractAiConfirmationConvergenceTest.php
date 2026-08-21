<?php

namespace Tests\Feature;

use App\Models\AiCreditLedgerEntry;
use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contract AI Workflow Convergence, Phase 2 + Phase 2A.
 *
 * No backend production code changed in Phase 2 — that phase was a frontend
 * state/control-flow change (AiContractWizard's "Upload & Analyse New
 * Contract" path now hands its completed analysis to the same
 * ContractAnalysisReview + POST /ai/analyses/{id}/confirm pipeline Project
 * Setup and the existing-Contract AI flow already use, instead of its own
 * bespoke reviewing/form/PUT shortcut).
 *
 * Phase 2A fixed the one real backend defect Phase 2's own test-writing
 * surfaced: `contracts.currency` had a raw DB-level `default('AUD')`
 * (2026_01_01_000005_create_contracts_table.php), which meant
 * ContractIntelligenceSyncService's "only write an empty field" guard
 * always treated a freshly-created stub Contract's currency as
 * "already set" and never wrote the AI-confirmed value. This was NOT a
 * regression introduced by Phase 2's convergence — discovery proved the
 * OLD, unconverged wizard path never persisted currency for a stub
 * Contract either, since its final save was `PUT /contracts/{id}`
 * (`ContractController::update()`, which has never accepted a `currency`
 * field at all). Fixed via
 * `2026_08_21_000002_fix_contracts_currency_default.php` (schema-only,
 * nullable + no default, no existing-data rewrite — see that migration's
 * own docblock for why a bulk rewrite, unlike the sibling
 * `projects.currency` fix, was not safe to do here).
 * `ContractIntelligenceSyncService` itself was not modified — its existing
 * empty-field guard was already correct; it simply had nothing to work
 * with before this schema fix.
 *
 * `Batch2ClientPermissionsTest::test_client_can_view_and_confirm_their_own_contracts_ai_analysis()`
 * already proves confirmation succeeds against a minimal (draft, no
 * commercial fields) Contract and persists `confirmed_data_json`/status —
 * exactly the shape AiContractWizard's stub Contract has. `Batch2...
 * ::test_client_cannot_access_another_organisations_contract_ai_analysis()`
 * already proves cross-organisation confirmation is forbidden.
 * `ProjectContractSuggestionsTest` extensively proves Project fields are
 * never changed except by an explicit, separate apply action.
 *
 * This file covers the convergence-relevant invariants existing coverage
 * doesn't already prove: that confirming a minimal/stub Contract's
 * analysis actually writes deterministic fields onto THAT Contract via
 * ContractIntelligenceSyncService (not just a status flag) — including
 * currency, now that the schema allows it, across USD/GBP/EUR/AUD and the
 * "genuinely null before confirmation" starting state — that an existing,
 * explicitly-set Contract currency is preserved (the empty-only guard is
 * unweakened), that the Project record is completely untouched by
 * confirmation alone, and that confirmation triggers zero AI-credit ledger
 * activity and zero re-dispatch of the analysis job.
 */
class ContractAiConfirmationConvergenceTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgProjectUser(): array
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid(), 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'Project', 'status' => 'active']);

        return compact('org', 'user', 'project');
    }

    /**
     * Mirrors exactly the shape AiContractWizard's `createAndAnalyseMutation`
     * produces — a freshly-created, minimal Contract (title + type + draft
     * status only), before any AI confirmation has touched it.
     */
    private function makeMinimalContract(Project $project, User $user): Contract
    {
        return Contract::create([
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $user->id,
            'title'           => 'Uploaded Contract',
            'type'            => 'main_contract',
            'status'          => 'draft',
        ]);
    }

    private function makeCompletedAnalysis(Contract $contract, Organization $org, Project $project, User $user, array $confirmedData): ContractAiAnalysis
    {
        return ContractAiAnalysis::create([
            'contract_id'       => $contract->id,
            'organization_id'   => $org->id,
            'project_id'        => $project->id,
            'status'            => 'completed',
            'raw_response_json' => $confirmedData,
            'created_by'        => $user->id,
        ]);
    }

    // ── Invariant 1 + 3: minimal Contract, confirmation drives real sync ──

    public function test_confirming_a_minimal_stub_contracts_analysis_writes_deterministic_contract_fields(): void
    {
        ['org' => $org, 'user' => $user, 'project' => $project] = $this->makeOrgProjectUser();
        // CurrencyService::validateAiExtractedCode() only accepts an
        // AI-extracted currency that already matches the project's own
        // resolved currency (never lets AI introduce a new one) — set it
        // explicitly so the 'GBP' in $confirmedData below is accepted by
        // the real sync logic, not silently skipped.
        $project->update(['currency' => 'GBP']);
        $contract = $this->makeMinimalContract($project, $user);

        $confirmedData = [
            'contract_overview' => [
                'contract_title' => 'JCT Design and Build 2016',
                'standard_form'  => 'JCT DB 2016',
            ],
            'commercial' => [
                'contract_sum'          => '150000',
                'currency'              => 'GBP',
                'retention_percent'     => 3,
            ],
            'dates' => [
                'commencement_date' => '2026-09-01',
                'completion_date'   => '2027-03-01',
            ],
        ];
        $analysis = $this->makeCompletedAnalysis($contract, $org, $project, $user, $confirmedData);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/ai/analyses/{$analysis->id}/confirm", ['confirmed_data' => $confirmedData]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('contract_ai_analyses', ['id' => $analysis->id, 'status' => 'confirmed']);
        $this->assertSame($confirmedData, $analysis->fresh()->confirmed_data_json);

        // Contract fields must reflect confirmed data via the deterministic
        // sync service — never a second, frontend-driven PUT re-applying it.
        // Currency is now included (Phase 2A) — previously untestable here
        // because the legacy DB default made the Contract's currency
        // "already set" before confirmation ever ran.
        $contract->refresh();
        $this->assertSame('JCT DB 2016', $contract->form_of_contract);
        $this->assertEquals(150000, (float) $contract->contract_sum);
        $this->assertSame('GBP', $contract->currency);
        $this->assertEquals(3, (float) $contract->retention_percentage);
        $this->assertSame('2026-09-01', $contract->commencement_date->format('Y-m-d'));
        $this->assertSame('2027-03-01', $contract->completion_date->format('Y-m-d'));
    }

    // ── A: minimal Contract's currency is genuinely null, not 'AUD' ───────

    public function test_a_minimal_contract_currency_is_null_not_aud(): void
    {
        ['project' => $project, 'user' => $user] = $this->makeOrgProjectUser();
        $contract = $this->makeMinimalContract($project, $user);

        // `Contract::create()` without an explicit `currency` returns a
        // model with that attribute simply unset in memory — `->fresh()`
        // re-reads the actual persisted row, which is where the schema's
        // (now-removed) default would have applied.
        $this->assertNull($contract->fresh()->currency, 'A freshly-created minimal Contract must never silently default to AUD.');
    }

    // ── B/C/D/E: confirmed USD/GBP/EUR/AUD all correctly populate an empty Contract ──

    public function test_confirmed_currency_populates_an_empty_contract_for_usd_gbp_eur_and_aud(): void
    {
        foreach (['USD', 'GBP', 'EUR', 'AUD'] as $currencyCode) {
            ['org' => $org, 'user' => $user, 'project' => $project] = $this->makeOrgProjectUser();
            // CurrencyService::validateAiExtractedCode() only accepts an
            // AI-extracted currency that already matches the project's own
            // resolved currency — set it explicitly per iteration so each
            // currency is genuinely accepted by the real, unmodified sync
            // logic, not silently skipped.
            $project->update(['currency' => $currencyCode]);
            $contract = $this->makeMinimalContract($project, $user);
            $this->assertNull($contract->fresh()->currency, "Sanity check: {$currencyCode} case must start from a genuinely null Contract currency.");

            $confirmedData = ['contract_overview' => [], 'commercial' => ['currency' => $currencyCode], 'dates' => []];
            $analysis = $this->makeCompletedAnalysis($contract, $org, $project, $user, $confirmedData);

            Sanctum::actingAs($user);
            $this->postJson("/api/ai/analyses/{$analysis->id}/confirm", ['confirmed_data' => $confirmedData])
                ->assertStatus(200);

            $this->assertSame(
                $currencyCode,
                $contract->fresh()->currency,
                "Confirming a {$currencyCode} analysis must populate the previously-empty Contract currency with {$currencyCode} — including AUD when it is a genuinely confirmed value, not a stale database default."
            );
        }
    }

    // ── F: an explicitly-set existing Contract currency is preserved ──────

    public function test_an_explicitly_set_existing_contract_currency_is_not_overwritten_by_confirmation(): void
    {
        ['org' => $org, 'user' => $user, 'project' => $project] = $this->makeOrgProjectUser();
        $project->update(['currency' => 'USD']);
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'title' => 'Existing Contract', 'type' => 'main_contract', 'status' => 'active',
            // Explicitly set at creation — the real, non-default case
            // this schema fix must never disturb.
            'currency' => 'GBP',
        ]);

        $confirmedData = ['contract_overview' => [], 'commercial' => ['currency' => 'USD'], 'dates' => []];
        $analysis = $this->makeCompletedAnalysis($contract, $org, $project, $user, $confirmedData);

        Sanctum::actingAs($user);
        $this->postJson("/api/ai/analyses/{$analysis->id}/confirm", ['confirmed_data' => $confirmedData])
            ->assertStatus(200);

        // ContractIntelligenceSyncService's empty-only guard is unweakened
        // by this schema fix — a genuinely non-empty existing value is
        // still preserved unless force_overwrite is explicitly passed.
        $this->assertSame('GBP', $contract->fresh()->currency);
    }

    public function test_force_overwrite_still_replaces_an_existing_contract_currency(): void
    {
        ['org' => $org, 'user' => $user, 'project' => $project] = $this->makeOrgProjectUser();
        $project->update(['currency' => 'USD']);
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'title' => 'Existing Contract', 'type' => 'main_contract', 'status' => 'active',
            'currency' => 'GBP',
        ]);

        $confirmedData = ['contract_overview' => [], 'commercial' => ['currency' => 'USD'], 'dates' => []];
        $analysis = $this->makeCompletedAnalysis($contract, $org, $project, $user, $confirmedData);

        Sanctum::actingAs($user);
        $this->postJson("/api/ai/analyses/{$analysis->id}/confirm", [
            'confirmed_data'  => $confirmedData,
            'force_overwrite' => true,
        ])->assertStatus(200);

        $this->assertSame('USD', $contract->fresh()->currency);
    }

    // ── Invariant 4: Project fields untouched by confirmation alone ──────

    public function test_confirming_an_analysis_never_changes_project_fields(): void
    {
        ['org' => $org, 'user' => $user, 'project' => $project] = $this->makeOrgProjectUser();
        $project->update(['currency' => null, 'start_date' => null, 'contract_value' => null]);
        $contract = $this->makeMinimalContract($project, $user);

        $confirmedData = [
            'contract_overview' => ['contract_title' => 'Main Contract'],
            'commercial'        => ['contract_sum' => '250000', 'currency' => 'USD'],
            'dates'             => ['commencement_date' => '2026-10-01'],
        ];
        $analysis = $this->makeCompletedAnalysis($contract, $org, $project, $user, $confirmedData);

        Sanctum::actingAs($user);
        $this->postJson("/api/ai/analyses/{$analysis->id}/confirm", ['confirmed_data' => $confirmedData])
            ->assertStatus(200);

        // Confirmation may populate the CONTRACT; it must never silently
        // populate the PROJECT — that remains the explicit, separate
        // Project Suggestions apply() action (ProjectContractSuggestionsTest
        // covers that path exhaustively; this asserts the negative case for
        // confirmation alone).
        $project->refresh();
        $this->assertNull($project->currency);
        $this->assertNull($project->start_date);
        $this->assertNull($project->contract_value);
    }

    // ── Invariant 6: confirmation never touches AI credits or re-runs analysis ──

    public function test_confirmation_creates_no_ledger_entries_and_dispatches_no_new_analysis_job(): void
    {
        ['org' => $org, 'user' => $user, 'project' => $project] = $this->makeOrgProjectUser();
        $contract = $this->makeMinimalContract($project, $user);
        $confirmedData = ['contract_overview' => ['contract_title' => 'Main Contract']];
        $analysis = $this->makeCompletedAnalysis($contract, $org, $project, $user, $confirmedData);

        Queue::fake();
        $ledgerCountBefore = AiCreditLedgerEntry::count();
        $analysisCountBefore = ContractAiAnalysis::count();

        Sanctum::actingAs($user);
        $this->postJson("/api/ai/analyses/{$analysis->id}/confirm", ['confirmed_data' => $confirmedData])
            ->assertStatus(200);

        // Reserve/settle/release live exclusively in AnalyseContractWithAiJob
        // (the async provider-call job) — confirmAnalysis() never touches
        // the ledger, matching the frontend's reliance on `initialAnalysis`
        // never triggering a second provider call.
        $this->assertSame($ledgerCountBefore, AiCreditLedgerEntry::count());
        // No second ContractAiAnalysis row was created — this confirmation
        // reused the exact existing analysis, never started a new one.
        $this->assertSame($analysisCountBefore, ContractAiAnalysis::count());
        Queue::assertNotPushed(\App\Jobs\AnalyseContractWithAiJob::class);
    }

    // ── Re-confirming the same analysis stays safe (existing idempotency) ──

    public function test_re_confirming_an_already_confirmed_analysis_does_not_duplicate_seeded_intelligence_rows(): void
    {
        ['org' => $org, 'user' => $user, 'project' => $project] = $this->makeOrgProjectUser();
        $contract = $this->makeMinimalContract($project, $user);
        $confirmedData = [
            'contract_overview' => ['contract_title' => 'Main Contract'],
            'deadlines' => [
                ['name' => 'Practical Completion Notice', 'category' => 'other'],
            ],
        ];
        $analysis = $this->makeCompletedAnalysis($contract, $org, $project, $user, $confirmedData);
        Sanctum::actingAs($user);

        $this->postJson("/api/ai/analyses/{$analysis->id}/confirm", ['confirmed_data' => $confirmedData])->assertStatus(200);
        $firstCount = \App\Models\ContractDeadline::where('contract_ai_analysis_id', $analysis->id)->count();

        // Re-confirm the same, already-confirmed analysis (double-click/retry).
        $this->postJson("/api/ai/analyses/{$analysis->id}/confirm", ['confirmed_data' => $confirmedData])->assertStatus(200);
        $secondCount = \App\Models\ContractDeadline::where('contract_ai_analysis_id', $analysis->id)->count();

        $this->assertGreaterThan(0, $firstCount);
        $this->assertSame($firstCount, $secondCount, 'Re-confirming must not duplicate seeded intelligence rows.');
    }
}

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
 * Phase E — Contract-Assisted Project Setup: Review & Apply Confirmed
 * Contract Suggestions to Project.
 *
 * Covers GET .../project-suggestions and POST .../apply-project-suggestions
 * (App\Http\Controllers\Api\ProjectContractSetupController /
 * App\Services\ProjectContractSetupSyncService) — suggestion generation,
 * the apply whitelist/atomicity, money/date/role safety rules, and tenant
 * isolation. Every scenario builds its own confirmed_data_json matching the
 * real v2 analysis schema (contract_overview/parties/commercial/dates)
 * rather than a simplified stand-in, since the service reads that shape
 * directly for currency/retention/role identity.
 */
class ProjectContractSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(string $name = 'Concrete Specialist Ltd'): Organization
    {
        return Organization::create(['name' => $name, 'slug' => str()->slug($name) . '-' . str()->random(6), 'timezone' => 'Europe/London']);
    }

    private function makeUser(Organization $org, string $role = 'Client'): User
    {
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        return $user;
    }

    private function makeProject(Organization $org, User $user, array $attrs = []): Project
    {
        return Project::create(array_merge([
            'organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'Riverside Apartments',
        ], $attrs));
    }

    private function makeContract(Project $project, User $user, array $attrs = []): Contract
    {
        return Contract::create(array_merge([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'created_by' => $user->id, 'type' => 'main_contract', 'title' => 'Riverside Main Contract',
        ], $attrs));
    }

    private function makeAnalysis(Project $project, Contract $contract, array $attrs = []): ContractAiAnalysis
    {
        return ContractAiAnalysis::create(array_merge([
            'contract_id' => $contract->id, 'organization_id' => $project->organization_id,
            'project_id' => $project->id, 'status' => 'completed', 'created_by' => $contract->created_by,
        ], $attrs));
    }

    /** A realistic v2 confirmed_data_json payload, overridable per test. */
    private function confirmedV2(array $overrides = []): array
    {
        return array_replace_recursive([
            'contract_overview' => [
                'contract_title' => 'Riverside Main Contract',
                'contract_type'  => 'JCT Design and Build',
                'standard_form'  => 'JCT Design and Build 2016',
                'currency'       => 'USD',
                'is_subcontract' => false,
            ],
            'parties' => [
                'main_contractor' => ['name' => 'Concrete Specialist Ltd'],
                'employer'        => ['name' => 'Property Holdings Ltd'],
            ],
            'commercial' => [
                'contract_sum'      => '650000',
                'currency'          => 'USD',
                'retention_percent' => 5,
            ],
            'dates' => [
                'commencement_date' => '2026-09-01',
                'completion_date'   => '2027-03-01',
            ],
        ], $overrides);
    }

    // ── Suggestion generation ────────────────────────────────────────────────

    public function test_confirmed_contract_produces_supported_suggestions(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user, [
            'contract_sum' => 650000, 'commencement_date' => '2026-09-01', 'completion_date' => '2027-03-01',
            'form_of_contract' => 'JCT Design and Build 2016',
        ]);
        $analysis = $this->makeAnalysis($project, $contract, [
            'status' => 'confirmed', 'confirmed_data_json' => $this->confirmedV2(),
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/project-suggestions");

        $response->assertStatus(200);
        $keys = collect($response->json('suggestions'))->pluck('key')->all();
        $this->assertContains('contract_value_currency', $keys);
        $this->assertContains('start_date', $keys);
        $this->assertContains('end_date', $keys);
        $this->assertContains('contract_type', $keys);
        $this->assertContains('retention_percentage', $keys);
        $this->assertContains('organization_role', $keys); // Org name matches main_contractor
    }

    public function test_completed_but_unconfirmed_analysis_is_rejected(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);
        $analysis = $this->makeAnalysis($project, $contract, ['status' => 'completed']);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/project-suggestions");

        $response->assertStatus(422);
    }

    public function test_pending_processing_failed_cancelled_analyses_are_all_rejected(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        Sanctum::actingAs($user);

        foreach (['pending', 'processing', 'failed', 'cancelled'] as $status) {
            $contract = $this->makeContract($project, $user);
            $analysis = $this->makeAnalysis($project, $contract, ['status' => $status]);

            $response = $this->getJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/project-suggestions");
            $response->assertStatus(422, "status={$status} should be rejected");
        }
    }

    public function test_no_cross_contract_merge_suggestions_scoped_to_one_contract(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contractA = $this->makeContract($project, $user, ['title' => 'Contract A', 'contract_sum' => 100000]);
        $contractB = $this->makeContract($project, $user, ['title' => 'Contract B', 'contract_sum' => 999999]);
        $analysisA = $this->makeAnalysis($project, $contractA, [
            'status' => 'confirmed',
            'confirmed_data_json' => $this->confirmedV2(['commercial' => ['contract_sum' => '100000', 'currency' => 'GBP']]),
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/projects/{$project->id}/contracts/{$contractA->id}/analyses/{$analysisA->id}/project-suggestions");

        $response->assertStatus(200);
        $money = collect($response->json('suggestions'))->firstWhere('key', 'contract_value_currency');
        $this->assertEquals(100000.0, $money['suggested']['value']);
        $this->assertEquals($contractA->id, $response->json('contract_id'));
    }

    public function test_analysis_belonging_to_a_different_contract_is_rejected(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contractA = $this->makeContract($project, $user, ['title' => 'Contract A']);
        $contractB = $this->makeContract($project, $user, ['title' => 'Contract B']);
        $analysisForB = $this->makeAnalysis($project, $contractB, ['status' => 'confirmed', 'confirmed_data_json' => $this->confirmedV2()]);
        Sanctum::actingAs($user);

        // Analysis genuinely belongs to Contract B, but the URL claims Contract A.
        $response = $this->getJson("/api/projects/{$project->id}/contracts/{$contractA->id}/analyses/{$analysisForB->id}/project-suggestions");

        $response->assertStatus(404);
    }

    public function test_missing_source_field_produces_no_suggestion(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user); // no contract_sum, no dates, no form_of_contract
        $analysis = $this->makeAnalysis($project, $contract, [
            'status' => 'confirmed',
            'confirmed_data_json' => ['contract_overview' => ['currency' => 'GBP'], 'parties' => [], 'commercial' => [], 'dates' => []],
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/project-suggestions");

        $response->assertStatus(200);
        $this->assertEmpty($response->json('suggestions'));
    }

    public function test_already_matches_when_current_equals_suggested(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user, ['start_date' => '2026-09-01']);
        $contract = $this->makeContract($project, $user, ['commencement_date' => '2026-09-01']);
        $analysis = $this->makeAnalysis($project, $contract, [
            'status' => 'confirmed',
            'confirmed_data_json' => ['contract_overview' => [], 'parties' => [], 'commercial' => [], 'dates' => ['commencement_date' => '2026-09-01']],
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/project-suggestions");

        $row = collect($response->json('suggestions'))->firstWhere('key', 'start_date');
        $this->assertTrue($row['already_matches']);
        $this->assertFalse($row['default_selected']);
    }

    public function test_populated_differing_value_is_not_preselected(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user, ['start_date' => '2026-01-01']);
        $contract = $this->makeContract($project, $user, ['commencement_date' => '2026-09-01']);
        $analysis = $this->makeAnalysis($project, $contract, [
            'status' => 'confirmed',
            'confirmed_data_json' => ['contract_overview' => [], 'parties' => [], 'commercial' => [], 'dates' => ['commencement_date' => '2026-09-01']],
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/project-suggestions");

        $row = collect($response->json('suggestions'))->firstWhere('key', 'start_date');
        $this->assertFalse($row['already_matches']);
        $this->assertFalse($row['default_selected']);
    }

    public function test_blank_project_value_is_preselected(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user); // start_date null
        $contract = $this->makeContract($project, $user, ['commencement_date' => '2026-09-01']);
        $analysis = $this->makeAnalysis($project, $contract, [
            'status' => 'confirmed',
            'confirmed_data_json' => ['contract_overview' => [], 'parties' => [], 'commercial' => [], 'dates' => ['commencement_date' => '2026-09-01']],
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/project-suggestions");

        $row = collect($response->json('suggestions'))->firstWhere('key', 'start_date');
        $this->assertTrue($row['default_selected']);
    }

    // ── Apply ─────────────────────────────────────────────────────────────────

    public function test_selected_single_suggestion_applies(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user, ['commencement_date' => '2026-09-01']);
        $analysis = $this->makeAnalysis($project, $contract, [
            'status' => 'confirmed',
            'confirmed_data_json' => ['contract_overview' => [], 'parties' => [], 'commercial' => [], 'dates' => ['commencement_date' => '2026-09-01']],
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/apply-project-suggestions", [
            'suggestions' => ['start_date'],
        ]);

        $response->assertStatus(200);
        $this->assertEquals(['start_date'], $response->json('applied'));
        $this->assertEquals('2026-09-01', $project->fresh()->start_date->toDateString());
    }

    public function test_multiple_selected_suggestions_apply_atomically(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user, [
            'contract_sum' => 650000, 'commencement_date' => '2026-09-01', 'completion_date' => '2027-03-01',
        ]);
        $analysis = $this->makeAnalysis($project, $contract, ['status' => 'confirmed', 'confirmed_data_json' => $this->confirmedV2()]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/apply-project-suggestions", [
            'suggestions' => ['contract_value_currency', 'start_date', 'end_date'],
        ]);

        $response->assertStatus(200);
        $fresh = $project->fresh();
        $this->assertEquals(650000.0, (float) $fresh->contract_value);
        $this->assertEquals('USD', $fresh->currency);
        $this->assertEquals('2026-09-01', $fresh->start_date->toDateString());
        $this->assertEquals('2027-03-01', $fresh->end_date->toDateString());
    }

    public function test_unselected_project_fields_remain_unchanged(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user, ['description' => 'Original description', 'code' => 'RIV-01']);
        $contract = $this->makeContract($project, $user, ['contract_sum' => 650000, 'commencement_date' => '2026-09-01']);
        $analysis = $this->makeAnalysis($project, $contract, ['status' => 'confirmed', 'confirmed_data_json' => $this->confirmedV2()]);
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/apply-project-suggestions", [
            'suggestions' => ['start_date'],
        ])->assertStatus(200);

        $fresh = $project->fresh();
        $this->assertEquals('Original description', $fresh->description);
        $this->assertEquals('RIV-01', $fresh->code);
        $this->assertNull($fresh->contract_value); // contract_value_currency was NOT selected
    }

    public function test_arbitrary_unsupported_suggestion_key_is_rejected(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);
        $analysis = $this->makeAnalysis($project, $contract, ['status' => 'confirmed', 'confirmed_data_json' => $this->confirmedV2()]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/apply-project-suggestions", [
            'suggestions' => ['client_id'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('suggestions.0');
    }

    public function test_frontend_cannot_submit_arbitrary_raw_project_values(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user, ['commencement_date' => '2026-09-01']);
        $analysis = $this->makeAnalysis($project, $contract, [
            'status' => 'confirmed',
            'confirmed_data_json' => ['contract_overview' => [], 'parties' => [], 'commercial' => [], 'dates' => ['commencement_date' => '2026-09-01']],
        ]);
        Sanctum::actingAs($user);

        // Attempt to smuggle an arbitrary Project value alongside a real
        // selection — only whitelisted keys are ever read; the endpoint has
        // no parameter that accepts a raw Project value at all.
        $this->postJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/apply-project-suggestions", [
            'suggestions' => ['start_date'],
            'name' => 'Hacked Name', 'description' => 'Hacked description', 'client_id' => 999,
        ])->assertStatus(200);

        $fresh = $project->fresh();
        $this->assertNotEquals('Hacked Name', $fresh->name);
        $this->assertNull($fresh->description);
        $this->assertNull($fresh->client_id);
        $this->assertEquals('2026-09-01', $fresh->start_date->toDateString());
    }

    public function test_contract_and_confirmed_data_remain_unchanged_after_apply(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user, ['contract_sum' => 650000, 'commencement_date' => '2026-09-01']);
        $confirmedData = $this->confirmedV2();
        $analysis = $this->makeAnalysis($project, $contract, ['status' => 'confirmed', 'confirmed_data_json' => $confirmedData]);

        $originalContractSum = $contract->contract_sum;
        $originalContractUpdatedAt = $contract->updated_at;

        Sanctum::actingAs($user);
        $this->postJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/apply-project-suggestions", [
            'suggestions' => ['contract_value_currency', 'start_date'],
        ])->assertStatus(200);

        $freshContract = $contract->fresh();
        $freshAnalysis = $analysis->fresh();
        $this->assertEquals((float) $originalContractSum, (float) $freshContract->contract_sum);
        $this->assertEquals($originalContractUpdatedAt->toDateTimeString(), $freshContract->updated_at->toDateTimeString());
        $this->assertEquals('confirmed', $freshAnalysis->status);
        $this->assertEquals($confirmedData, $freshAnalysis->confirmed_data_json);
    }

    public function test_no_ai_credit_ledger_entries_are_created_by_apply(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user, ['commencement_date' => '2026-09-01']);
        $analysis = $this->makeAnalysis($project, $contract, [
            'status' => 'confirmed',
            'confirmed_data_json' => ['contract_overview' => [], 'parties' => [], 'commercial' => [], 'dates' => ['commencement_date' => '2026-09-01']],
        ]);
        Sanctum::actingAs($user);

        $before = \Illuminate\Support\Facades\DB::table('ai_credit_ledger_entries')->count();

        $this->postJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/apply-project-suggestions", [
            'suggestions' => ['start_date'],
        ])->assertStatus(200);

        $after = \Illuminate\Support\Facades\DB::table('ai_credit_ledger_entries')->count();
        $this->assertEquals($before, $after);
    }

    public function test_current_project_query_reflects_applied_values(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user, ['commencement_date' => '2026-09-01']);
        $analysis = $this->makeAnalysis($project, $contract, [
            'status' => 'confirmed',
            'confirmed_data_json' => ['contract_overview' => [], 'parties' => [], 'commercial' => [], 'dates' => ['commencement_date' => '2026-09-01']],
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/apply-project-suggestions", [
            'suggestions' => ['start_date'],
        ])->assertStatus(200);

        $response = $this->getJson("/api/projects/{$project->id}");
        $response->assertStatus(200);
        $this->assertStringStartsWith('2026-09-01', $response->json('start_date'));
    }

    public function test_nothing_selected_is_rejected_by_validation(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);
        $analysis = $this->makeAnalysis($project, $contract, ['status' => 'confirmed', 'confirmed_data_json' => $this->confirmedV2()]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/apply-project-suggestions", [
            'suggestions' => [],
        ]);

        $response->assertStatus(422);
    }

    // ── Money / currency safety ──────────────────────────────────────────────

    public function test_contract_value_and_currency_apply_together(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user, ['contract_sum' => 650000]);
        $analysis = $this->makeAnalysis($project, $contract, [
            'status' => 'confirmed',
            'confirmed_data_json' => ['contract_overview' => ['currency' => 'USD'], 'parties' => [], 'commercial' => ['currency' => 'USD'], 'dates' => []],
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/apply-project-suggestions", [
            'suggestions' => ['contract_value_currency'],
        ])->assertStatus(200);

        $fresh = $project->fresh();
        $this->assertEquals(650000.0, (float) $fresh->contract_value);
        $this->assertEquals('USD', $fresh->currency);
    }

    public function test_missing_confirmed_currency_prevents_money_suggestion_even_with_contract_sum(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        // Contract.currency defaults to 'AUD' at the DB level (never
        // touched) — the service must not treat that as a confirmed value.
        // (The in-memory $contract from create() won't reflect the DB
        // default until refreshed — same documented Eloquent quirk as
        // Project's own `country` default — so this sanity-checks the real
        // stored row, not the in-memory object.)
        $contract = $this->makeContract($project, $user, ['contract_sum' => 650000]);
        $this->assertEquals('AUD', $contract->refresh()->currency);
        $analysis = $this->makeAnalysis($project, $contract, [
            'status' => 'confirmed',
            'confirmed_data_json' => ['contract_overview' => [], 'parties' => [], 'commercial' => [], 'dates' => []], // no currency anywhere confirmed
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/project-suggestions");

        $money = collect($response->json('suggestions'))->firstWhere('key', 'contract_value_currency');
        $this->assertNull($money);
    }

    public function test_existing_different_project_currency_is_shown_as_differing_not_overwritten_until_applied(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user, ['contract_value' => 500000, 'currency' => 'GBP']);
        $contract = $this->makeContract($project, $user, ['contract_sum' => 650000]);
        $analysis = $this->makeAnalysis($project, $contract, [
            'status' => 'confirmed',
            'confirmed_data_json' => ['contract_overview' => ['currency' => 'USD'], 'parties' => [], 'commercial' => ['currency' => 'USD'], 'dates' => []],
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/project-suggestions");

        $money = collect($response->json('suggestions'))->firstWhere('key', 'contract_value_currency');
        $this->assertFalse($money['already_matches']);
        $this->assertFalse($money['default_selected']);
        $this->assertEquals(500000.0, $money['current']['value']);
        $this->assertEquals('GBP', $money['current']['currency']);
        $this->assertEquals(650000.0, $money['suggested']['value']);
        $this->assertEquals('USD', $money['suggested']['currency']);

        // Still GBP/500000 until explicitly applied.
        $this->assertEquals('GBP', $project->fresh()->currency);
    }

    /**
     * Post-approval verification — the "current" Project currency used for
     * comparison must be the effective resolved currency
     * (Project → Organization → platform, via Project::$resolved_currency /
     * CurrencyService::resolveCode()), not the raw nullable
     * projects.currency column. A Project with no explicit currency
     * override must not be treated as differing from a confirmed Contract
     * currency that matches its Organization's own configured currency.
     */
    public function test_inherited_organization_currency_matching_confirmed_currency_already_matches(): void
    {
        $org = $this->makeOrg();
        $org->update(['currency' => 'USD']); // explicit Organization default — no Project-level override
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user, ['contract_value' => 500000]); // currency left null
        $this->assertNull($project->currency);
        $contract = $this->makeContract($project, $user, ['contract_sum' => 500000]);
        $analysis = $this->makeAnalysis($project, $contract, [
            'status' => 'confirmed',
            'confirmed_data_json' => ['contract_overview' => ['currency' => 'USD'], 'parties' => [], 'commercial' => ['currency' => 'USD'], 'dates' => []],
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/project-suggestions");

        $money = collect($response->json('suggestions'))->firstWhere('key', 'contract_value_currency');
        $this->assertEquals(500000.0, $money['current']['value']);
        $this->assertEquals('USD', $money['current']['currency']); // resolved from the Organization, not the null column
        $this->assertEquals(500000.0, $money['suggested']['value']);
        $this->assertEquals('USD', $money['suggested']['currency']);
        $this->assertTrue($money['already_matches']);
        $this->assertFalse($money['default_selected']); // never preselected once it already matches
    }

    /** Same inherited-currency setup, but the Organization's own configured
     *  currency genuinely differs from the confirmed Contract's — this must
     *  still show as differing (never silently treated as a match, and
     *  never FX-converted), sourced from the Organization, not a guess. */
    public function test_inherited_organization_currency_differing_from_confirmed_currency_is_shown_as_differing(): void
    {
        $org = $this->makeOrg();
        $org->update(['currency' => 'GBP']);
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user, ['contract_value' => 500000]); // currency left null
        $contract = $this->makeContract($project, $user, ['contract_sum' => 500000]);
        $analysis = $this->makeAnalysis($project, $contract, [
            'status' => 'confirmed',
            'confirmed_data_json' => ['contract_overview' => ['currency' => 'USD'], 'parties' => [], 'commercial' => ['currency' => 'USD'], 'dates' => []],
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/project-suggestions");

        $money = collect($response->json('suggestions'))->firstWhere('key', 'contract_value_currency');
        $this->assertEquals(500000.0, $money['current']['value']);
        $this->assertEquals('GBP', $money['current']['currency']); // resolved from the Organization
        $this->assertEquals(500000.0, $money['suggested']['value']);
        $this->assertEquals('USD', $money['suggested']['currency']);
        $this->assertFalse($money['already_matches']); // same amount, but a genuinely different currency
        $this->assertFalse($money['default_selected']);

        // No FX conversion, no silent write — still null/inherited until applied.
        $this->assertNull($project->fresh()->currency);
    }

    // ── Dates ─────────────────────────────────────────────────────────────────

    public function test_commencement_maps_to_start_date_and_completion_to_end_date(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user, ['commencement_date' => '2026-09-01', 'completion_date' => '2027-03-01']);
        $analysis = $this->makeAnalysis($project, $contract, ['status' => 'confirmed', 'confirmed_data_json' => $this->confirmedV2()]);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/project-suggestions");

        $start = collect($response->json('suggestions'))->firstWhere('key', 'start_date');
        $end   = collect($response->json('suggestions'))->firstWhere('key', 'end_date');
        $this->assertEquals('2026-09-01', $start['suggested']['value']);
        $this->assertEquals('2027-03-01', $end['suggested']['value']);
    }

    public function test_practical_completion_date_is_never_a_suggestion_key(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user, ['completion_date' => '2027-03-01']);
        $analysis = $this->makeAnalysis($project, $contract, ['status' => 'confirmed', 'confirmed_data_json' => $this->confirmedV2()]);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/project-suggestions");

        $keys = collect($response->json('suggestions'))->pluck('key')->all();
        $this->assertNotContains('practical_completion_date', $keys);
    }

    public function test_invalid_resulting_date_range_is_rejected(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        // Project already has an end_date earlier than what start_date would become.
        $project = $this->makeProject($org, $user, ['end_date' => '2026-01-01']);
        $contract = $this->makeContract($project, $user, ['commencement_date' => '2026-09-01']);
        $analysis = $this->makeAnalysis($project, $contract, [
            'status' => 'confirmed',
            'confirmed_data_json' => ['contract_overview' => [], 'parties' => [], 'commercial' => [], 'dates' => ['commencement_date' => '2026-09-01']],
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/apply-project-suggestions", [
            'suggestions' => ['start_date'],
        ]);

        $response->assertStatus(422);
        $this->assertNull($project->fresh()->start_date); // nothing applied
    }

    public function test_applying_one_date_preserves_the_other_when_valid(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user, ['end_date' => '2027-12-01']);
        $contract = $this->makeContract($project, $user, ['commencement_date' => '2026-09-01']);
        $analysis = $this->makeAnalysis($project, $contract, [
            'status' => 'confirmed',
            'confirmed_data_json' => ['contract_overview' => [], 'parties' => [], 'commercial' => [], 'dates' => ['commencement_date' => '2026-09-01']],
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/apply-project-suggestions", [
            'suggestions' => ['start_date'],
        ])->assertStatus(200);

        $fresh = $project->fresh();
        $this->assertEquals('2026-09-01', $fresh->start_date->toDateString());
        $this->assertEquals('2027-12-01', $fresh->end_date->toDateString());
    }

    // ── Project Role identity rules ─────────────────────────────────────────

    public function test_exact_normalized_main_contractor_identity_suggests_role_when_null(): void
    {
        $org = $this->makeOrg('Concrete Specialist Ltd');
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user); // organization_role null
        $contract = $this->makeContract($project, $user);
        $analysis = $this->makeAnalysis($project, $contract, [
            'status' => 'confirmed',
            'confirmed_data_json' => $this->confirmedV2(['parties' => ['main_contractor' => ['name' => 'CONCRETE SPECIALIST LTD']]]),
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/project-suggestions");

        $role = collect($response->json('suggestions'))->firstWhere('key', 'organization_role');
        $this->assertNotNull($role);
        $this->assertEquals('main_contractor', $role['suggested']['value']);
        $this->assertFalse($role['default_selected']); // role decisions are never preselected
    }

    public function test_exact_normalized_employer_identity_suggests_role_when_null(): void
    {
        $org = $this->makeOrg('Property Holdings Ltd');
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user, ['employer_name' => 'Property Holdings Ltd']);
        $analysis = $this->makeAnalysis($project, $contract, [
            'status' => 'confirmed',
            'confirmed_data_json' => $this->confirmedV2(['parties' => ['main_contractor' => ['name' => 'Some Other Contractor Ltd']]]),
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/project-suggestions");

        $role = collect($response->json('suggestions'))->firstWhere('key', 'organization_role');
        $this->assertEquals('employer', $role['suggested']['value']);
    }

    public function test_is_subcontract_alone_never_suggests_subcontractor(): void
    {
        $org = $this->makeOrg('Concrete Specialist Ltd');
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user, ['type' => 'subcontract']);
        $analysis = $this->makeAnalysis($project, $contract, [
            'status' => 'confirmed',
            'confirmed_data_json' => $this->confirmedV2([
                'contract_overview' => ['is_subcontract' => true],
                // No dedicated subcontractor party identity at all, and the
                // Organization's name matches nothing.
                'parties' => ['main_contractor' => ['name' => 'Some Other Contractor Ltd'], 'employer' => ['name' => 'Property Holdings Ltd']],
            ]),
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/project-suggestions");

        $role = collect($response->json('suggestions'))->firstWhere('key', 'organization_role');
        $this->assertNull($role);
    }

    public function test_dedicated_subcontractor_identity_suggests_subcontractor(): void
    {
        $org = $this->makeOrg('Concrete Specialist Ltd');
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user, ['type' => 'subcontract']);
        $analysis = $this->makeAnalysis($project, $contract, [
            'status' => 'confirmed',
            'confirmed_data_json' => $this->confirmedV2([
                'contract_overview' => ['is_subcontract' => true],
                'parties' => [
                    'main_contractor' => ['name' => 'BuildCo Ltd'],
                    'subcontractor'   => ['name' => 'Concrete Specialist Ltd'],
                ],
            ]),
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/project-suggestions");

        $role = collect($response->json('suggestions'))->firstWhere('key', 'organization_role');
        $this->assertEquals('subcontractor', $role['suggested']['value']);
    }

    public function test_existing_project_role_suppresses_actionable_role_suggestion(): void
    {
        $org = $this->makeOrg('Concrete Specialist Ltd');
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user, ['organization_role' => 'subcontractor']);
        $contract = $this->makeContract($project, $user, ['type' => 'subcontract']);
        $analysis = $this->makeAnalysis($project, $contract, [
            'status' => 'confirmed',
            'confirmed_data_json' => $this->confirmedV2(['parties' => ['main_contractor' => ['name' => 'Concrete Specialist Ltd']]]),
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/project-suggestions");

        $role = collect($response->json('suggestions'))->firstWhere('key', 'organization_role');
        $this->assertNull($role);
        $this->assertEquals('subcontractor', $project->fresh()->organization_role); // untouched
    }

    public function test_ambiguous_identity_produces_no_role_suggestion(): void
    {
        $org = $this->makeOrg('Concrete Specialist Ltd');
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);
        $analysis = $this->makeAnalysis($project, $contract, [
            'status' => 'confirmed',
            // Same normalized name appears as BOTH main_contractor and employer.
            'confirmed_data_json' => $this->confirmedV2([
                'parties' => [
                    'main_contractor' => ['name' => 'Concrete Specialist Ltd'],
                    'employer'        => ['name' => '  CONCRETE   SPECIALIST LTD  '],
                ],
            ]),
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/project-suggestions");

        $role = collect($response->json('suggestions'))->firstWhere('key', 'organization_role');
        $this->assertNull($role);
    }

    public function test_boss_scenario_main_contractor_project_with_confirmed_subcontract_stays_main_contractor(): void
    {
        $org = $this->makeOrg('Concrete Specialist Ltd');
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user, ['organization_role' => 'main_contractor']);
        $contract = $this->makeContract($project, $user, ['type' => 'subcontract', 'contract_sum' => 100000]);
        $analysis = $this->makeAnalysis($project, $contract, [
            'status' => 'confirmed',
            'confirmed_data_json' => $this->confirmedV2([
                'contract_overview' => ['is_subcontract' => true],
                'parties' => ['main_contractor' => ['name' => 'Concrete Specialist Ltd'], 'subcontractor' => ['name' => 'Steel Fixers Ltd']],
                'commercial' => ['contract_sum' => '100000', 'currency' => 'GBP'],
            ]),
        ]);
        Sanctum::actingAs($user);

        // Applying whatever else is available must never touch organization_role.
        $this->postJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/apply-project-suggestions", [
            'suggestions' => ['contract_value_currency'],
        ])->assertStatus(200);

        $fresh = $project->fresh();
        $this->assertEquals('main_contractor', $fresh->organization_role);
        $this->assertEquals('subcontract', $contract->fresh()->type);
    }

    // ── Tenant isolation ──────────────────────────────────────────────────────

    public function test_org_a_cannot_preview_suggestions_for_org_b_project(): void
    {
        $orgB = $this->makeOrg('Org B Ltd');
        $userB = $this->makeUser($orgB);
        $projectB = $this->makeProject($orgB, $userB);
        $contractB = $this->makeContract($projectB, $userB);
        $analysisB = $this->makeAnalysis($projectB, $contractB, ['status' => 'confirmed', 'confirmed_data_json' => $this->confirmedV2()]);

        $orgA = $this->makeOrg('Org A Ltd');
        $userA = $this->makeUser($orgA);
        Sanctum::actingAs($userA);

        $response = $this->getJson("/api/projects/{$projectB->id}/contracts/{$contractB->id}/analyses/{$analysisB->id}/project-suggestions");

        $response->assertStatus(403);
    }

    public function test_project_a_cannot_use_project_b_contract(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $projectA = $this->makeProject($org, $user, ['name' => 'Project A']);
        $projectB = $this->makeProject($org, $user, ['name' => 'Project B']);
        $contractB = $this->makeContract($projectB, $user);
        $analysisB = $this->makeAnalysis($projectB, $contractB, ['status' => 'confirmed', 'confirmed_data_json' => $this->confirmedV2()]);
        Sanctum::actingAs($user);

        // Same organisation, but Contract B genuinely belongs to Project B, not A.
        $response = $this->getJson("/api/projects/{$projectA->id}/contracts/{$contractB->id}/analyses/{$analysisB->id}/project-suggestions");

        $response->assertStatus(404);
    }

    public function test_org_a_cannot_apply_org_b_analysis_suggestions(): void
    {
        $orgB = $this->makeOrg('Org B Ltd');
        $userB = $this->makeUser($orgB);
        $projectB = $this->makeProject($orgB, $userB);
        $contractB = $this->makeContract($projectB, $userB, ['commencement_date' => '2026-09-01']);
        $analysisB = $this->makeAnalysis($projectB, $contractB, [
            'status' => 'confirmed',
            'confirmed_data_json' => ['contract_overview' => [], 'parties' => [], 'commercial' => [], 'dates' => ['commencement_date' => '2026-09-01']],
        ]);

        $orgA = $this->makeOrg('Org A Ltd');
        $userA = $this->makeUser($orgA);
        Sanctum::actingAs($userA);

        $response = $this->postJson("/api/projects/{$projectB->id}/contracts/{$contractB->id}/analyses/{$analysisB->id}/apply-project-suggestions", [
            'suggestions' => ['start_date'],
        ]);

        $response->assertStatus(403);
        $this->assertNull($projectB->fresh()->start_date);
    }

    public function test_super_admin_can_apply_suggestions_for_a_customer_project(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user, ['commencement_date' => '2026-09-01']);
        $analysis = $this->makeAnalysis($project, $contract, [
            'status' => 'confirmed',
            'confirmed_data_json' => ['contract_overview' => [], 'parties' => [], 'commercial' => [], 'dates' => ['commencement_date' => '2026-09-01']],
        ]);

        $admin = User::factory()->create(['organization_id' => null]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/projects/{$project->id}/contracts/{$contract->id}/analyses/{$analysis->id}/apply-project-suggestions", [
            'suggestions' => ['start_date'],
        ]);

        $response->assertStatus(200);
        $this->assertEquals('2026-09-01', $project->fresh()->start_date->toDateString());
    }
}

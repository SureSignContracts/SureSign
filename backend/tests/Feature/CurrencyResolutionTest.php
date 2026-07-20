<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SuresignSetting;
use App\Models\User;
use App\Services\ContractIntelligenceSyncService;
use App\Services\CurrencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Currency inheritance audit — covers the full project -> organisation ->
 * platform -> GBP hierarchy, the explicit-override-preservation rules, the
 * AI-analysis non-override guarantee, and the exact symbol table required.
 *
 * Root cause of the pre-audit "AUD on every project" bug (documented in
 * production-readiness-audit.md's sibling report / project-context.md):
 * the `projects.currency` column was created with `default('AUD')`, and no
 * controller ever accepted a `currency` field on create/update, so every
 * project ever created got 'AUD' purely from the column default. Fixed by
 * a schema migration (2026_07_20_000002) plus ProjectController now always
 * passing an explicit value (including null) to Project::create().
 */
class CurrencyResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(array $overrides = []): Organization
    {
        static $n = 0;
        $n++;

        return Organization::create(array_merge([
            'name' => "Org {$n}", 'slug' => "org-{$n}", 'timezone' => 'Europe/London',
        ], $overrides));
    }

    private function makeProject(Organization $org, User $user, array $overrides = []): Project
    {
        static $n = 0;
        $n++;

        return Project::create(array_merge([
            'organization_id' => $org->id, 'created_by' => $user->id,
            'name' => "Project {$n}", 'status' => 'active',
        ], $overrides));
    }

    // ── Hierarchy resolution ─────────────────────────────────────────────

    public function test_explicit_project_currency_wins_over_everything(): void
    {
        SuresignSetting::instance()->update(['currency' => 'EUR']);
        $org = $this->makeOrg(['currency' => 'USD']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user, ['currency' => 'AUD']);

        $this->assertSame('AUD', CurrencyService::resolveCode($project));
    }

    public function test_project_without_override_inherits_organisation_default(): void
    {
        SuresignSetting::instance()->update(['currency' => 'EUR']);
        $org = $this->makeOrg(['currency' => 'USD']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user, ['currency' => null]);

        $this->assertSame('USD', CurrencyService::resolveCode($project));
    }

    public function test_project_and_org_without_override_inherit_platform_default(): void
    {
        SuresignSetting::instance()->update(['currency' => 'EUR']);
        $org = $this->makeOrg(['currency' => null]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user, ['currency' => null]);

        $this->assertSame('EUR', CurrencyService::resolveCode($project));
    }

    public function test_gbp_is_the_final_fallback_when_nothing_is_configured(): void
    {
        // SuresignSetting::instance() defaults 'currency' to GBP on first
        // creation (see SuresignSetting::instance()) — never leave it unset.
        $org = $this->makeOrg(['currency' => null]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user, ['currency' => null]);

        $this->assertSame('GBP', CurrencyService::resolveCode($project));
    }

    public function test_never_infers_currency_from_project_country(): void
    {
        $org = $this->makeOrg(['currency' => null]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        // Country strongly suggests AUD, but nothing configured it explicitly.
        $project = $this->makeProject($org, $user, ['currency' => null, 'country' => 'Australia', 'city' => 'Sydney']);

        $this->assertSame('GBP', CurrencyService::resolveCode($project));
    }

    // ── Preservation rules ───────────────────────────────────────────────

    public function test_existing_project_with_explicit_currency_is_not_affected_by_organisation_default_change(): void
    {
        $org = $this->makeOrg(['currency' => 'USD']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user, ['currency' => 'AUD']);

        $org->update(['currency' => 'EUR']);
        $project->refresh();

        $this->assertSame('AUD', CurrencyService::resolveCode($project));
    }

    public function test_organisation_default_change_dynamically_affects_projects_without_an_override(): void
    {
        $org = $this->makeOrg(['currency' => 'USD']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user, ['currency' => null]);

        $this->assertSame('USD', CurrencyService::resolveCode($project));

        $org->update(['currency' => 'EUR']);
        $project->refresh();

        $this->assertSame('EUR', CurrencyService::resolveCode($project));
    }

    public function test_project_update_endpoint_omitting_currency_preserves_existing_override(): void
    {
        $org = $this->makeOrg();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        $project = $this->makeProject($org, $user, ['currency' => 'AUD']);

        Sanctum::actingAs($user);
        $response = $this->putJson("/api/projects/{$project->id}", ['name' => 'Renamed Project']);

        $response->assertOk();
        $this->assertSame('AUD', $project->fresh()->currency);
    }

    public function test_project_update_endpoint_can_explicitly_clear_override_to_inherit(): void
    {
        $org = $this->makeOrg(['currency' => 'EUR']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        $project = $this->makeProject($org, $user, ['currency' => 'AUD']);

        Sanctum::actingAs($user);
        $response = $this->putJson("/api/projects/{$project->id}", ['currency' => null]);

        $response->assertOk();
        $project->refresh();
        $this->assertNull($project->currency);
        $this->assertSame('EUR', CurrencyService::resolveCode($project));
    }

    public function test_project_create_endpoint_without_currency_leaves_it_null_not_aud(): void
    {
        $org = $this->makeOrg();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/projects', ['name' => 'New Project']);

        $response->assertCreated();
        $project = Project::findOrFail($response->json('id'));
        $this->assertNull($project->currency, 'currency must default to null (inherit), never the AUD column default');
    }

    public function test_project_create_endpoint_accepts_explicit_currency_override(): void
    {
        $org = $this->makeOrg(['currency' => 'GBP']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/projects', ['name' => 'New Project', 'currency' => 'usd']);

        $response->assertCreated();
        $project = Project::findOrFail($response->json('id'));
        $this->assertSame('USD', $project->currency);
    }

    // ── AI analysis must never silently overwrite the authoritative currency ──

    public function test_ai_extracted_currency_is_rejected_when_it_does_not_match_the_resolved_project_currency(): void
    {
        $org = $this->makeOrg();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user, ['currency' => 'GBP']);

        // Contract PDF text mentions AUD somewhere, but the project is GBP.
        $this->assertNull(CurrencyService::validateAiExtractedCode('AUD', $project));
    }

    public function test_ai_extracted_currency_is_accepted_only_when_it_matches_the_resolved_project_currency(): void
    {
        $org = $this->makeOrg(['currency' => 'AUD']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user, ['currency' => null]); // inherits AUD from org

        $this->assertSame('AUD', CurrencyService::validateAiExtractedCode('aud', $project));
        $this->assertNull(CurrencyService::validateAiExtractedCode('USD', $project));
    }

    public function test_contract_intelligence_sync_never_writes_currency_to_the_project_record(): void
    {
        $org = $this->makeOrg();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user, ['currency' => 'GBP']);
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'type' => 'main_contract', 'title' => 'Main Contract', 'currency' => '',
        ]);
        $confirmedData = [
            'contract_overview' => [],
            'commercial' => ['currency' => 'AUD'],
        ];
        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'confirmed',
            'confirmed_data_json' => $confirmedData,
        ]);

        (new ContractIntelligenceSyncService())->sync($analysis, $confirmedData, false);

        $project->refresh();
        $this->assertSame('GBP', $project->currency, 'AI analysis must never write to Project::currency directly');
    }

    // ── Symbol table (backend source of truth for frontend lib/currency.ts) ──

    public function test_currency_symbols_match_the_required_table(): void
    {
        $this->assertSame('£',   CurrencyService::codeToSymbol('GBP'));
        $this->assertSame('$',   CurrencyService::codeToSymbol('USD'));
        $this->assertSame('€',   CurrencyService::codeToSymbol('EUR'));
        $this->assertSame('A$',  CurrencyService::codeToSymbol('AUD'));
        $this->assertSame('NZ$', CurrencyService::codeToSymbol('NZD'));
        $this->assertSame('C$',  CurrencyService::codeToSymbol('CAD'));
        $this->assertSame('S$',  CurrencyService::codeToSymbol('SGD'));
        $this->assertSame('¥',   CurrencyService::codeToSymbol('JPY'));
    }

    // ── Mixed-currency totals must never be combined ─────────────────────

    public function test_mixed_currency_projects_are_grouped_not_combined_in_the_dashboard(): void
    {
        $org = $this->makeOrg();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        $gbpProject = $this->makeProject($org, $user, ['currency' => 'GBP', 'contract_value' => 1000]);
        $usdProject = $this->makeProject($org, $user, ['currency' => 'USD', 'contract_value' => 2000]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/projects/portfolio');

        $response->assertOk();
        $currencies = collect($response->json('filters.currencies'));
        $this->assertTrue($currencies->contains('GBP'));
        $this->assertTrue($currencies->contains('USD'));

        $rows = collect($response->json('projects.data'));
        $this->assertSame('GBP', $rows->firstWhere('id', $gbpProject->id)['commercial']['currency']);
        $this->assertSame('USD', $rows->firstWhere('id', $usdProject->id)['commercial']['currency']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\FeatureAvailability;
use App\Models\FridayPack;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanEntitlement;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlementSnapshot;
use App\Models\User;
use App\Services\FridayPack\FridayPackGenerationService;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Entitlements\Feature;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Friday Pack Plan Entitlement Enforcement, Deployment B.
 *
 * Proves `feature.entitled:friday_packs` (App\Http\Middleware\EnsureFeatureIsEntitled)
 * is wired onto exactly the 18 mutation/workflow Friday Pack routes and
 * NOT onto the 9 read-only ones — an organisation that loses (or never
 * had) the Friday Packs entitlement must still be able to read its own
 * historical Friday Pack records; only creating, changing, or progressing
 * one requires the entitlement. Mirrors
 * OrganisationUrlBrandingCustomerSelfServiceTest's exact subscription/
 * snapshot construction (the real `SubscriptionLifecycleService::activate()`
 * shape, never hand-waved), and FridayPackTest's org/project conventions
 * (Europe/London timezone, since FridayPackPeriodResolver's Friday check
 * needs a real timezone to resolve against).
 */
class FridayPackEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private static int $orgCounter = 300;
    private static int $planCounter = 0;

    /** A known real Friday used throughout — 21 August 2026. */
    private const FRIDAY = '2026-08-21';

    private function makeOrg(string $suffix): Organization
    {
        return Organization::create([
            'name' => "Org {$suffix}", 'slug' => "org-{$suffix}", 'timezone' => 'Europe/London', 'is_active' => true,
        ]);
    }

    private function makeUser(string $role, ?Organization $org = null): User
    {
        $org ??= $this->makeOrg((string) (++self::$orgCounter));
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));

        return $user;
    }

    private function makeProject(Organization $org, User $creator): Project
    {
        return Project::create([
            'organization_id' => $org->id,
            'created_by' => $creator->id,
            'name' => 'Project ' . $org->id,
        ]);
    }

    private function makePlan(string $code): PricingPlan
    {
        $n = ++self::$planCounter;

        return PricingPlan::create([
            'code' => $code, 'slug' => $code . '-fpe-' . $n, 'name' => ucfirst($code), 'order' => $n,
            'currency' => 'GBP', 'status' => 'active',
        ]);
    }

    /**
     * Creates an ACTIVE subscription for $organization on a fresh plan
     * with Friday Packs entitlement set to $fridayPacksEntitled, with a
     * real activation snapshot — mirrors
     * OrganisationUrlBrandingCustomerSelfServiceTest::makeActiveSubscriptionWithSnapshot()
     * exactly, so this exercises FeatureGate's real snapshot-first
     * resolution path rather than a shortcut.
     */
    private function subscribeOrg(Organization $organization, string $planCode, bool $fridayPacksEntitled): void
    {
        $plan = $this->makePlan($planCode);
        PricingPlanEntitlement::create([
            'pricing_plan_id' => $plan->id,
            'feature_key' => Feature::FRIDAY_PACKS,
            'is_applicable' => true,
            'is_unlimited' => false,
            'value' => $fridayPacksEntitled,
        ]);

        $subscription = Subscription::create([
            'organization_id' => $organization->id,
            'provider' => 'stripe',
            'livemode' => false,
            'internal_reference' => 'SUB-FPE-' . random_int(1, 99999999),
            'status' => SubscriptionStatus::ACTIVE,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'unit_amount' => 2999,
            'plan_code_snapshot' => $planCode,
            'starts_at' => now()->subDay(),
        ]);

        SubscriptionEntitlementSnapshot::create([
            'subscription_id' => $subscription->id,
            'organization_id' => $organization->id,
            'pricing_plan_id' => null,
            'plan_code_snapshot' => $planCode,
            'entitlements_json' => [
                Feature::FRIDAY_PACKS => [
                    'value_type' => 'boolean', 'value' => $fridayPacksEntitled, 'is_unlimited' => false, 'unit' => null, 'source' => 'plan_default',
                ],
            ],
            'effective_from' => CarbonImmutable::now()->subHour(),
            'lifecycle_reason' => 'activation',
            'source_transition' => 'subscription.activated',
        ]);
    }

    /** Seeds a historical Friday Pack directly via the generation service — bypasses HTTP/middleware entirely, exactly like FridayPackReadinessTest/FridayPackPdfTest already do. */
    private function seedHistoricalPack(Project $project, User $editor, string $weekEnding = self::FRIDAY): FridayPack
    {
        return app(FridayPackGenerationService::class)->generate($project, $weekEnding, $editor);
    }

    // ── Essential: mutations blocked ────────────────────────────────────

    public function test_essential_cannot_create_a_friday_pack(): void
    {
        $org = $this->makeOrg('e1');
        $this->subscribeOrg($org, 'essential-e1', fridayPacksEntitled: false);
        $editor = $this->makeUser('Client', $org);
        $project = $this->makeProject($org, $editor);

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/friday-packs", ['week_ending' => self::FRIDAY]);

        $response->assertStatus(403)->assertJsonPath('code', 'feature_not_entitled');
        $this->assertSame(0, FridayPack::where('project_id', $project->id)->count());
    }

    public function test_essential_cannot_update_an_existing_friday_pack(): void
    {
        $org = $this->makeOrg('e2');
        $this->subscribeOrg($org, 'essential-e2', fridayPacksEntitled: false);
        $editor = $this->makeUser('Client', $org);
        $project = $this->makeProject($org, $editor);
        $pack = $this->seedHistoricalPack($project, $editor);

        Sanctum::actingAs($editor);
        $this->putJson("/api/projects/{$project->id}/friday-packs/{$pack->id}", ['weekly_summary' => 'x'])
            ->assertStatus(403)->assertJsonPath('code', 'feature_not_entitled');
    }

    public function test_essential_cannot_call_a_protected_sub_resource_mutation_directly(): void
    {
        $org = $this->makeOrg('e3');
        $this->subscribeOrg($org, 'essential-e3', fridayPacksEntitled: false);
        $editor = $this->makeUser('Client', $org);
        $project = $this->makeProject($org, $editor);
        $pack = $this->seedHistoricalPack($project, $editor);

        Sanctum::actingAs($editor);
        // Representative protected sub-resource mutation: photo selections store.
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", [
            'file_upload_id' => 999999,
        ])->assertStatus(403)->assertJsonPath('code', 'feature_not_entitled');
    }

    public function test_essential_cannot_progress_lifecycle_actions(): void
    {
        $org = $this->makeOrg('e4');
        $this->subscribeOrg($org, 'essential-e4', fridayPacksEntitled: false);
        $editor = $this->makeUser('Client', $org);
        $project = $this->makeProject($org, $editor);
        $pack = $this->seedHistoricalPack($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/submit-for-review", [])
            ->assertStatus(403)->assertJsonPath('code', 'feature_not_entitled');
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/regenerate", ['week_ending' => self::FRIDAY])
            ->assertStatus(403)->assertJsonPath('code', 'feature_not_entitled');
    }

    // ── Professional / Enterprise: mutations allowed ────────────────────

    public function test_professional_can_perform_mutations(): void
    {
        $org = $this->makeOrg('p1');
        $this->subscribeOrg($org, 'professional-p1', fridayPacksEntitled: true);
        $editor = $this->makeUser('Client', $org);
        $project = $this->makeProject($org, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs", ['week_ending' => self::FRIDAY])
            ->assertStatus(201);
        $this->assertSame(1, FridayPack::where('project_id', $project->id)->count());
    }

    public function test_enterprise_can_perform_mutations(): void
    {
        $org = $this->makeOrg('n1');
        $this->subscribeOrg($org, 'enterprise-n1', fridayPacksEntitled: true);
        $editor = $this->makeUser('Client', $org);
        $project = $this->makeProject($org, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs", ['week_ending' => self::FRIDAY])
            ->assertStatus(201);
        $this->assertSame(1, FridayPack::where('project_id', $project->id)->count());
    }

    // ── Historical read access survives entitlement loss ────────────────

    public function test_downgraded_organisation_can_still_list_historical_friday_packs(): void
    {
        $org = $this->makeOrg('d1');
        // Created while entitled...
        $this->subscribeOrg($org, 'professional-d1', fridayPacksEntitled: true);
        $editor = $this->makeUser('Client', $org);
        $project = $this->makeProject($org, $editor);
        $this->seedHistoricalPack($project, $editor);

        // ...then downgraded — a fresh snapshot supersedes the old one,
        // exactly like a real downgrade/plan-change commercial event would.
        $this->subscribeOrg($org, 'essential-d1', fridayPacksEntitled: false);

        Sanctum::actingAs($editor);
        $this->getJson("/api/projects/{$project->id}/friday-packs")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_downgraded_organisation_can_still_view_historical_friday_pack(): void
    {
        $org = $this->makeOrg('d2');
        $this->subscribeOrg($org, 'professional-d2', fridayPacksEntitled: true);
        $editor = $this->makeUser('Client', $org);
        $project = $this->makeProject($org, $editor);
        $pack = $this->seedHistoricalPack($project, $editor);

        $this->subscribeOrg($org, 'essential-d2', fridayPacksEntitled: false);

        Sanctum::actingAs($editor);
        $this->getJson("/api/projects/{$project->id}/friday-packs/{$pack->id}")
            ->assertStatus(200)
            ->assertJsonPath('id', $pack->id);
    }

    // ── Tenant isolation on read surfaces ────────────────────────────────

    public function test_read_access_does_not_bypass_tenant_isolation(): void
    {
        $orgA = $this->makeOrg('t1');
        $this->subscribeOrg($orgA, 'professional-t1', fridayPacksEntitled: true);
        $editorA = $this->makeUser('Client', $orgA);
        $projectA = $this->makeProject($orgA, $editorA);
        $packA = $this->seedHistoricalPack($projectA, $editorA);

        $orgB = $this->makeOrg('t2');
        $this->subscribeOrg($orgB, 'essential-t2', fridayPacksEntitled: false);
        $editorB = $this->makeUser('Client', $orgB);

        Sanctum::actingAs($editorB);
        // Wrong organisation entirely — controller-level authorize() 403s
        // before this middleware is ever reached.
        $this->getJson("/api/projects/{$projectA->id}/friday-packs/{$packA->id}")->assertStatus(403);
    }

    public function test_essential_cannot_read_another_organisations_friday_pack(): void
    {
        $orgA = $this->makeOrg('t3');
        $this->subscribeOrg($orgA, 'professional-t3', fridayPacksEntitled: true);
        $editorA = $this->makeUser('Client', $orgA);
        $projectA = $this->makeProject($orgA, $editorA);
        $packA = $this->seedHistoricalPack($projectA, $editorA);

        $orgB = $this->makeOrg('t4');
        $this->subscribeOrg($orgB, 'essential-t4', fridayPacksEntitled: false);
        $editorB = $this->makeUser('Client', $orgB);
        $projectB = $this->makeProject($orgB, $editorB);

        Sanctum::actingAs($editorB);
        // Right shape of request (their own project prefix), wrong pack —
        // FridayPackController::authorizeProjectFridayPack() checks the
        // pack's own organization first (403, cross-tenant), independent
        // of project_id matching.
        $this->getJson("/api/projects/{$projectB->id}/friday-packs/{$packA->id}")->assertStatus(403);
    }

    // ── Independence from other systems ──────────────────────────────────

    public function test_toolbox_talks_remain_available_regardless_of_friday_pack_entitlement(): void
    {
        $org = $this->makeOrg('tt1');
        $this->subscribeOrg($org, 'essential-tt1', fridayPacksEntitled: false);
        $editor = $this->makeUser('Client', $org);
        $project = $this->makeProject($org, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/toolbox-talks", [
            'title' => 'Working at height',
            'talk_date' => self::FRIDAY,
            'attendee_count' => 5,
        ])->assertStatus(201);
    }

    public function test_feature_availability_remains_independently_enforceable(): void
    {
        $org = $this->makeOrg('fa1');
        // Fully entitled (Professional)...
        $this->subscribeOrg($org, 'professional-fa1', fridayPacksEntitled: true);
        $editor = $this->makeUser('Client', $org);
        $project = $this->makeProject($org, $editor);

        // ...but the module is platform-wide switched to Maintenance —
        // 'feature.available' still blocks regardless of commercial
        // entitlement, proving the two gates are genuinely independent.
        FeatureAvailability::create(['feature_key' => 'project.friday_packs', 'status' => 'maintenance']);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs", ['week_ending' => self::FRIDAY])
            ->assertStatus(503);
    }

    // ── Super Admin / Admin bypass ────────────────────────────────────────

    public function test_super_admin_bypasses_friday_pack_entitlement(): void
    {
        $org = $this->makeOrg('sa1');
        $this->subscribeOrg($org, 'essential-sa1', fridayPacksEntitled: false);
        $editor = $this->makeUser('Client', $org);
        $project = $this->makeProject($org, $editor);

        $superAdmin = $this->makeUser('Super Admin');
        Sanctum::actingAs($superAdmin);

        $this->postJson("/api/projects/{$project->id}/friday-packs", ['week_ending' => self::FRIDAY])
            ->assertStatus(201);
    }

    public function test_admin_bypasses_friday_pack_entitlement(): void
    {
        $org = $this->makeOrg('ad1');
        $this->subscribeOrg($org, 'essential-ad1', fridayPacksEntitled: false);
        $editor = $this->makeUser('Client', $org);
        $project = $this->makeProject($org, $editor);

        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);

        $this->postJson("/api/projects/{$project->id}/friday-packs", ['week_ending' => self::FRIDAY])
            ->assertStatus(201);
    }

    // ── Entitlement UX status endpoint (GET /friday-packs/entitlement) ──

    public function test_entitlement_status_reports_false_for_essential(): void
    {
        $org = $this->makeOrg('ux1');
        $this->subscribeOrg($org, 'essential-ux1', fridayPacksEntitled: false);
        $editor = $this->makeUser('Client', $org);
        $project = $this->makeProject($org, $editor);

        Sanctum::actingAs($editor);
        $this->getJson("/api/projects/{$project->id}/friday-packs/entitlement")
            ->assertStatus(200)
            ->assertJsonPath('entitled', false)
            ->assertJsonPath('is_platform_operator', false);
    }

    public function test_entitlement_status_reports_true_for_professional(): void
    {
        $org = $this->makeOrg('ux2');
        $this->subscribeOrg($org, 'professional-ux2', fridayPacksEntitled: true);
        $editor = $this->makeUser('Client', $org);
        $project = $this->makeProject($org, $editor);

        Sanctum::actingAs($editor);
        $this->getJson("/api/projects/{$project->id}/friday-packs/entitlement")
            ->assertStatus(200)
            ->assertJsonPath('entitled', true);
    }

    public function test_entitlement_status_reports_true_for_enterprise(): void
    {
        $org = $this->makeOrg('ux3');
        $this->subscribeOrg($org, 'enterprise-ux3', fridayPacksEntitled: true);
        $editor = $this->makeUser('Client', $org);
        $project = $this->makeProject($org, $editor);

        Sanctum::actingAs($editor);
        $this->getJson("/api/projects/{$project->id}/friday-packs/entitlement")
            ->assertStatus(200)
            ->assertJsonPath('entitled', true);
    }

    public function test_entitlement_status_reports_true_for_super_admin_regardless_of_plan(): void
    {
        $org = $this->makeOrg('ux4');
        $this->subscribeOrg($org, 'essential-ux4', fridayPacksEntitled: false);
        $editor = $this->makeUser('Client', $org);
        $project = $this->makeProject($org, $editor);

        $superAdmin = $this->makeUser('Super Admin');
        Sanctum::actingAs($superAdmin);

        $this->getJson("/api/projects/{$project->id}/friday-packs/entitlement")
            ->assertStatus(200)
            ->assertJsonPath('entitled', true)
            ->assertJsonPath('is_platform_operator', true);
    }

    public function test_entitlement_status_endpoint_never_blocks_a_mutation_itself(): void
    {
        // The status endpoint is a read — it must never gain a
        // feature.entitled/feature.available gate itself, or it could
        // never report "not entitled" in the first place.
        $org = $this->makeOrg('ux5');
        $this->subscribeOrg($org, 'essential-ux5', fridayPacksEntitled: false);
        $editor = $this->makeUser('Client', $org);
        $project = $this->makeProject($org, $editor);

        FeatureAvailability::create(['feature_key' => 'project.friday_packs', 'status' => 'maintenance']);

        Sanctum::actingAs($editor);
        $this->getJson("/api/projects/{$project->id}/friday-packs/entitlement")
            ->assertStatus(200)
            ->assertJsonPath('entitled', false);
    }
}

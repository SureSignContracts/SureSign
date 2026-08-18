<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\FeatureAvailability;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TradePackage;
use App\Models\User;
use App\Services\FeatureAvailability\FeatureAvailabilityService;
use App\Support\FeatureAvailability\FeatureAvailabilityRegistry;
use App\Support\FeatureAvailability\FeatureAvailabilityStatus;
use App\Support\FeatureAvailability\FeatureAvailabilityUnavailableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SureSign Feature Availability, Phase A — backend foundation test suite.
 *
 * Covers: registry/service resolution (fail-safe rules), the customer
 * status API, Super Admin management API authorization, audit logging,
 * cache invalidation, the middleware in isolation (never attached to a
 * real module route in this phase), tenant/data safety, and non-
 * interference with AI credits. Deliberately does NOT test module-route
 * enforcement — no real route has this middleware attached yet; that
 * belongs to a later enforcement-rollout phase.
 */
class FeatureAvailabilityTest extends TestCase
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

    // ── A. Registry / service ───────────────────────────────────────────

    public function test_known_feature_is_recognized_by_registry(): void
    {
        $this->assertTrue(FeatureAvailabilityRegistry::isValid('project.programme'));
        $this->assertNotNull(FeatureAvailabilityRegistry::get('project.programme'));
    }

    public function test_unknown_feature_fails_open_to_active(): void
    {
        $service = app(FeatureAvailabilityService::class);

        $this->assertSame(FeatureAvailabilityStatus::ACTIVE, $service->statusFor('not.a.real.feature'));
        $this->assertTrue($service->isActive('not.a.real.feature'));
    }

    public function test_missing_db_override_resolves_active(): void
    {
        $service = app(FeatureAvailabilityService::class);

        $this->assertTrue($service->isActive('project.programme'));
        $this->assertSame([], $service->allEffective());
    }

    public function test_active_override_resolves_active(): void
    {
        FeatureAvailability::create(['feature_key' => 'project.programme', 'status' => 'active']);

        $service = app(FeatureAvailabilityService::class);

        $this->assertTrue($service->isActive('project.programme'));
        // Active rows are never surfaced by allEffective() — sparse payload contract.
        $this->assertArrayNotHasKey('project.programme', $service->allEffective());
    }

    public function test_maintenance_override_resolves_maintenance(): void
    {
        FeatureAvailability::create(['feature_key' => 'project.programme', 'status' => 'maintenance', 'message' => 'Under repair']);

        $service = app(FeatureAvailabilityService::class);

        $this->assertTrue($service->isMaintenance('project.programme'));
        $this->assertSame('maintenance', $service->statusFor('project.programme'));
        $this->assertSame('Under repair', $service->entryFor('project.programme')['message']);
    }

    public function test_coming_soon_override_resolves_coming_soon(): void
    {
        FeatureAvailability::create(['feature_key' => 'ai.assistant', 'status' => 'coming_soon']);

        $service = app(FeatureAvailabilityService::class);

        $this->assertTrue($service->isComingSoon('ai.assistant'));
    }

    public function test_corrupt_status_fails_open_to_active(): void
    {
        // Bypass the model/service write path entirely to simulate a raw DB anomaly.
        DB::table('feature_availabilities')->insert([
            'feature_key' => 'project.programme',
            'status' => 'totally-not-a-real-status',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(FeatureAvailabilityService::class);

        $this->assertTrue($service->isActive('project.programme'));
    }

    public function test_unsupported_status_rejected_for_registry_entry(): void
    {
        // project.contracts does not support coming_soon in V1.
        $this->assertFalse(FeatureAvailabilityRegistry::supportsStatus('project.contracts', FeatureAvailabilityStatus::COMING_SOON));
        // ai.assistant does not support maintenance in V1 (nothing to "maintain").
        $this->assertFalse(FeatureAvailabilityRegistry::supportsStatus('ai.assistant', FeatureAvailabilityStatus::MAINTENANCE));
        // project.drawings does NOT support coming_soon — being recently shipped is not sufficient justification.
        $this->assertFalse(FeatureAvailabilityRegistry::supportsStatus('project.drawings', FeatureAvailabilityStatus::COMING_SOON));
    }

    // ── B. Customer status API ──────────────────────────────────────────

    public function test_customer_receives_non_active_overrides(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org, 'Client');
        FeatureAvailability::create(['feature_key' => 'project.programme', 'status' => 'maintenance', 'message' => 'We are working on it']);
        FeatureAvailability::create(['feature_key' => 'ai.assistant', 'status' => 'coming_soon']);

        $response = $this->actingAs($user)->getJson('/api/feature-availability');

        $response->assertOk();
        $features = $response->json('features');
        $this->assertSame('maintenance', $features['project.programme']['status']);
        $this->assertSame('We are working on it', $features['project.programme']['message']);
        $this->assertSame('coming_soon', $features['ai.assistant']['status']);
    }

    public function test_active_features_are_omitted_from_sparse_payload(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org, 'Client');
        FeatureAvailability::create(['feature_key' => 'project.programme', 'status' => 'active']);

        $response = $this->actingAs($user)->getJson('/api/feature-availability');

        $response->assertOk();
        $this->assertArrayNotHasKey('project.programme', $response->json('features'));
    }

    public function test_available_at_returned_to_customer(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org, 'Client');
        $when = now()->addDay();
        FeatureAvailability::create(['feature_key' => 'project.programme', 'status' => 'maintenance', 'available_at' => $when]);

        $response = $this->actingAs($user)->getJson('/api/feature-availability');

        $response->assertOk();
        $this->assertNotNull($response->json('features')['project.programme']['available_at']);
    }

    public function test_customer_payload_never_exposes_audit_or_actor_fields(): void
    {
        $org = $this->makeOrg();
        $superAdmin = $this->makeUser($this->makeOrg('Platform Co'), 'Super Admin');
        $user = $this->makeUser($org, 'Client');
        FeatureAvailability::create(['feature_key' => 'project.programme', 'status' => 'maintenance', 'updated_by' => $superAdmin->id]);

        $response = $this->actingAs($user)->getJson('/api/feature-availability');

        $body = $response->json();
        $raw = json_encode($body);
        $this->assertStringNotContainsString('updated_by', $raw);
        $this->assertStringNotContainsString((string) $superAdmin->id, $raw);
    }

    public function test_customer_status_endpoint_fails_safe_when_lookup_throws(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org, 'Client');

        // Drop the table to force the underlying lookup to throw — the
        // endpoint must still respond 200 with an empty (all-Active) map,
        // never a 500.
        \Illuminate\Support\Facades\Schema::drop('feature_availabilities');

        $response = $this->actingAs($user)->getJson('/api/feature-availability');

        $response->assertOk();
        $response->assertJson(['features' => []]);
    }

    // ── C. Super Admin authorization ────────────────────────────────────

    public function test_super_admin_can_get_management_data(): void
    {
        $superAdmin = $this->makeUser($this->makeOrg(), 'Super Admin');

        $response = $this->actingAs($superAdmin)->getJson('/api/admin/feature-availability');

        $response->assertOk();
        $response->assertJsonStructure(['features' => ['project.programme' => ['label', 'status', 'maintenance_supported', 'coming_soon_supported']]]);
    }

    public function test_super_admin_can_update_status(): void
    {
        $superAdmin = $this->makeUser($this->makeOrg(), 'Super Admin');

        $response = $this->actingAs($superAdmin)->putJson('/api/admin/feature-availability/project.programme', [
            'status' => 'maintenance',
            'message' => 'Fixing a rendering bug',
            'reason' => 'Programme has a data rendering bug affecting milestone dates',
            'confirmed' => true,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('feature_availabilities', ['feature_key' => 'project.programme', 'status' => 'maintenance']);
    }

    public function test_admin_cannot_get_management_data(): void
    {
        $admin = $this->makeUser($this->makeOrg(), 'Admin');

        $response = $this->actingAs($admin)->getJson('/api/admin/feature-availability');

        $response->assertForbidden();
    }

    public function test_admin_cannot_update_status(): void
    {
        $admin = $this->makeUser($this->makeOrg(), 'Admin');

        $response = $this->actingAs($admin)->putJson('/api/admin/feature-availability/project.programme', [
            'status' => 'maintenance',
            'reason' => 'Attempting an unauthorized change',
            'confirmed' => true,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('feature_availabilities', ['feature_key' => 'project.programme']);
    }

    public function test_client_cannot_update_status(): void
    {
        $client = $this->makeUser($this->makeOrg(), 'Client');

        $response = $this->actingAs($client)->putJson('/api/admin/feature-availability/project.programme', [
            'status' => 'maintenance',
            'reason' => 'Attempting an unauthorized change',
            'confirmed' => true,
        ]);

        $response->assertForbidden();
    }

    public function test_unauthenticated_cannot_update_status(): void
    {
        $response = $this->putJson('/api/admin/feature-availability/project.programme', [
            'status' => 'maintenance',
            'reason' => 'Attempting an unauthorized change',
            'confirmed' => true,
        ]);

        $response->assertUnauthorized();
    }

    public function test_unsupported_feature_key_rejected(): void
    {
        $superAdmin = $this->makeUser($this->makeOrg(), 'Super Admin');

        $response = $this->actingAs($superAdmin)->putJson('/api/admin/feature-availability/not.a.real.feature', [
            'status' => 'maintenance',
            'reason' => 'This key does not exist in the registry',
            'confirmed' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['feature_key']);
    }

    public function test_unsupported_state_for_feature_rejected(): void
    {
        $superAdmin = $this->makeUser($this->makeOrg(), 'Super Admin');

        // ai.assistant does not support maintenance in V1.
        $response = $this->actingAs($superAdmin)->putJson('/api/admin/feature-availability/ai.assistant', [
            'status' => 'maintenance',
            'reason' => 'Attempting an unsupported state for this feature',
            'confirmed' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_reason_and_confirmation_are_required(): void
    {
        $superAdmin = $this->makeUser($this->makeOrg(), 'Super Admin');

        $response = $this->actingAs($superAdmin)->putJson('/api/admin/feature-availability/project.programme', [
            'status' => 'maintenance',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['reason', 'confirmed']);
    }

    // ── D. Audit ─────────────────────────────────────────────────────────

    public function test_status_change_writes_activity_log_with_full_transition(): void
    {
        $superAdmin = $this->makeUser($this->makeOrg(), 'Super Admin');

        $this->actingAs($superAdmin)->putJson('/api/admin/feature-availability/project.programme', [
            'status' => 'maintenance',
            'message' => 'Fixing a bug',
            'reason' => 'Programme has a data rendering bug affecting milestone dates',
            'confirmed' => true,
        ])->assertOk();

        $log = ActivityLog::where('action', 'feature_availability.status_changed')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($superAdmin->id, $log->user_id);
        $this->assertSame('project.programme', $log->metadata['feature_key']);
        $this->assertSame('active', $log->metadata['previous_status']);
        $this->assertSame('maintenance', $log->metadata['new_status']);
        $this->assertSame('Programme has a data rendering bug affecting milestone dates', $log->metadata['reason']);
        $this->assertSame($superAdmin->id, $log->metadata['changed_by']);
    }

    public function test_restoring_active_is_audited_and_deletes_override_row(): void
    {
        $superAdmin = $this->makeUser($this->makeOrg(), 'Super Admin');
        FeatureAvailability::create(['feature_key' => 'project.programme', 'status' => 'maintenance', 'message' => 'Broken']);

        $response = $this->actingAs($superAdmin)->putJson('/api/admin/feature-availability/project.programme', [
            'status' => 'active',
            'reason' => 'Programme has been fixed and verified',
            'confirmed' => true,
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('feature_availabilities', ['feature_key' => 'project.programme']);

        $log = ActivityLog::where('action', 'feature_availability.status_changed')->latest('id')->first();
        $this->assertSame('maintenance', $log->metadata['previous_status']);
        $this->assertSame('active', $log->metadata['new_status']);
    }

    // ── E. Cache ─────────────────────────────────────────────────────────

    public function test_status_lookup_is_cached(): void
    {
        $service = app(FeatureAvailabilityService::class);
        $service->allEffective();

        $this->assertTrue(Cache::has(\App\Support\FeatureAvailability\FeatureAvailabilityCacheInvalidator::CACHE_KEY));
    }

    public function test_update_invalidates_cache(): void
    {
        $superAdmin = $this->makeUser($this->makeOrg(), 'Super Admin');
        $service = app(FeatureAvailabilityService::class);
        $service->allEffective(); // warms the cache with an empty map
        $this->assertTrue($service->isActive('project.programme'));

        $this->actingAs($superAdmin)->putJson('/api/admin/feature-availability/project.programme', [
            'status' => 'maintenance',
            'reason' => 'Programme has a data rendering bug affecting milestone dates',
            'confirmed' => true,
        ])->assertOk();

        // A fresh service instance still observes the change immediately —
        // no manual cache clear, no deployment.
        $this->assertTrue(app(FeatureAvailabilityService::class)->isMaintenance('project.programme'));
    }

    public function test_restoring_active_invalidates_cache(): void
    {
        $superAdmin = $this->makeUser($this->makeOrg(), 'Super Admin');
        FeatureAvailability::create(['feature_key' => 'project.programme', 'status' => 'maintenance']);
        $service = app(FeatureAvailabilityService::class);
        $this->assertTrue($service->isMaintenance('project.programme'));

        $this->actingAs($superAdmin)->putJson('/api/admin/feature-availability/project.programme', [
            'status' => 'active',
            'reason' => 'Programme has been fixed and verified',
            'confirmed' => true,
        ])->assertOk();

        $this->assertTrue(app(FeatureAvailabilityService::class)->isActive('project.programme'));
    }

    // ── F. Middleware foundation (tested directly, never attached to a real route) ──

    public function test_middleware_permits_client_when_active(): void
    {
        $client = $this->makeUser($this->makeOrg(), 'Client');
        $this->registerTestMiddlewareRoute('project.programme');

        $response = $this->actingAs($client)->getJson('/__test/feature-gate');

        $response->assertOk();
        $response->assertJson(['ok' => true]);
    }

    public function test_middleware_blocks_client_when_maintenance(): void
    {
        $client = $this->makeUser($this->makeOrg(), 'Client');
        FeatureAvailability::create(['feature_key' => 'project.programme', 'status' => 'maintenance']);
        $this->registerTestMiddlewareRoute('project.programme');

        $response = $this->actingAs($client)->getJson('/__test/feature-gate');

        $response->assertStatus(503);
        $response->assertJson(['code' => 'feature_maintenance', 'feature' => 'project.programme']);
    }

    public function test_middleware_blocks_client_when_coming_soon(): void
    {
        $client = $this->makeUser($this->makeOrg(), 'Client');
        FeatureAvailability::create(['feature_key' => 'ai.assistant', 'status' => 'coming_soon']);
        $this->registerTestMiddlewareRoute('ai.assistant');

        $response = $this->actingAs($client)->getJson('/__test/feature-gate');

        $response->assertStatus(503);
        $response->assertJson(['code' => 'feature_coming_soon', 'feature' => 'ai.assistant']);
    }

    public function test_middleware_bypasses_for_super_admin(): void
    {
        $superAdmin = $this->makeUser($this->makeOrg(), 'Super Admin');
        FeatureAvailability::create(['feature_key' => 'project.programme', 'status' => 'maintenance']);
        $this->registerTestMiddlewareRoute('project.programme');

        $response = $this->actingAs($superAdmin)->getJson('/__test/feature-gate');

        $response->assertOk();
        $response->assertJson(['ok' => true]);
    }

    public function test_middleware_bypasses_for_admin(): void
    {
        $admin = $this->makeUser($this->makeOrg(), 'Admin');
        FeatureAvailability::create(['feature_key' => 'project.programme', 'status' => 'maintenance']);
        $this->registerTestMiddlewareRoute('project.programme');

        $response = $this->actingAs($admin)->getJson('/__test/feature-gate');

        $response->assertOk();
        $response->assertJson(['ok' => true]);
    }

    public function test_middleware_fails_open_for_unknown_key(): void
    {
        $client = $this->makeUser($this->makeOrg(), 'Client');
        $this->registerTestMiddlewareRoute('not.a.real.feature');

        $response = $this->actingAs($client)->getJson('/__test/feature-gate');

        $response->assertOk();
        $response->assertJson(['ok' => true]);
    }

    /**
     * Registers a single throwaway test route with the middleware pinned to
     * a literal, known feature key — deliberately NOT a route parameter,
     * since middleware parameters are resolved at route-registration time,
     * not per-request. This is exactly the "test the middleware directly"
     * approach the Phase A spec calls for, without attaching it to any real
     * module route.
     */
    private function registerTestMiddlewareRoute(string $featureKey): void
    {
        \Illuminate\Support\Facades\Route::middleware(['api', 'auth:sanctum'])
            ->get('/__test/feature-gate', function () {
                return response()->json(['ok' => true]);
            })
            ->middleware("feature.available:{$featureKey}");
    }

    // ── G. Data safety ──────────────────────────────────────────────────

    public function test_status_change_does_not_mutate_project_contract_or_trade_package_data(): void
    {
        $superAdmin = $this->makeUser($this->makeOrg(), 'Super Admin');
        $org = $this->makeOrg('Contractor Ltd');
        $owner = $this->makeUser($org, 'Client');
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $owner->id, 'name' => 'Riverside Apartments']);
        $contract = Contract::create(['project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $owner->id, 'type' => 'main_contract', 'title' => 'Main Contract']);
        $tradePackage = TradePackage::create(['project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $owner->id, 'name' => 'Groundworks Package', 'slug' => 'groundworks-package-' . str()->random(6)]);

        $projectBefore = $project->fresh()->toArray();
        $contractBefore = $contract->fresh()->toArray();
        $tradePackageBefore = $tradePackage->fresh()->toArray();

        $this->actingAs($superAdmin)->putJson('/api/admin/feature-availability/project.programme', [
            'status' => 'maintenance',
            'reason' => 'Programme has a data rendering bug affecting milestone dates',
            'confirmed' => true,
        ])->assertOk();

        $this->assertSame($projectBefore, $project->fresh()->toArray());
        $this->assertSame($contractBefore, $contract->fresh()->toArray());
        $this->assertSame($tradePackageBefore, $tradePackage->fresh()->toArray());
    }

    // ── H. AI credits ────────────────────────────────────────────────────

    public function test_no_ai_credit_ledger_activity_occurs(): void
    {
        $superAdmin = $this->makeUser($this->makeOrg(), 'Super Admin');

        $this->actingAs($superAdmin)->putJson('/api/admin/feature-availability/project.programme', [
            'status' => 'maintenance',
            'reason' => 'Programme has a data rendering bug affecting milestone dates',
            'confirmed' => true,
        ])->assertOk();

        $this->assertDatabaseCount('ai_credit_ledger_entries', 0);
        $this->assertDatabaseCount('ai_credit_simulation_results', 0);
    }

    // ── I. Global nature / tenant isolation ─────────────────────────────

    public function test_global_availability_is_identical_across_organizations_without_leaking_tenant_data(): void
    {
        $orgA = $this->makeOrg('Org A Ltd');
        $orgB = $this->makeOrg('Org B Ltd');
        $userA = $this->makeUser($orgA, 'Client');
        $userB = $this->makeUser($orgB, 'Client');

        FeatureAvailability::create(['feature_key' => 'project.programme', 'status' => 'maintenance', 'message' => 'Global maintenance']);

        $responseA = $this->actingAs($userA)->getJson('/api/feature-availability');
        $responseB = $this->actingAs($userB)->getJson('/api/feature-availability');

        $responseA->assertOk();
        $responseB->assertOk();
        $this->assertSame($responseA->json('features'), $responseB->json('features'));

        // Neither response mentions the other organisation, or any org id at all.
        $this->assertStringNotContainsString('Org A', json_encode($responseB->json()));
        $this->assertStringNotContainsString('Org B', json_encode($responseA->json()));
    }
}

<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\Admin\AdminAccessService;
use App\Support\Admin\AdminAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Super Admin Configurable Admin Access — the per-Admin module permission
 * system, now fully live (both Stage 1 and Stage 2 of the Two-Stage Admin
 * Access rollout are shipped; the production backfill completed
 * 2026-08-26). Covers the catalogue bootstrap, the sentinel, the full
 * lifecycle (new/existing/restricted/role-change Admins), cold-start
 * safety, the Gate::before() Super Admin bypass, the configuration
 * endpoints, permanently-Super-Admin-only role tightening, and the
 * ActivityLog audit trail. Configurable-module `admin.module.*`
 * enforcement coverage (restricted -> 403, permitted -> 200) lives in
 * AdminAccessEnforcementTest.php — a separate file kept for the duration
 * of the rollout, not merged back into this one, since it still reads
 * clearly on its own. Client regression lives in
 * Batch1ClientPermissionsTest and is re-run, not duplicated, here.
 */
class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuperAdmin(string $email = 'sa@example.com'): User
    {
        $user = User::factory()->create(['organization_id' => null, 'email' => $email]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        return $user;
    }

    private function makeAdmin(string $email = 'admin@example.com'): User
    {
        $user = User::factory()->create(['organization_id' => null, 'email' => $email]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));

        return $user;
    }

    private function makeClient(): User
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        return $user;
    }

    // ── Catalogue bootstrap ──────────────────────────────────────────────

    public function test_permission_catalogue_bootstraps_idempotently(): void
    {
        AdminAccessService::ensurePermissionsExist();
        AdminAccessService::ensurePermissionsExist();

        $count = Permission::whereIn('name', AdminAccess::keys())->count();
        $this->assertSame(count(AdminAccess::keys()), $count);
    }

    /**
     * Default-Baseline Correction (2026-08-27) — the authoritative counts,
     * hardcoded rather than compared to themselves, so a future accidental
     * change to either list is caught here explicitly rather than only by
     * every other test's dynamic count() call silently moving together.
     */
    public function test_configurable_catalogue_is_23_and_default_baseline_is_15(): void
    {
        $this->assertCount(23, AdminAccess::keys());
        $this->assertCount(15, AdminAccess::defaultKeys());

        // Every default key must also be a valid catalogue key — the
        // default baseline is a subset of the configurable catalogue, not
        // a parallel, potentially-divergent list.
        foreach (AdminAccess::defaultKeys() as $key) {
            $this->assertTrue(AdminAccess::isValidKey($key));
        }

        // The eight modules the Full Parity Access Expansion added are
        // configurable but must never appear in the default baseline.
        $sensitive = [
            'admin.module.users',
            'admin.module.ai_config',
            'admin.module.application_monitoring',
            'admin.module.storage',
            'admin.module.support',
            'admin.module.announcements',
            'admin.module.system_logs',
            'admin.module.audit_log',
        ];
        foreach ($sensitive as $key) {
            $this->assertTrue(AdminAccess::isValidKey($key), "{$key} should be a valid configurable key");
            $this->assertNotContains($key, AdminAccess::defaultKeys(), "{$key} should not be in the default baseline");
        }
    }

    /**
     * An Admin already initialised under the default baseline (holds the
     * sentinel + exactly the 15 default keys) must not be silently
     * upgraded to include the eight sensitive modules merely because the
     * backfill command runs again — the sentinel alone is what skips
     * them, regardless of which keys they currently hold.
     */
    public function test_existing_initialized_admin_is_not_silently_upgraded_by_backfill(): void
    {
        $admin = $this->makeAdmin();
        AdminAccessService::grantDefaultAccess($admin);
        $this->assertTrue(AdminAccessService::isInitialized($admin->fresh()));

        Artisan::call('admin:permissions:backfill');

        $fresh = $admin->fresh();
        $this->assertEqualsCanonicalizing(AdminAccess::defaultKeys(), $fresh->getPermissionNames()->intersect(AdminAccess::keys())->values()->all());
        $this->assertFalse($fresh->hasPermissionTo('admin.module.users'));
        $this->assertFalse($fresh->hasPermissionTo('admin.module.storage'));
    }

    // ── Cold-start backfill (Final Deployment / Cold-Start Hardening) ────

    /**
     * Reproduces, then proves fixed, the true production cold-start
     * state: NOT ONE admin.module.* or admin.access.initialized row
     * exists in the permissions table at all (a fresh install, or any
     * environment where nothing has ever called ensurePermissionsExist()
     * yet) — distinct from every other test in this file, which relies on
     * makeAdmin()'s own assignRole() call having already triggered the
     * listener and bootstrapped the catalogue via RoleAttachedEvent. Here
     * that event is deliberately faked/suppressed so the row is left in a
     * genuinely untouched legacy state, matching a real Admin created
     * before this feature existed or with events disabled at the time.
     */
    public function test_backfill_cold_start_zero_permission_rows_does_not_throw(): void
    {
        \Illuminate\Support\Facades\Event::fake();

        $this->assertSame(0, Permission::whereIn('name', array_merge(AdminAccess::keys(), [AdminAccess::INITIALIZED_SENTINEL]))->count());

        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['organization_id' => null]);
        $admin->assignRole($role);
        $this->assertSame(0, $admin->permissions()->count());

        // 1-4. --dry-run must not throw PermissionDoesNotExist, and must
        // not mutate anything — no catalogue/sentinel rows created, no
        // permissions granted.
        Artisan::call('admin:permissions:backfill', ['--dry-run' => true]);
        $this->assertSame(0, Permission::whereIn('name', array_merge(AdminAccess::keys(), [AdminAccess::INITIALIZED_SENTINEL]))->count());
        $this->assertSame(0, $admin->fresh()->permissions()->count());

        // 5-7. The real run bootstraps the entire catalogue's permission
        // ROWS (ensurePermissionsExist() always creates all 23 + the
        // sentinel, regardless of default-vs-configurable), then GRANTS
        // the legacy Admin only the default baseline (Default-Baseline
        // Correction, 2026-08-27) — never the full 23-key catalogue.
        Artisan::call('admin:permissions:backfill');
        $this->assertSame(count(AdminAccess::keys()) + 1, Permission::whereIn('name', array_merge(AdminAccess::keys(), [AdminAccess::INITIALIZED_SENTINEL]))->count());
        $fresh = $admin->fresh();
        $this->assertTrue(AdminAccessService::isInitialized($fresh));
        foreach (AdminAccess::defaultKeys() as $key) {
            $this->assertTrue($fresh->hasPermissionTo($key));
        }

        // 8-9. Re-running changes nothing.
        Artisan::call('admin:permissions:backfill');
        $this->assertSame(count(AdminAccess::defaultKeys()) + 1, $admin->fresh()->permissions->count());
    }

    /**
     * Same cold-start database state, but the legacy Admin was already
     * deliberately Clear-All'd (initialised, zero modules) via a genuine
     * pre-existing sentinel row of its own before the catalogue's OTHER
     * rows ever got created elsewhere. Distinguishes "no catalogue rows
     * exist anywhere yet" from "this specific Admin holds none" — the
     * backfill command must still leave this Admin at zero.
     */
    public function test_backfill_cold_start_intentionally_zero_admin_stays_zero(): void
    {
        \Illuminate\Support\Facades\Event::fake();

        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['organization_id' => null]);
        $admin->assignRole($role);

        // Simulate an Admin already deliberately initialised to zero
        // modules, in a database where the wider catalogue rows otherwise
        // don't exist yet (only the sentinel does).
        AdminAccessService::ensurePermissionsExist();
        $admin->givePermissionTo(AdminAccess::INITIALIZED_SENTINEL);
        $this->assertSame(0, $admin->fresh()->getPermissionNames()->intersect(AdminAccess::keys())->count());

        Artisan::call('admin:permissions:backfill');

        $this->assertSame(0, $admin->fresh()->getPermissionNames()->intersect(AdminAccess::keys())->count());
        $this->assertTrue(AdminAccessService::isInitialized($admin->fresh()));
    }

    /**
     * Phase 4 recheck: an initialised-to-zero Admin survives boot/login
     * (/auth/me), an unrelated profile update, and a backfill run +
     * rerun — all without regaining a single admin.module.* permission.
     * Only a genuine role transition away from and back into Admin may
     * reset to the full baseline.
     */
    public function test_initialized_zero_permission_admin_survives_me_update_and_backfill(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $admin = $this->makeAdmin();
        AdminAccessService::grantDefaultAccess($admin);
        Sanctum::actingAs($superAdmin);
        $this->putJson("/api/users/{$admin->id}/permissions", ['permissions' => []])->assertStatus(200);

        Sanctum::actingAs($admin->fresh());
        $this->getJson('/api/auth/me')->assertStatus(200);
        $this->assertSame(0, $admin->fresh()->getPermissionNames()->intersect(AdminAccess::keys())->count());

        Sanctum::actingAs($superAdmin);
        $this->putJson("/api/users/{$admin->id}", ['name' => 'Renamed'])->assertStatus(200);
        $this->assertSame(0, $admin->fresh()->getPermissionNames()->intersect(AdminAccess::keys())->count());

        Artisan::call('admin:permissions:backfill');
        $this->assertSame(0, $admin->fresh()->getPermissionNames()->intersect(AdminAccess::keys())->count());
        Artisan::call('admin:permissions:backfill');
        $this->assertSame(0, $admin->fresh()->getPermissionNames()->intersect(AdminAccess::keys())->count());

        // Only a genuine transition away and back resets to the default
        // baseline (Default-Baseline Correction, 2026-08-27 — never the
        // full 23-key catalogue).
        $this->putJson("/api/users/{$admin->id}", ['role' => 'Client'])->assertStatus(200);
        $this->putJson("/api/users/{$admin->id}", ['role' => 'Admin'])->assertStatus(200);
        $this->assertSame(count(AdminAccess::defaultKeys()), $admin->fresh()->getPermissionNames()->intersect(AdminAccess::keys())->count());
    }

    // ── Baseline lifecycle ───────────────────────────────────────────────

    public function test_existing_admin_receives_default_baseline_via_backfill(): void
    {
        $admin = $this->makeAdmin();
        // Simulates a genuinely legacy/never-initialised Admin row — no
        // AdminAccess::INITIALIZED_SENTINEL, regardless of how many (if
        // any) admin.module.* permissions happen to be present. Wiping
        // ALL permissions (including any auto-granted-by-listener sentinel)
        // is the correct way to model "never initialised" under the fixed
        // sentinel-based logic.
        $admin->syncPermissions([]);
        $this->assertFalse(AdminAccessService::isInitialized($admin->fresh()));

        Artisan::call('admin:permissions:backfill');

        $fresh = $admin->fresh();
        $this->assertTrue(AdminAccessService::isInitialized($fresh));
        // Default-Baseline Correction (2026-08-27) — only the original 15
        // modules, never the eight formerly-permanently-Super-Admin-only
        // ones the Full Parity Access Expansion made configurable.
        foreach (AdminAccess::defaultKeys() as $key) {
            $this->assertTrue($fresh->hasPermissionTo($key));
        }
        foreach (['admin.module.users', 'admin.module.ai_config', 'admin.module.application_monitoring', 'admin.module.storage', 'admin.module.support', 'admin.module.announcements', 'admin.module.system_logs', 'admin.module.audit_log'] as $sensitiveKey) {
            $this->assertFalse($fresh->hasPermissionTo($sensitiveKey));
        }
    }

    public function test_backfill_never_restores_an_admin_deliberately_restricted_to_a_subset(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $admin = $this->makeAdmin();
        AdminAccessService::grantDefaultAccess($admin);
        Sanctum::actingAs($superAdmin);

        // Restrict via the REAL configuration endpoint — the only
        // safe/realistic way a Super Admin restricts an Admin. This is
        // what correctly preserves the sentinel (unlike a raw
        // syncPermissions() call, which this test deliberately avoids).
        $this->putJson("/api/users/{$admin->id}/permissions", ['permissions' => ['admin.module.pricing']])
            ->assertStatus(200);
        $this->assertTrue(AdminAccessService::isInitialized($admin->fresh()));

        Artisan::call('admin:permissions:backfill');

        $this->assertSame(['admin.module.pricing'], $admin->fresh()->getPermissionNames()->intersect(AdminAccess::keys())->values()->all());
    }

    /**
     * The mandatory four-state backfill matrix (Admin Access Configuration
     * — Final Initialisation phase): legacy-uninitialised is granted;
     * already-initialised-full, already-initialised-partial, and
     * already-initialised-ZERO are all left completely untouched. This is
     * the direct regression test for the confirmed zero-permission bug —
     * before this fix, the zero-module case was indistinguishable from
     * the legacy case and would have been wrongly re-granted.
     */
    public function test_backfill_four_state_matrix(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        Sanctum::actingAs($superAdmin);

        $legacy = $this->makeAdmin('legacy@example.com');
        $legacy->syncPermissions([]); // never initialised

        $defaulted = $this->makeAdmin('defaulted@example.com');
        AdminAccessService::grantDefaultAccess($defaulted); // initialised, default baseline

        $partial = $this->makeAdmin('partial@example.com');
        $this->putJson("/api/users/{$partial->id}/permissions", ['permissions' => ['admin.module.pricing']])
            ->assertStatus(200);

        $zero = $this->makeAdmin('zero@example.com');
        AdminAccessService::grantDefaultAccess($zero);
        $this->putJson("/api/users/{$zero->id}/permissions", ['permissions' => []])
            ->assertStatus(200); // Clear All — deliberately initialised to zero modules

        Artisan::call('admin:permissions:backfill');

        $this->assertTrue($legacy->fresh()->hasPermissionTo('admin.module.pricing'));
        $this->assertSame(count(AdminAccess::defaultKeys()), $defaulted->fresh()->getPermissionNames()->intersect(AdminAccess::keys())->count());
        $this->assertSame(['admin.module.pricing'], $partial->fresh()->getPermissionNames()->intersect(AdminAccess::keys())->values()->all());
        // The critical assertion: zero stays zero after backfill.
        $this->assertCount(0, $zero->fresh()->getPermissionNames()->intersect(AdminAccess::keys()));
        $this->assertTrue(AdminAccessService::isInitialized($zero->fresh()));
    }

    /**
     * Clear-All PERSISTENCE sequence (Stage 1 scope only): an
     * intentionally zero-module Admin must survive a backfill re-run AND
     * a legitimate role-lifecycle event that isn't a genuine role
     * transition — the permission STATE itself (sentinel present,
     * catalogue permissions empty) is stable regardless of Stage.
     * Deliberately does NOT assert a 403 on any module route here — Stage
     * 1 has no `permission:admin.module.*` middleware yet, so a
     * Clear-All'd Admin correctly still succeeds against those routes in
     * this build (see test_legacy_admin_retains_historical_access_before_stage_2_enforcement
     * below). The Stage-2 counterpart (asserting the eventual 403) lives
     * in AdminAccessEnforcementTest.php, held for Stage 2.
     */
    public function test_clear_all_state_survives_backfill_rerun_and_a_non_transition_role_event(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $admin = $this->makeAdmin();
        AdminAccessService::grantDefaultAccess($admin); // 1. Admin gets baseline
        Sanctum::actingAs($superAdmin);

        // 2/3. Super Admin uses the Access API to Clear All.
        $this->putJson("/api/users/{$admin->id}/permissions", ['permissions' => []])
            ->assertStatus(200);
        $this->assertCount(0, $admin->fresh()->getPermissionNames()->intersect(AdminAccess::keys()));

        // 4. Sentinel remains.
        $this->assertTrue(AdminAccessService::isInitialized($admin->fresh()));

        // 5/6. Re-running the backfill changes nothing.
        Artisan::call('admin:permissions:backfill');
        $this->assertCount(0, $admin->fresh()->getPermissionNames()->intersect(AdminAccess::keys()));

        // 7/8. A legitimate role-lifecycle event that is NOT a genuine
        // transition (re-submitting the same role) must not restore access
        // — exercises both UserController::update()'s own before/after
        // guard and the RoleAttachedEvent listener (which now checks
        // sentinel presence, not permission count).
        $this->putJson("/api/users/{$admin->id}", ['role' => 'Admin'])->assertStatus(200);
        $this->assertCount(0, $admin->fresh()->getPermissionNames()->intersect(AdminAccess::keys()));
    }

    // NOTE (Two-Stage Admin Access rollout): the Stage-1-only
    // test_legacy_admin_retains_historical_access_before_stage_2_enforcement
    // that used to live here asserted that a zero-permission Admin still
    // got historical (unenforced) access — correct only while Stage 2's
    // `permission:admin.module.*` middleware was deliberately held back.
    // Now that Stage 2 is live (production backfill confirmed complete,
    // 2026-08-26), that assertion is no longer true and has been removed
    // rather than left to bit-rot. Equivalent, now-correct coverage
    // already exists: AdminAccessEnforcementTest.php's
    // test_backend_authority_companies_projects_pricing_ai_credits_google_integration
    // covers the same five modules (restricted -> 403, permitted -> 200),
    // and this file's own
    // test_permanently_super_admin_only_api_remains_blocked_from_admin_even_with_full_catalogue
    // / test_admin_cannot_access_super_admin_only_api_by_faking_a_permission_name
    // already cover the permanently-Super-Admin-only denial.

    public function test_new_admin_via_invite_receives_default_baseline_only(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'x'], 201)]);
        \App\Models\SuresignSetting::instance()->update([
            'brevo_api_key' => 'fake-brevo-key', 'email_sender_email' => 'noreply@suresigncontracts.app',
            'support_email' => 'support@suresigncontracts.app', 'admin_email' => 'admin@suresigncontracts.app',
        ]);
        $superAdmin = $this->makeSuperAdmin();
        Sanctum::actingAs($superAdmin);

        $this->postJson('/api/users/invite', ['email' => 'newadmin@example.com', 'role' => 'Admin'])
            ->assertStatus(201);

        $newAdmin = User::where('email', 'newadmin@example.com')->first();
        // Default-Baseline Correction (2026-08-27) — a new Admin gets
        // exactly AdminAccess::defaultKeys() (15), never the full 23-key
        // catalogue; the eight sensitive modules require an explicit
        // Super Admin grant.
        $this->assertEqualsCanonicalizing(AdminAccess::defaultKeys(), $newAdmin->getPermissionNames()->intersect(AdminAccess::keys())->values()->all());
        $this->assertFalse($newAdmin->hasPermissionTo('admin.module.users'));
        $this->assertFalse($newAdmin->hasPermissionTo('admin.module.ai_config'));
        $this->assertFalse($newAdmin->hasPermissionTo('admin.module.storage'));
        $this->assertFalse($newAdmin->hasPermissionTo('admin.module.audit_log'));
        $this->assertTrue(AdminAccessService::isInitialized($newAdmin));
    }

    public function test_role_change_to_admin_receives_default_baseline_only(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $client = $this->makeClient();
        Sanctum::actingAs($superAdmin);

        $this->putJson("/api/users/{$client->id}", ['role' => 'Admin'])->assertStatus(200);

        $fresh = $client->fresh();
        $this->assertTrue($fresh->hasRole('Admin'));
        $this->assertEqualsCanonicalizing(AdminAccess::defaultKeys(), $fresh->getPermissionNames()->intersect(AdminAccess::keys())->values()->all());
        $this->assertTrue(AdminAccessService::isInitialized($fresh));
    }

    public function test_restricted_admin_retains_restrictions_after_unrelated_profile_update(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $admin = $this->makeAdmin();
        AdminAccessService::grantDefaultAccess($admin);
        Sanctum::actingAs($superAdmin);

        $this->putJson("/api/users/{$admin->id}/permissions", ['permissions' => ['admin.module.pricing']])
            ->assertStatus(200);

        // Unrelated field-only update — no 'role' key submitted at all.
        $this->putJson("/api/users/{$admin->id}", ['name' => 'New Name'])->assertStatus(200);

        $this->assertSame(['admin.module.pricing'], $admin->fresh()->getPermissionNames()->intersect(AdminAccess::keys())->values()->all());
    }

    public function test_restricted_admin_retains_restrictions_when_role_resubmitted_unchanged(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $admin = $this->makeAdmin();
        AdminAccessService::grantDefaultAccess($admin);
        Sanctum::actingAs($superAdmin);

        // Restrict via the real endpoint, which preserves the sentinel —
        // never a raw syncPermissions() call, which would wipe it and
        // make this test model an impossible state.
        $this->putJson("/api/users/{$admin->id}/permissions", ['permissions' => ['admin.module.pricing']])
            ->assertStatus(200);

        // Resubmitting the SAME role ('Admin' -> 'Admin') must never reset
        // the restriction back to full, whether via the explicit
        // before/after check in UserController::update() or via the
        // catch-all RoleAttachedEvent listener (which now keys off sentinel
        // presence, not permission count — this admin holds the sentinel).
        $this->putJson("/api/users/{$admin->id}", ['role' => 'Admin'])->assertStatus(200);

        $this->assertSame(['admin.module.pricing'], $admin->fresh()->getPermissionNames()->intersect(AdminAccess::keys())->values()->all());
    }

    public function test_role_change_away_from_admin_removes_admin_permissions(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $admin = $this->makeAdmin();
        AdminAccessService::grantDefaultAccess($admin);
        Sanctum::actingAs($superAdmin);

        $this->putJson("/api/users/{$admin->id}", ['role' => 'Client'])->assertStatus(200);

        // Both the catalogue permissions AND the initialisation sentinel
        // are removed on a genuine transition away from Admin — a later
        // transition back into Admin must be a fresh start, never a
        // silent restoration of whatever was configured before.
        $this->assertCount(0, $admin->fresh()->permissions);
        $this->assertFalse(AdminAccessService::isInitialized($admin->fresh()));
    }

    public function test_admin_to_super_admin_transition_also_removes_sentinel(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $admin = $this->makeAdmin();
        AdminAccessService::grantDefaultAccess($admin);
        Sanctum::actingAs($superAdmin);

        $this->putJson("/api/users/{$admin->id}", ['role' => 'Super Admin'])->assertStatus(200);

        $this->assertCount(0, $admin->fresh()->permissions);
        $this->assertFalse(AdminAccessService::isInitialized($admin->fresh()));
    }

    public function test_transition_back_into_admin_after_a_prior_restriction_is_a_fresh_start(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $admin = $this->makeAdmin();
        AdminAccessService::grantDefaultAccess($admin);
        Sanctum::actingAs($superAdmin);

        // Restrict, then move away from Admin (removes managed access +
        // sentinel), then back into Admin — must be the default baseline
        // again (never the full 23-key catalogue, and never a silent
        // restoration of the earlier restriction).
        $this->putJson("/api/users/{$admin->id}/permissions", ['permissions' => ['admin.module.pricing']])->assertStatus(200);
        $this->putJson("/api/users/{$admin->id}", ['role' => 'Client'])->assertStatus(200);
        $this->putJson("/api/users/{$admin->id}", ['role' => 'Admin'])->assertStatus(200);

        $fresh = $admin->fresh();
        $this->assertEqualsCanonicalizing(AdminAccess::defaultKeys(), $fresh->getPermissionNames()->intersect(AdminAccess::keys())->values()->all());
        $this->assertTrue(AdminAccessService::isInitialized($fresh));
    }

    // ── Super Admin bypass ───────────────────────────────────────────────

    public function test_super_admin_bypasses_every_module_check_with_zero_stored_permissions(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $this->assertCount(0, $superAdmin->permissions);
        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/admin/pricing/settings')->assertStatus(200);
        $this->getJson('/api/admin/dashboard')->assertStatus(200);
    }

    // ── Configuration endpoints ──────────────────────────────────────────

    public function test_super_admin_can_read_and_write_an_admins_access(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $admin = $this->makeAdmin();
        AdminAccessService::grantDefaultAccess($admin);
        Sanctum::actingAs($superAdmin);

        $this->getJson("/api/users/{$admin->id}/permissions")
            ->assertStatus(200)
            ->assertJsonCount(count(AdminAccess::defaultKeys()), 'data.granted');

        $this->putJson("/api/users/{$admin->id}/permissions", ['permissions' => ['admin.module.pricing', 'admin.module.projects']])
            ->assertStatus(200)
            ->assertJsonPath('data.granted', ['admin.module.pricing', 'admin.module.projects']);

        // The sentinel remains held (this is an ordinary configuration
        // call, not a role transition) but is never returned by the
        // catalogue-scoped query below or by the endpoint's own response.
        $this->assertEqualsCanonicalizing(
            ['admin.module.pricing', 'admin.module.projects'],
            $admin->fresh()->getPermissionNames()->intersect(AdminAccess::keys())->all(),
        );
        $this->assertTrue(AdminAccessService::isInitialized($admin->fresh()));
    }

    public function test_admin_cannot_configure_permissions(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeAdmin('target@example.com');
        Sanctum::actingAs($admin);

        $this->getJson("/api/users/{$target->id}/permissions")->assertStatus(403);
        $this->putJson("/api/users/{$target->id}/permissions", ['permissions' => []])->assertStatus(403);
    }

    public function test_client_cannot_configure_permissions(): void
    {
        $client = $this->makeClient();
        $target = $this->makeAdmin();
        Sanctum::actingAs($client);

        $this->getJson("/api/users/{$target->id}/permissions")->assertStatus(403);
    }

    public function test_target_must_be_admin_not_client(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $client = $this->makeClient();
        Sanctum::actingAs($superAdmin);

        $this->getJson("/api/users/{$client->id}/permissions")->assertStatus(422);
        $this->putJson("/api/users/{$client->id}/permissions", ['permissions' => []])->assertStatus(422);
    }

    public function test_target_must_be_admin_not_super_admin(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $otherSuperAdmin = $this->makeSuperAdmin('sa2@example.com');
        Sanctum::actingAs($superAdmin);

        $this->getJson("/api/users/{$otherSuperAdmin->id}/permissions")->assertStatus(422);
    }

    public function test_super_admin_cannot_configure_their_own_access(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        Sanctum::actingAs($superAdmin);

        // Self-target is rejected before the role check even runs (this
        // Super Admin isn't an Admin anyway, but the self-check is the
        // more specific, correctly-ordered guard).
        $this->putJson("/api/users/{$superAdmin->id}/permissions", ['permissions' => []])->assertStatus(422);
    }

    public function test_unknown_permission_key_is_rejected(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $admin = $this->makeAdmin();
        $before = $admin->fresh()->getPermissionNames()->all();
        Sanctum::actingAs($superAdmin);

        $this->putJson("/api/users/{$admin->id}/permissions", ['permissions' => ['admin.module.pricing', 'not-a-real-permission']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['permissions.1']);

        // Zero mutation on a rejected update — permissions remain exactly
        // whatever they were before this request (the auto-granted
        // baseline from makeAdmin()'s own role assignment), not wiped.
        $this->assertEqualsCanonicalizing($before, $admin->fresh()->getPermissionNames()->all());
    }

    public function test_sentinel_never_appears_in_catalogue_or_show_response_and_cannot_be_submitted(): void
    {
        $this->assertFalse(AdminAccess::isValidKey(AdminAccess::INITIALIZED_SENTINEL));
        $this->assertNotContains(AdminAccess::INITIALIZED_SENTINEL, AdminAccess::keys());

        $superAdmin = $this->makeSuperAdmin();
        $admin = $this->makeAdmin();
        AdminAccessService::grantDefaultAccess($admin); // holds the sentinel
        Sanctum::actingAs($superAdmin);

        $response = $this->getJson("/api/users/{$admin->id}/permissions")->assertStatus(200);
        $this->assertNotContains(AdminAccess::INITIALIZED_SENTINEL, $response->json('data.granted'));
        foreach ($response->json('data.modules') as $module) {
            $this->assertNotSame(AdminAccess::INITIALIZED_SENTINEL, $module['key']);
        }

        // The PUT payload cannot submit it — Rule::in(AdminAccess::keys())
        // rejects it, structurally, the same as any other unknown key.
        $this->putJson("/api/users/{$admin->id}/permissions", ['permissions' => [AdminAccess::INITIALIZED_SENTINEL]])
            ->assertStatus(422);
    }

    /**
     * Full Parity Access Expansion (2026-08-26): 'admin.module.storage' is
     * now a genuine, valid catalogue key (Storage is no longer permanently
     * Super-Admin-only) — this endpoint correctly accepts it. The
     * remaining, still-genuinely-inaccessible-through-this-endpoint
     * surface is the initialisation sentinel itself, covered by
     * test_sentinel_never_appears_in_catalogue_or_show_response_and_cannot_be_submitted
     * above. This test now proves the opposite of its pre-expansion self —
     * kept (renamed) rather than deleted, to make the change explicit.
     */
    public function test_storage_capability_can_now_be_granted_through_this_endpoint(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $admin = $this->makeAdmin();
        Sanctum::actingAs($superAdmin);

        $this->putJson("/api/users/{$admin->id}/permissions", ['permissions' => ['admin.module.storage']])
            ->assertStatus(200);
        $this->assertTrue(AdminAccess::isValidKey('admin.module.storage'));
    }

    public function test_activity_log_records_granted_and_revoked_diff(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $admin = $this->makeAdmin();
        $admin->syncPermissions(['admin.module.pricing', 'admin.module.projects']);
        Sanctum::actingAs($superAdmin);

        $this->putJson("/api/users/{$admin->id}/permissions", ['permissions' => ['admin.module.pricing', 'admin.module.documents']])
            ->assertStatus(200);

        $log = ActivityLog::where('action', 'admin.permissions.updated')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEqualsCanonicalizing(['admin.module.documents'], $log->metadata['granted']);
        $this->assertEqualsCanonicalizing(['admin.module.projects'], $log->metadata['revoked']);
    }

    public function test_no_activity_log_written_when_update_produces_no_change(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $admin = $this->makeAdmin();
        $admin->syncPermissions(['admin.module.pricing']);
        Sanctum::actingAs($superAdmin);

        $this->putJson("/api/users/{$admin->id}/permissions", ['permissions' => ['admin.module.pricing']])
            ->assertStatus(200);

        $this->assertDatabaseMissing('activity_logs', ['action' => 'admin.permissions.updated', 'subject_id' => $admin->id]);
    }

    // ── Backend enforcement (permanently-Super-Admin-only surfaces that
    // remain outside the catalogue even after the Full Parity Access
    // Expansion — see AdminAccessEnforcementTest.php, for the
    // configurable-module `admin.module.*` enforcement tests, including
    // the eight formerly-permanent modules Storage/Support/Announcements/
    // System Logs/Audit Log/AI Config/Users/Application Monitoring now
    // are) ─────────────────────────────────────────────────────────────

    /**
     * Full Parity Access Expansion (2026-08-26): granting the ENTIRE
     * catalogue (now including Storage/System Logs/Audit Log/Support/
     * Announcements/AI Config) genuinely unlocks all of them for an
     * Admin — proving the opposite of what this test proved before the
     * expansion, kept (renamed) rather than deleted to make the change
     * explicit. What remains permanently Super-Admin-only regardless of
     * catalogue grants is the Access-configuration endpoint itself (see
     * test_admin_cannot_configure_permissions above) and a handful of
     * high-consequence surfaces never brought into this catalogue at all
     * (AI Credits grant/adjust/expire, the AI Credit operating-mode
     * switch, Google OAuth connect/disconnect, manual/complimentary
     * subscription assignment) — see the next test for that boundary.
     */
    public function test_full_catalogue_grant_now_unlocks_the_eight_expanded_modules(): void
    {
        $admin = $this->makeAdmin();
        // Explicit grant of the full 23-key catalogue — never
        // grantDefaultAccess(), which (Default-Baseline Correction,
        // 2026-08-27) grants only the 15-key default baseline. This test
        // is specifically about what happens when a Super Admin
        // deliberately grants everything, including the eight sensitive
        // modules.
        $admin->givePermissionTo(AdminAccess::keys());
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/storage')->assertStatus(200);
        $this->getJson('/api/admin/system-logs')->assertStatus(200);
        $this->getJson('/api/admin/audit-log')->assertStatus(200);
        $this->getJson('/api/admin/support-tickets')->assertStatus(200);
        $this->putJson('/api/admin/suresign-settings/ai', ['ai_enabled' => true])->assertStatus(200);
        $this->getJson('/api/admin/application-monitoring')->assertStatus(200);
        $this->postJson('/api/users/bulk-invite', ['emails' => [], 'role' => 'Client'])->assertStatus(422); // reaches admin.module.users-gated validation, not 403
    }

    /**
     * The Access-configuration endpoint remains role:Super Admin ONLY
     * regardless of admin.module.users or any other catalogue grant — an
     * Admin can never reach it, full parity or not.
     */
    public function test_access_configuration_endpoint_remains_super_admin_only_even_with_full_catalogue(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeAdmin('target@example.com');
        $admin->givePermissionTo(AdminAccess::keys()); // the full 23-key catalogue, deliberately not just the default baseline
        Sanctum::actingAs($admin);

        $this->getJson("/api/users/{$target->id}/permissions")->assertStatus(403);
        $this->putJson("/api/users/{$target->id}/permissions", ['permissions' => []])->assertStatus(403);
    }

    /**
     * A handful of high-consequence surfaces were never brought into the
     * catalogue at all, even after the Full Parity Access Expansion —
     * confirms a manually-inserted fake permission matching one of their
     * naming conventions still cannot grant access, since these routes
     * are gated by role:Super Admin alone.
     */
    public function test_surfaces_outside_the_catalogue_resist_fake_matching_permissions(): void
    {
        $admin = $this->makeAdmin();
        $org = Organization::create(['name' => 'Fake Target Org', 'slug' => 'fake-target-org-' . uniqid()]);
        foreach (['admin.module.ai_credits_grant', 'admin.module.google_oauth'] as $fakeName) {
            $admin->givePermissionTo(Permission::firstOrCreate(['name' => $fakeName, 'guard_name' => 'web']));
        }
        AdminAccessService::grantDefaultAccess($admin);
        Sanctum::actingAs($admin->fresh());

        $this->postJson("/api/admin/ai-credits/organizations/{$org->id}/grant", ['amount' => 10, 'reason' => 'test reason here', 'confirmed' => 'accepted'])->assertStatus(403);
        $this->postJson('/api/admin/google/oauth/connect')->assertStatus(403);
    }

    // ── Client regression (sampled here; full suite in Batch1ClientPermissionsTest) ──

    public function test_client_routes_are_entirely_unaffected(): void
    {
        $client = $this->makeClient();
        Sanctum::actingAs($client);

        $this->getJson('/api/projects')->assertStatus(200);
    }
}

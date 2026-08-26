<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BrandingSetting;
use App\Models\Contract;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Invited User Organisation Lifecycle audit (2026-08-26) — freezes the
 * CURRENT SAFE CONTRACT so a future schema/query change can't silently
 * weaken it:
 *
 *  - a freshly invited Client legitimately has organization_id = NULL
 *    until they complete onboarding (EXPECTED, not a bug);
 *  - while organization_id is NULL, every tenant-scoped endpoint sampled
 *    in the audit returns empty/403, never another organisation's data;
 *  - OrganizationController::getBranding() must not crash for a null-org
 *    Client (the one real defect the audit found — see Phase 2 fix);
 *  - onboarding is the sole path that assigns organization_id, and does
 *    so correctly;
 *  - Admin/Super Admin's own null organization_id is a separate,
 *    intentional platform-operator state, unaffected by any of the above.
 *
 * This does not test frontend redirect behaviour — only direct API access,
 * per the audit's own instruction that a frontend redirect is not a
 * security boundary.
 */
class InvitedUserOrganisationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function makeClientRole(): Role
    {
        return Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']);
    }

    /**
     * Mirrors the exact state UserController::inviteOneUser() +
     * InvitationService::accept() leave behind for a brand-new invited
     * Client who has accepted their invitation but not yet onboarded:
     * active, verified, real chosen password, Client role, organization_id
     * still null.
     */
    private function makeAcceptedPreOnboardingClient(): User
    {
        $user = User::factory()->create([
            'organization_id'    => null,
            'is_active'          => true,
            'email_verified_at'  => now(),
            'must_change_password' => false,
        ]);
        $user->assignRole($this->makeClientRole());

        return $user;
    }

    private function makeOrgAndProject(string $label): array
    {
        static $n = 0;
        $n++;

        $org = Organization::create(['name' => "{$label} Org {$n}", 'slug' => "org-{$label}-{$n}"]);
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $project = Project::create([
            'organization_id' => $org->id,
            'created_by'      => $owner->id,
            'name'            => "Project for {$org->name}",
            'status'          => 'active',
        ]);

        return compact('org', 'owner', 'project');
    }

    // ── Phase 1 — reconfirm the branding failure (pre-fix behaviour) ────

    public function test_super_admin_gets_default_branding(): void
    {
        $admin = User::factory()->create(['organization_id' => null]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($admin);

        $this->getJson('/api/organization/branding')
            ->assertStatus(200)
            ->assertJsonPath('data.company_name', 'SureSign');
    }

    public function test_admin_gets_default_branding(): void
    {
        $admin = User::factory()->create(['organization_id' => null]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($admin);

        $this->getJson('/api/organization/branding')
            ->assertStatus(200)
            ->assertJsonPath('data.company_name', 'SureSign');
    }

    public function test_client_with_organisation_gets_their_own_branding(): void
    {
        $data = $this->makeOrgAndProject('branded');
        Sanctum::actingAs($data['owner']);

        $this->getJson('/api/organization/branding')
            ->assertStatus(200)
            ->assertJsonPath('data.company_name', $data['org']->name);
    }

    /**
     * The fix under test: before Phase 2, this request threw an uncaught
     * QueryException (branding_settings.organization_id is NOT NULL) and
     * returned a 500. This test proves the null-org Client path now
     * succeeds with the same default resource platform operators get.
     */
    public function test_preonboarding_client_getBranding_returns_safe_default_not_500(): void
    {
        $user = $this->makeAcceptedPreOnboardingClient();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/organization/branding');

        $response->assertStatus(200)
            ->assertJsonPath('data.company_name', 'SureSign');
    }

    public function test_preonboarding_client_getBranding_creates_no_branding_setting_row(): void
    {
        $user = $this->makeAcceptedPreOnboardingClient();
        Sanctum::actingAs($user);

        $this->getJson('/api/organization/branding')->assertStatus(200);

        $this->assertDatabaseCount('branding_settings', 0);
        $this->assertDatabaseMissing('branding_settings', ['organization_id' => null]);
    }

    // ── Phase 3 — pre-onboarding access regression ──────────────────────

    public function test_preonboarding_client_projects_index_returns_no_tenant_projects(): void
    {
        $this->makeOrgAndProject('other');
        $user = $this->makeAcceptedPreOnboardingClient();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/projects');

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('total'));
        $this->assertCount(0, $response->json('data'));
    }

    public function test_preonboarding_client_cannot_access_another_organisations_project(): void
    {
        $other = $this->makeOrgAndProject('foreign');
        $user = $this->makeAcceptedPreOnboardingClient();
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/projects/{$other['project']->id}");

        $response->assertStatus(403);
        $response->assertJsonMissing(['name' => $other['project']->name]);
    }

    public function test_preonboarding_client_cannot_access_contracts_via_another_organisations_project(): void
    {
        $other = $this->makeOrgAndProject('foreign2');
        Contract::create([
            'project_id'      => $other['project']->id,
            'organization_id' => $other['org']->id,
            'created_by'      => $other['owner']->id,
            'type'            => 'main_contract',
            'title'           => 'Foreign Main Contract',
        ]);
        $user = $this->makeAcceptedPreOnboardingClient();
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/projects/{$other['project']->id}/contracts");

        $response->assertStatus(403);
        $response->assertJsonMissing(['title' => 'Foreign Main Contract']);
    }

    public function test_preonboarding_client_cannot_access_documents_via_another_organisations_project(): void
    {
        $other = $this->makeOrgAndProject('foreign3');
        Document::create([
            'project_id'      => $other['project']->id,
            'organization_id' => $other['org']->id,
            'created_by'      => $other['owner']->id,
            'title'           => 'Foreign Document',
            'type'            => 'other',
        ]);
        $user = $this->makeAcceptedPreOnboardingClient();
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/projects/{$other['project']->id}/documents");

        $response->assertStatus(403);
        $response->assertJsonMissing(['title' => 'Foreign Document']);
    }

    public function test_preonboarding_client_dashboard_contains_no_tenant_data(): void
    {
        $this->makeOrgAndProject('dashboard-other');
        $user = $this->makeAcceptedPreOnboardingClient();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('stats.total_projects', 0)
            ->assertJsonPath('stats.active_projects', 0)
            ->assertJsonCount(0, 'recent_projects');
    }

    // ── Phase 4 — onboarding assignment ─────────────────────────────────

    public function test_onboarding_assigns_organisation_and_grants_scoped_access(): void
    {
        $foreign = $this->makeOrgAndProject('after-onboard-foreign');
        $user = $this->makeAcceptedPreOnboardingClient();
        Sanctum::actingAs($user);

        $company = $this->postJson('/api/organization/onboard/company', [
            'name' => 'Newly Onboarded Ltd',
        ]);
        $company->assertStatus(200);

        $user->refresh();
        $this->assertNotNull($user->organization_id);
        $org = Organization::find($user->organization_id);
        $this->assertSame('Newly Onboarded Ltd', $org->name);

        // Their own organisation now has a real project reachable normally.
        $ownProject = Project::create([
            'organization_id' => $org->id,
            'created_by'      => $user->id,
            'name'            => 'My First Project',
            'status'          => 'active',
        ]);
        $this->getJson("/api/projects/{$ownProject->id}")->assertStatus(200);

        // Still cannot reach the pre-existing foreign organisation's project.
        $this->getJson("/api/projects/{$foreign['project']->id}")->assertStatus(403);

        // Finalize and confirm the flag + /auth/me shape.
        $finalize = $this->postJson('/api/organization/onboard/finalize');
        $finalize->assertStatus(200);
        $this->assertTrue($org->fresh()->is_onboarded);

        $me = $this->getJson('/api/auth/me');
        $me->assertStatus(200)
            ->assertJsonPath('organization.id', $org->id)
            ->assertJsonPath('organization.is_onboarded', true);
    }

    // ── Phase 5 — soft-deleted restore semantics (documented, unchanged) ─

    /**
     * Documents CURRENT behaviour only — see the audit's Phase 5
     * classification (SEPARATE PRODUCT DECISION REQUIRED). A removed
     * user's prior organization_id is preserved across a re-invite of the
     * same email; this is not asserted here as correct or incorrect,
     * only as the contract that exists today.
     */
    public function test_reinviting_a_removed_user_preserves_their_prior_organisation_id(): void
    {
        $org = Organization::create(['name' => 'Prior Org', 'slug' => 'prior-org-'.uniqid()]);
        $removed = User::factory()->create([
            'email'           => 'formerly-removed@example.com',
            'organization_id' => $org->id,
        ]);
        $removed->delete(); // soft delete, mirrors UserController::destroy()

        $admin = User::factory()->create(['organization_id' => null]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($admin);

        \Illuminate\Support\Facades\Http::fake(['api.brevo.com/*' => \Illuminate\Support\Facades\Http::response(['messageId' => 'x'], 201)]);
        \App\Models\SuresignSetting::instance()->update([
            'brevo_api_key' => 'fake-brevo-key',
            'email_sender_email' => 'noreply@suresigncontracts.app',
            'support_email' => 'support@suresigncontracts.app',
            'admin_email' => 'admin@suresigncontracts.app',
        ]);

        $response = $this->postJson('/api/users/invite', [
            'email' => 'formerly-removed@example.com',
            'role'  => 'Client',
        ]);

        $response->assertStatus(201);

        $restored = User::where('email', 'formerly-removed@example.com')->first();
        $this->assertNotNull($restored);
        $this->assertNull($restored->deleted_at);
        $this->assertSame($org->id, $restored->organization_id);
    }

    // ── Phase 7 — Admin / Super Admin regression ────────────────────────

    public function test_super_admin_project_wide_access_unchanged(): void
    {
        $data = $this->makeOrgAndProject('platform-check');
        $admin = User::factory()->create(['organization_id' => null]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($admin);

        $this->getJson("/api/projects/{$data['project']->id}")->assertStatus(200);
        $this->getJson('/api/organization/branding')->assertStatus(200)
            ->assertJsonPath('data.company_name', 'SureSign');
    }

    public function test_admin_project_wide_access_unchanged(): void
    {
        $data = $this->makeOrgAndProject('platform-check-admin');
        $admin = User::factory()->create(['organization_id' => null]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($admin);

        $this->getJson("/api/projects/{$data['project']->id}")->assertStatus(200);
        $this->getJson('/api/organization/branding')->assertStatus(200)
            ->assertJsonPath('data.company_name', 'SureSign');
    }
}

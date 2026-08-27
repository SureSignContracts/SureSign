<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\BillingCustomer;
use App\Models\Contract;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Two User Removal Modes — UserController::removeAndDetach()
 * (POST /users/{id}/remove-and-detach). "Remove User" (destroy()/
 * bulkRemove()) is unchanged and covered elsewhere (UserBulkRemoveApiTest,
 * TokenRevocationTest); this file covers ONLY the new "Remove & Detach"
 * action and the invariants the product decision requires of it:
 * organisation/tenant-data/billing preservation, token revocation, the
 * last-Client confirmation gate, eligibility, and the detached-reinvite
 * lifecycle.
 */
class UserRemoveAndDetachApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        $admin = User::factory()->create(['organization_id' => null]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function makeOrgWithClient(string $label): array
    {
        static $n = 0;
        $n++;
        $org = Organization::create(['name' => "{$label} Org {$n}", 'slug' => "detach-org-{$label}-{$n}"]);
        $client = User::factory()->create(['organization_id' => $org->id, 'email' => "{$label}-client-{$n}@example.com"]);
        $client->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        return compact('org', 'client');
    }

    private function fakeBrevo(): void
    {
        \App\Models\SuresignSetting::instance()->update([
            'brevo_api_key' => 'fake-brevo-key',
            'email_sender_email' => 'noreply@suresigncontracts.app',
            'support_email' => 'support@suresigncontracts.app',
            'admin_email' => 'admin@suresigncontracts.app',
        ]);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'fake-message-id'], 201)]);
    }

    // ── Core detach behaviour ────────────────────────────────────────────

    public function test_detach_revokes_tokens_clears_org_and_soft_deletes(): void
    {
        $this->actingAsSuperAdmin();
        $data = $this->makeOrgWithClient('core');
        // A second Client so this isn't the last-Client case.
        $second = User::factory()->create(['organization_id' => $data['org']->id]);
        $second->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        $token = $data['client']->createToken('probe')->plainTextToken;

        $response = $this->postJson("/api/users/{$data['client']->id}/remove-and-detach");

        $response->assertStatus(200);

        $fresh = User::withTrashed()->find($data['client']->id);
        $this->assertNotNull($fresh->deleted_at);
        $this->assertNull($fresh->organization_id);
        $this->assertSame(0, $fresh->tokens()->count());

        // Sanctum::actingAs() (via actingAsSuperAdmin()) fixes the guard to
        // the Super Admin regardless of any Bearer token header — forget it
        // first so this request genuinely re-resolves via the raw token,
        // same convention as TokenRevocationTest/AccountStatusTest.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_detach_leaves_organisation_and_tenant_data_intact(): void
    {
        $this->actingAsSuperAdmin();
        $data = $this->makeOrgWithClient('tenant');
        $second = User::factory()->create(['organization_id' => $data['org']->id]);
        $second->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        $project = Project::create([
            'organization_id' => $data['org']->id,
            'created_by'      => $data['client']->id,
            'name'            => 'Kept Project',
            'status'          => 'active',
        ]);
        $contract = Contract::create([
            'project_id'      => $project->id,
            'organization_id' => $data['org']->id,
            'created_by'      => $data['client']->id,
            'type'            => 'main_contract',
            'title'           => 'Kept Contract',
        ]);
        $document = Document::create([
            'project_id'      => $project->id,
            'organization_id' => $data['org']->id,
            'created_by'      => $data['client']->id,
            'title'           => 'Kept Document',
            'type'            => 'other',
        ]);
        $billing = BillingCustomer::create([
            'organization_id'      => $data['org']->id,
            'provider'              => 'stripe',
            'provider_customer_id'  => 'cus_test123',
        ]);

        $this->postJson("/api/users/{$data['client']->id}/remove-and-detach")->assertStatus(200);

        $this->assertDatabaseHas('organizations', ['id' => $data['org']->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('contracts', ['id' => $contract->id]);
        $this->assertDatabaseHas('documents', ['id' => $document->id]);
        $this->assertDatabaseHas('billing_customers', [
            'id' => $billing->id,
            'organization_id' => $data['org']->id,
            'provider_customer_id' => 'cus_test123',
        ]);
    }

    public function test_activity_log_records_removed_and_detached_with_previous_org_metadata(): void
    {
        $this->actingAsSuperAdmin();
        $data = $this->makeOrgWithClient('audit');
        $second = User::factory()->create(['organization_id' => $data['org']->id]);
        $second->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        $this->postJson("/api/users/{$data['client']->id}/remove-and-detach")->assertStatus(200);

        $log = ActivityLog::where('action', 'user.removed_and_detached')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($data['org']->id, $log->metadata['previous_organization_id']);
        $this->assertSame($data['org']->name, $log->metadata['previous_organization_name']);

        // Option A's own event/message is untouched by this new action.
        $this->assertDatabaseMissing('activity_logs', ['action' => 'user.removed', 'subject_id' => $data['client']->id]);
    }

    // ── Eligibility ──────────────────────────────────────────────────────

    public function test_admin_target_is_rejected(): void
    {
        $this->actingAsSuperAdmin();
        $target = User::factory()->create(['organization_id' => null]);
        $target->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));

        $this->postJson("/api/users/{$target->id}/remove-and-detach")->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'deleted_at' => null]);
    }

    public function test_super_admin_target_is_rejected(): void
    {
        $this->actingAsSuperAdmin();
        $target = User::factory()->create(['organization_id' => null]);
        $target->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        $this->postJson("/api/users/{$target->id}/remove-and-detach")->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'deleted_at' => null]);
    }

    public function test_already_null_org_client_is_rejected(): void
    {
        $this->actingAsSuperAdmin();
        $target = User::factory()->create(['organization_id' => null]);
        $target->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        $this->postJson("/api/users/{$target->id}/remove-and-detach")->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'deleted_at' => null]);
    }

    /**
     * Full Parity Access Expansion (2026-08-26) — this endpoint is
     * reachable by an Admin holding admin.module.users; an Admin WITHOUT
     * it is still forbidden.
     */
    public function test_admin_without_the_users_module_cannot_call_the_endpoint(): void
    {
        $admin = User::factory()->create(['organization_id' => null]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));
        $admin->syncPermissions([]); // strip the listener's own auto-grant
        Sanctum::actingAs($admin);

        $data = $this->makeOrgWithClient('forbidden-admin');

        $this->postJson("/api/users/{$data['client']->id}/remove-and-detach")->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $data['client']->id, 'deleted_at' => null, 'organization_id' => $data['org']->id]);
    }

    public function test_client_role_cannot_call_the_endpoint(): void
    {
        $data = $this->makeOrgWithClient('forbidden-client');
        Sanctum::actingAs($data['client']);

        $other = $this->makeOrgWithClient('forbidden-client-target');

        $this->postJson("/api/users/{$other['client']->id}/remove-and-detach")->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $other['client']->id, 'deleted_at' => null]);
    }

    public function test_cannot_detach_self(): void
    {
        $admin = $this->actingAsSuperAdmin();
        // Self-removal guard mirrors destroy()'s own — exercised via a
        // Client acting on themselves isn't reachable (route requires
        // Super Admin), so this proves the same numeric-id guard applies
        // regardless of the acting Super Admin's own role.
        $this->postJson("/api/users/{$admin->id}/remove-and-detach")->assertStatus(422);
    }

    // ── Last-Client guard ────────────────────────────────────────────────

    public function test_detaching_one_of_two_clients_succeeds_without_confirmation(): void
    {
        $this->actingAsSuperAdmin();
        $data = $this->makeOrgWithClient('two-clients');
        $second = User::factory()->create(['organization_id' => $data['org']->id]);
        $second->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        $this->postJson("/api/users/{$data['client']->id}/remove-and-detach")->assertStatus(200);

        $this->assertNull(User::withTrashed()->find($data['client']->id)->organization_id);
        $this->assertSame($data['org']->id, $second->fresh()->organization_id);
    }

    public function test_detaching_the_last_client_without_confirmation_returns_409_with_zero_mutation(): void
    {
        $this->actingAsSuperAdmin();
        $data = $this->makeOrgWithClient('last-client');
        $token = $data['client']->createToken('probe')->plainTextToken;

        $response = $this->postJson("/api/users/{$data['client']->id}/remove-and-detach");

        $response->assertStatus(409)->assertJsonPath('code', 'LAST_CLIENT_DETACH_REQUIRES_CONFIRMATION');

        $fresh = $data['client']->fresh();
        $this->assertNull($fresh->deleted_at);
        $this->assertSame($data['org']->id, $fresh->organization_id);
        $this->assertSame(1, $fresh->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(200);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'user.removed_and_detached', 'subject_id' => $data['client']->id]);
    }

    public function test_detaching_the_last_client_with_confirmation_succeeds(): void
    {
        $this->actingAsSuperAdmin();
        $data = $this->makeOrgWithClient('last-client-confirmed');

        $response = $this->postJson("/api/users/{$data['client']->id}/remove-and-detach", [
            'confirm_last_client' => true,
        ]);

        $response->assertStatus(200);

        $fresh = User::withTrashed()->find($data['client']->id);
        $this->assertNotNull($fresh->deleted_at);
        $this->assertNull($fresh->organization_id);

        // Organisation, and the fact it now has zero Client users, is fine —
        // never deleted.
        $this->assertDatabaseHas('organizations', ['id' => $data['org']->id, 'deleted_at' => null]);
        $this->assertSame(0, User::role('Client')->where('organization_id', $data['org']->id)->count());
    }

    // ── Detached reinvite lifecycle ──────────────────────────────────────

    public function test_detached_reinvite_stays_null_org_and_old_org_is_not_restored(): void
    {
        $this->fakeBrevo();
        $admin = $this->actingAsSuperAdmin();
        $data = $this->makeOrgWithClient('reinvite');
        $second = User::factory()->create(['organization_id' => $data['org']->id]);
        $second->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        $clientEmail = $data['client']->email;

        $this->postJson("/api/users/{$data['client']->id}/remove-and-detach")->assertStatus(200);

        $this->postJson('/api/users/invite', ['email' => $clientEmail, 'role' => 'Client'])
            ->assertStatus(201);

        $restored = User::where('email', $clientEmail)->first();
        $this->assertNotNull($restored);
        $this->assertNull($restored->deleted_at);
        $this->assertNull($restored->organization_id);

        // Simulate accepted invitation (InvitationService::accept() clears
        // must_change_password/sets email_verified_at) — otherwise
        // EnsurePasswordIsCurrent blocks every route except auth.me/
        // force-password-change/logout regardless of organization_id,
        // which would test that middleware instead of this invariant.
        $restored->forceFill(['must_change_password' => false, 'email_verified_at' => now()])->save();

        // Pre-onboarding tenant access remains blocked, same invariant the
        // Invited User Organisation Lifecycle audit already proved for a
        // fresh invite — never re-derived here, just reconfirmed for the
        // detached-then-reinvited path specifically.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($restored);
        $this->getJson('/api/projects')->assertStatus(200)->assertJsonPath('total', 0);
        $this->getJson("/api/projects/" . Project::create([
            'organization_id' => $data['org']->id,
            'created_by'      => $second->id,
            'name'            => 'Old Org Project',
            'status'          => 'active',
        ])->id)->assertStatus(403);
    }
}

<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BillingCustomer;
use App\Models\Contract;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Self-Service Account Deletion — POST /auth/delete-account
 * (AuthController::deleteAccount()). Deliberately distinct from
 * UserController::destroy() ("Remove User") and ::removeAndDetach()
 * ("Remove & Detach") — see AuthController::deleteAccount()'s own
 * docblock for the exact semantic difference this suite locks in:
 * identity tombstoned/anonymised, original email released for reuse,
 * roles/permissions cleared, all tokens revoked, never a hard delete.
 */
class SelfServiceAccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'CorrectPassw0rd!';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeClient(?Organization $org = null, string $email = 'client@example.com'): User
    {
        $org ??= Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'email'           => $email,
            'password'        => Hash::make(self::PASSWORD),
            'is_active'       => true,
        ]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        return $user;
    }

    private function makeAdmin(string $email = 'admin@example.com', string $role = 'Admin'): User
    {
        $user = User::factory()->create([
            'organization_id' => null,
            'email'           => $email,
            'password'        => Hash::make(self::PASSWORD),
            'is_active'       => true,
        ]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));

        return $user;
    }

    private function deletePayload(array $overrides = []): array
    {
        return array_merge([
            'current_password' => self::PASSWORD,
            'confirmed'         => true,
        ], $overrides);
    }

    // ── Basic success per role ──────────────────────────────────────────

    public function test_client_can_delete_their_own_account(): void
    {
        $org = Organization::create(['name' => 'SoloClientOrg', 'slug' => 'solo-' . uniqid()]);
        $client = $this->makeClient($org);
        // A second Client so this isn't the sole-Client case.
        $second = $this->makeClient($org, 'second@example.com');
        Sanctum::actingAs($client);

        $response = $this->postJson('/api/auth/delete-account', $this->deletePayload());

        $response->assertStatus(200);
        $this->assertSoftDeleted('users', ['id' => $client->id]);
    }

    // ── Self-Service Account Deletion is Client-only ─────────────────────
    // Admin/Super Admin are platform operators and must never be able to
    // self-delete through this endpoint — enforced server-side, not merely
    // by the Settings UI never showing the action for them. Verified via a
    // direct API call, exactly as a curl/Postman/custom-frontend caller
    // would experience it.

    public function test_admin_cannot_self_delete(): void
    {
        $admin = $this->makeAdmin();
        $token = $admin->createToken('probe')->plainTextToken;
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/auth/delete-account', $this->deletePayload());

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'deleted_at' => null, 'email' => $admin->email]);
        $this->assertSame(1, $admin->fresh()->tokens()->count());
        $this->assertCount(1, $admin->fresh()->roles);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'user.self_deleted', 'subject_id' => $admin->id]);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(200);
    }

    public function test_super_admin_cannot_self_delete(): void
    {
        // Two active Super Admins — proves the rejection is role-based,
        // not a last-active-Super-Admin count decision (that invariant no
        // longer applies to this endpoint at all; see
        // AuthController::deleteAccount()'s own docblock).
        $superAdmin = $this->makeAdmin('sa1@example.com', 'Super Admin');
        $this->makeAdmin('sa2@example.com', 'Super Admin');
        $token = $superAdmin->createToken('probe')->plainTextToken;
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/auth/delete-account', $this->deletePayload());

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $superAdmin->id, 'deleted_at' => null, 'email' => 'sa1@example.com']);
        $this->assertSame(1, $superAdmin->fresh()->tokens()->count());
        $this->assertCount(1, $superAdmin->fresh()->roles);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'user.self_deleted', 'subject_id' => $superAdmin->id]);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(200);
    }

    public function test_last_active_super_admin_is_also_rejected_by_role_not_by_count(): void
    {
        // Even the SOLE active Super Admin is rejected — the platform-wide
        // ban on operator self-delete applies regardless of how many
        // Super Admins currently exist. Confirms this endpoint truly never
        // makes a last-active-Super-Admin decision at all.
        $superAdmin = $this->makeAdmin('onlysa@example.com', 'Super Admin');
        $token = $superAdmin->createToken('probe')->plainTextToken;
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/auth/delete-account', $this->deletePayload());

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $superAdmin->id, 'deleted_at' => null, 'email' => 'onlysa@example.com']);
        $this->assertSame(1, $superAdmin->fresh()->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(200);
    }

    // ── Validation ───────────────────────────────────────────────────────

    public function test_current_password_is_required(): void
    {
        $client = $this->makeClient();
        Sanctum::actingAs($client);

        $this->postJson('/api/auth/delete-account', ['confirmed' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
        $this->assertDatabaseHas('users', ['id' => $client->id, 'deleted_at' => null]);
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $client = $this->makeClient();
        Sanctum::actingAs($client);

        $this->postJson('/api/auth/delete-account', ['current_password' => 'WrongPassw0rd!', 'confirmed' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
        $this->assertDatabaseHas('users', ['id' => $client->id, 'deleted_at' => null]);
    }

    public function test_acknowledgement_is_required(): void
    {
        $client = $this->makeClient();
        Sanctum::actingAs($client);

        $this->postJson('/api/auth/delete-account', ['current_password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['confirmed']);
        $this->assertDatabaseHas('users', ['id' => $client->id, 'deleted_at' => null]);
    }

    // ── Token revocation ─────────────────────────────────────────────────

    public function test_all_sanctum_tokens_are_revoked_not_only_the_current_one(): void
    {
        $org = Organization::create(['name' => 'TokensOrg', 'slug' => 'tokens-' . uniqid()]);
        $client = $this->makeClient($org, 'tokens-client@example.com');
        $this->makeClient($org, 'tokens-sibling@example.com');
        $tokenA = $client->createToken('device-a')->plainTextToken;
        $tokenB = $client->createToken('device-b')->plainTextToken;
        Sanctum::actingAs($client);

        $this->postJson('/api/auth/delete-account', $this->deletePayload())->assertStatus(200);

        $this->assertSame(0, User::withTrashed()->find($client->id)->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($tokenA)->getJson('/api/auth/me')->assertStatus(401);
        $this->app['auth']->forgetGuards();
        $this->withToken($tokenB)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_old_token_does_not_resurrect_if_the_row_is_manually_restored(): void
    {
        $org = Organization::create(['name' => 'RestoreOrg', 'slug' => 'restore-' . uniqid()]);
        $client = $this->makeClient($org, 'restore-client@example.com');
        $this->makeClient($org, 'restore-sibling@example.com');
        $token = $client->createToken('probe')->plainTextToken;
        Sanctum::actingAs($client);

        $this->postJson('/api/auth/delete-account', $this->deletePayload())->assertStatus(200);

        User::withTrashed()->find($client->id)->restore();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);
    }

    // ── Anonymisation ────────────────────────────────────────────────────

    public function test_original_email_and_identity_fields_are_anonymised(): void
    {
        $client = User::factory()->create([
            'organization_id' => Organization::create(['name' => 'AnonOrg', 'slug' => 'anon-' . uniqid()])->id,
            'email'           => 'realperson@example.com',
            'name'            => 'Real Person',
            'first_name'      => 'Real',
            'last_name'       => 'Person',
            'phone'           => '+441234567890',
            'address'         => '1 Real Street',
            'city'            => 'Realtown',
            'password'        => Hash::make(self::PASSWORD),
        ]);
        $client->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        // Second client so this isn't the sole-Client path (kept simple —
        // anonymisation itself is what's under test here).
        $second = $client->organization_id;
        $sibling = User::factory()->create(['organization_id' => $second]);
        $sibling->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        Sanctum::actingAs($client);

        $this->postJson('/api/auth/delete-account', $this->deletePayload())->assertStatus(200);

        $fresh = User::withTrashed()->find($client->id);
        $this->assertNotSame('realperson@example.com', $fresh->email);
        $this->assertStringNotContainsString('realperson', $fresh->email);
        $this->assertStringEndsWith('@deleted.invalid', $fresh->email);
        $this->assertStringStartsWith('deleted-', $fresh->email);
        $this->assertNotSame('Real Person', $fresh->name);
        $this->assertNull($fresh->first_name);
        $this->assertNull($fresh->last_name);
        $this->assertNull($fresh->phone);
        $this->assertNull($fresh->address);
        $this->assertNull($fresh->city);
        $this->assertNull($fresh->organization_id);
    }

    public function test_roles_and_permissions_are_cleared_on_self_delete(): void
    {
        $org = Organization::create(['name' => 'RoleClearOrg', 'slug' => 'roleclear-' . uniqid()]);
        $client = $this->makeClient($org, 'roleclear-client@example.com');
        $this->makeClient($org, 'roleclear-sibling@example.com');
        Sanctum::actingAs($client);

        $this->postJson('/api/auth/delete-account', $this->deletePayload())->assertStatus(200);

        $fresh = User::withTrashed()->find($client->id);
        $this->assertCount(0, $fresh->roles);
        $this->assertCount(0, $fresh->permissions);
    }

    // ── Login after delete ───────────────────────────────────────────────

    public function test_original_email_cannot_login_after_deletion(): void
    {
        $org = Organization::create(['name' => 'LoginOrg', 'slug' => 'login-' . uniqid()]);
        $client = $this->makeClient($org, 'loginme@example.com');
        $this->makeClient($org, 'sibling-login@example.com');
        Sanctum::actingAs($client);

        $this->postJson('/api/auth/delete-account', $this->deletePayload())->assertStatus(200);

        $this->app['auth']->forgetGuards();
        $response = $this->postJson('/api/auth/login', ['email' => 'loginme@example.com', 'password' => self::PASSWORD]);

        // Same generic, account-enumeration-safe wording every other wrong
        // login attempt gets — never a distinct "this account was deleted"
        // message.
        $response->assertStatus(401)->assertJson(['message' => 'The email or password is incorrect.']);
    }

    // ── Tenant / billing preservation ────────────────────────────────────

    public function test_organisation_and_tenant_data_and_billing_survive(): void
    {
        $org = Organization::create(['name' => 'SurvivorOrg', 'slug' => 'survivor-' . uniqid()]);
        $client = $this->makeClient($org, 'survivor-client@example.com');
        $sibling = $this->makeClient($org, 'survivor-sibling@example.com');
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $client->id, 'name' => 'Survivor Project', 'status' => 'active']);
        $contract = Contract::create(['project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $client->id, 'type' => 'main_contract', 'title' => 'Survivor Contract']);
        $document = Document::create(['project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $client->id, 'title' => 'Survivor Document', 'type' => 'other']);
        $billing = BillingCustomer::create(['organization_id' => $org->id, 'provider' => 'stripe', 'provider_customer_id' => 'cus_survivor']);

        Sanctum::actingAs($client);
        $this->postJson('/api/auth/delete-account', $this->deletePayload())->assertStatus(200);

        $this->assertDatabaseHas('organizations', ['id' => $org->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('contracts', ['id' => $contract->id]);
        $this->assertDatabaseHas('documents', ['id' => $document->id]);
        $this->assertDatabaseHas('billing_customers', ['id' => $billing->id, 'organization_id' => $org->id]);
        $this->assertDatabaseHas('users', ['id' => $sibling->id, 'deleted_at' => null, 'organization_id' => $org->id]);
    }

    // ── Sole-Client guard ────────────────────────────────────────────────

    public function test_sole_client_delete_without_confirmation_returns_409_with_zero_mutation(): void
    {
        $org = Organization::create(['name' => 'SoleOrg', 'slug' => 'sole-' . uniqid()]);
        $client = $this->makeClient($org, 'sole@example.com');
        $token = $client->createToken('probe')->plainTextToken;
        Sanctum::actingAs($client);

        $response = $this->postJson('/api/auth/delete-account', $this->deletePayload());

        $response->assertStatus(409)->assertJsonPath('code', 'LAST_CLIENT_ACCOUNT_DELETE_REQUIRES_CONFIRMATION');

        $fresh = $client->fresh();
        $this->assertNull($fresh->deleted_at);
        $this->assertSame($org->id, $fresh->organization_id);
        $this->assertSame(1, $fresh->tokens()->count());
        $this->assertDatabaseMissing('activity_logs', ['action' => 'user.self_deleted', 'subject_id' => $client->id]);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(200);
    }

    public function test_sole_client_delete_with_confirmation_succeeds(): void
    {
        $org = Organization::create(['name' => 'SoleConfirmedOrg', 'slug' => 'sole-confirmed-' . uniqid()]);
        $client = $this->makeClient($org, 'sole-confirmed@example.com');
        Sanctum::actingAs($client);

        $response = $this->postJson('/api/auth/delete-account', $this->deletePayload(['confirm_last_client' => true]));

        $response->assertStatus(200);
        $fresh = User::withTrashed()->find($client->id);
        $this->assertNotNull($fresh->deleted_at);
        $this->assertNull($fresh->organization_id);
        $this->assertDatabaseHas('organizations', ['id' => $org->id, 'deleted_at' => null]);
        $this->assertSame(0, User::role('Client')->where('organization_id', $org->id)->count());
    }

    // ── Reinvite guarantee ───────────────────────────────────────────────

    public function test_reinviting_the_original_email_creates_a_brand_new_user_row(): void
    {
        \App\Models\SuresignSetting::instance()->update([
            'brevo_api_key' => 'fake-brevo-key',
            'email_sender_email' => 'noreply@suresigncontracts.app',
            'support_email' => 'support@suresigncontracts.app',
            'admin_email' => 'admin@suresigncontracts.app',
        ]);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'x'], 201)]);

        $org = Organization::create(['name' => 'ReinviteOrg', 'slug' => 'reinvite-' . uniqid()]);
        $client = $this->makeClient($org, 'reinvite-me@example.com');
        $this->makeClient($org, 'reinvite-sibling@example.com');
        $oldId = $client->id;
        Sanctum::actingAs($client);
        $this->postJson('/api/auth/delete-account', $this->deletePayload())->assertStatus(200);

        $admin = $this->makeAdmin('reinvite-admin@example.com', 'Super Admin');
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin);

        $this->postJson('/api/users/invite', ['email' => 'reinvite-me@example.com', 'role' => 'Client'])
            ->assertStatus(201);

        $newUser = User::where('email', 'reinvite-me@example.com')->first();
        $this->assertNotNull($newUser);
        $this->assertNotSame($oldId, $newUser->id);
        // Follows the normal fresh-invite lifecycle — null-org until onboarding.
        $this->assertNull($newUser->organization_id);

        $tombstoned = User::withTrashed()->find($oldId);
        $this->assertNotNull($tombstoned->deleted_at);
        $this->assertStringEndsWith('@deleted.invalid', $tombstoned->email);
    }

    // ── ActivityLog ──────────────────────────────────────────────────────

    public function test_activity_log_records_self_deletion_without_the_original_email(): void
    {
        $org = Organization::create(['name' => 'AuditOrg', 'slug' => 'audit-' . uniqid()]);
        $client = $this->makeClient($org, 'audited-person@example.com');
        $this->makeClient($org, 'audit-sibling@example.com');
        Sanctum::actingAs($client);

        $this->postJson('/api/auth/delete-account', $this->deletePayload())->assertStatus(200);

        $log = ActivityLog::where('action', 'user.self_deleted')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('audited-person', $log->description);
        $this->assertStringNotContainsString('audited-person', json_encode($log->metadata));
        $this->assertSame('Client', $log->metadata['role_at_deletion']);
        $this->assertFalse($log->metadata['was_last_client']);
        $this->assertSame($org->id, $log->metadata['organization_id']);
    }
}

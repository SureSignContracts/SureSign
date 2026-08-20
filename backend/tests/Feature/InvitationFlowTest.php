<?php

namespace Tests\Feature;

use App\Jobs\SendEmailVerificationJob;
use App\Jobs\SendInvitationEmailJob;
use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\SuresignSetting;
use App\Models\User;
use App\Services\InvitationLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Invitation & First-Time Account Setup phase — covers UserController::invite(),
 * InvitationService (send + atomic accept), and the signed public
 * /public/invitations/{user} endpoints. Standard self-registration
 * verification (EmailVerificationService/AccountEmailService/
 * SendEmailVerificationJob) is deliberately exercised here too, only to
 * prove the two flows never cross.
 */
class InvitationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SuresignSetting::instance()->update([
            'brevo_api_key' => 'fake-brevo-key',
            'email_sender_email' => 'noreply@suresigncontracts.app',
            'support_email' => 'support@suresigncontracts.app',
            'admin_email' => 'admin@suresigncontracts.app',
        ]);
    }

    private function fakeBrevo(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'fake-message-id'], 201)]);
    }

    private function actingAsSuperAdmin(): User
    {
        $admin = User::factory()->create(['email' => 'super-admin@example.com']);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function signedInvitationUrls(User $user): array
    {
        $apiUrl = app(InvitationLinkService::class)->apiUrl($user);
        $query = [];
        parse_str((string) parse_url($apiUrl, PHP_URL_QUERY), $query);

        return [
            'path'  => "/api/public/invitations/{$user->id}",
            'query' => $query,
        ];
    }

    // ── Sending the invitation ──────────────────────────────────────────

    public function test_super_admin_can_invite_a_new_user_by_email_only(): void
    {
        $this->fakeBrevo();
        Bus::fake();
        $this->actingAsSuperAdmin();

        $response = $this->postJson('/api/users/invite', [
            'email' => 'invitee@example.com',
            'role'  => 'Client',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', ['email' => 'invitee@example.com', 'is_active' => true]);
    }

    public function test_invitation_uses_dedicated_invitation_job_not_standard_verification_job(): void
    {
        $this->fakeBrevo();
        Bus::fake();
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users/invite', ['email' => 'invitee2@example.com', 'role' => 'Client'])
            ->assertStatus(201);

        Bus::assertDispatched(SendInvitationEmailJob::class, fn ($job) => $job->email === 'invitee2@example.com');
        Bus::assertNotDispatched(SendEmailVerificationJob::class);
    }

    public function test_self_registration_still_uses_standard_verification_job_not_invitation_job(): void
    {
        Bus::fake();
        $user = User::factory()->create(['email' => 'self-reg@example.com']);

        \App\Services\EmailVerificationService::sendVerificationLink($user);

        Bus::assertDispatched(SendEmailVerificationJob::class, fn ($job) => $job->email === 'self-reg@example.com');
        Bus::assertNotDispatched(SendInvitationEmailJob::class);
    }

    public function test_invitation_api_response_never_contains_temp_password(): void
    {
        $this->fakeBrevo();
        Bus::fake();
        $this->actingAsSuperAdmin();

        $response = $this->postJson('/api/users/invite', ['email' => 'nopass@example.com', 'role' => 'Client']);

        $response->assertStatus(201)->assertJsonMissing(['temp_password']);
        $this->assertStringNotContainsString('temp_password', $response->getContent());
    }

    public function test_invitation_email_content_contains_no_generated_password(): void
    {
        $this->fakeBrevo();
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users/invite', ['email' => 'emailcheck@example.com', 'role' => 'Client'])
            ->assertStatus(201);

        $user = User::where('email', 'emailcheck@example.com')->first();
        $rawHash = $user->password;

        Http::assertSent(function ($request) use ($rawHash) {
            $body = $request->data();
            return str_contains($body['subject'], "You've been invited to SureSign")
                && str_contains($body['htmlContent'], 'Accept Invitation &amp; Set Up Account')
                && !str_contains($body['htmlContent'], $rawHash);
        });
    }

    public function test_internal_compatibility_password_is_hashed(): void
    {
        $this->fakeBrevo();
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users/invite', ['email' => 'hashcheck@example.com', 'role' => 'Client'])
            ->assertStatus(201);

        $user = User::where('email', 'hashcheck@example.com')->first();
        $this->assertNotEquals('password', $user->password);
        $this->assertTrue(Hash::needsRehash($user->password) === false || strlen($user->password) > 20);
    }

    public function test_missing_name_produces_no_email_derived_display_name(): void
    {
        $this->fakeBrevo();
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users/invite', ['email' => 'jamescarlo.romero@example.com', 'role' => 'Client'])
            ->assertStatus(201);

        $user = User::where('email', 'jamescarlo.romero@example.com')->first();
        $this->assertNull($user->first_name);
        $this->assertNotEquals('jamescarlo.romero', $user->name);
    }

    public function test_invitation_email_greets_generic_when_no_genuine_name_exists(): void
    {
        $this->fakeBrevo();
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users/invite', ['email' => 'greeting@example.com', 'role' => 'Client'])
            ->assertStatus(201);

        Http::assertSent(fn ($request) => str_contains($request->data()['htmlContent'], 'Hi,'));
    }

    public function test_role_assignment_is_preserved(): void
    {
        $this->fakeBrevo();
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users/invite', ['email' => 'roletest@example.com', 'role' => 'Admin'])
            ->assertStatus(201);

        $user = User::where('email', 'roletest@example.com')->first();
        $this->assertTrue($user->hasRole('Admin'));
    }

    public function test_audit_record_created_without_secret_data(): void
    {
        $this->fakeBrevo();
        $admin = $this->actingAsSuperAdmin();

        $this->postJson('/api/users/invite', ['email' => 'audited@example.com', 'role' => 'Client'])
            ->assertStatus(201);

        $log = ActivityLog::where('action', 'user.invited')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('password', strtolower(json_encode($log->metadata)));
    }

    public function test_existing_active_email_rejected_as_duplicate(): void
    {
        $this->fakeBrevo();
        $this->actingAsSuperAdmin();
        User::factory()->create(['email' => 'already-active@example.com']);

        $this->postJson('/api/users/invite', ['email' => 'already-active@example.com', 'role' => 'Client'])
            ->assertStatus(422);
    }

    // ── Accepting the invitation ─────────────────────────────────────────

    public function test_invitation_link_resolves_correct_user_and_shows_setup_details(): void
    {
        $user = User::factory()->create(['email' => 'target@example.com', 'email_verified_at' => null]);
        $urls = $this->signedInvitationUrls($user);

        $response = $this->getJson($urls['path'] . '?' . http_build_query($urls['query']));

        $response->assertStatus(200)
            ->assertJsonPath('data.email', 'target@example.com')
            ->assertJsonPath('data.already_accepted', false);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $response = $this->getJson("/api/public/invitations/{$user->id}?expires=9999999999&signature=not-a-real-signature");

        $response->assertStatus(403);
    }

    public function test_expired_invitation_link_is_rejected(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $expiredUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'invitations.show',
            now()->subMinute(),
            ['user' => $user->id],
        );
        $query = [];
        parse_str((string) parse_url($expiredUrl, PHP_URL_QUERY), $query);

        $response = $this->getJson("/api/public/invitations/{$user->id}?" . http_build_query($query));

        $response->assertStatus(403);
    }

    public function test_viewing_the_setup_page_does_not_consume_the_invitation(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $urls = $this->signedInvitationUrls($user);

        $this->getJson($urls['path'] . '?' . http_build_query($urls['query']))->assertStatus(200);
        // A second read of the exact same link must still work — the link
        // is only consumed by a successful accept().
        $this->getJson($urls['path'] . '?' . http_build_query($urls['query']))->assertStatus(200);

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_valid_acceptance_sets_recipient_chosen_password_and_verifies_email(): void
    {
        $user = User::factory()->create(['email_verified_at' => null, 'must_change_password' => true]);
        $urls = $this->signedInvitationUrls($user);

        $response = $this->postJson($urls['path'] . '?' . http_build_query($urls['query']), [
            'password' => 'MyNewPassphrase1!',
            'password_confirmation' => 'MyNewPassphrase1!',
        ]);

        $response->assertStatus(200);
        $fresh = $user->fresh();
        $this->assertNotNull($fresh->email_verified_at);
        $this->assertFalse($fresh->must_change_password);
        $this->assertTrue(Hash::check('MyNewPassphrase1!', $fresh->password));
    }

    public function test_password_confirmation_mismatch_rejected(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $urls = $this->signedInvitationUrls($user);

        $response = $this->postJson($urls['path'] . '?' . http_build_query($urls['query']), [
            'password' => 'MyNewPassphrase1!',
            'password_confirmation' => 'Different1!',
        ]);

        $response->assertStatus(422);
    }

    public function test_weak_password_rejected_by_existing_policy(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $urls = $this->signedInvitationUrls($user);

        $response = $this->postJson($urls['path'] . '?' . http_build_query($urls['query']), [
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ]);

        $response->assertStatus(422);
    }

    public function test_accepted_invitation_cannot_be_used_again(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $urls = $this->signedInvitationUrls($user);

        $this->postJson($urls['path'] . '?' . http_build_query($urls['query']), [
            'password' => 'MyNewPassphrase1!',
            'password_confirmation' => 'MyNewPassphrase1!',
        ])->assertStatus(200);

        $response = $this->postJson($urls['path'] . '?' . http_build_query($urls['query']), [
            'password' => 'AnotherPassphrase1!',
            'password_confirmation' => 'AnotherPassphrase1!',
        ]);

        $response->assertStatus(409)->assertJsonPath('code', 'invitation_already_accepted');
        // The first chosen password must still be the one that works.
        $this->assertTrue(Hash::check('MyNewPassphrase1!', $user->fresh()->password));
    }

    public function test_already_accepted_invitation_shown_as_already_accepted_on_view(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $urls = $this->signedInvitationUrls($user);

        $response = $this->getJson($urls['path'] . '?' . http_build_query($urls['query']));

        $response->assertStatus(200)->assertJsonPath('data.already_accepted', true);
    }

    public function test_signature_for_one_user_cannot_be_reused_for_another_user(): void
    {
        $userA = User::factory()->create(['email_verified_at' => null]);
        $userB = User::factory()->create(['email_verified_at' => null]);
        $urls = $this->signedInvitationUrls($userA);

        // Swap the path's user id but keep userA's signature/expires — this
        // must fail, since Laravel's signature is computed over the full
        // URL including the {user} route parameter.
        $response = $this->getJson("/api/public/invitations/{$userB->id}?" . http_build_query($urls['query']));

        $response->assertStatus(403);
    }

    public function test_organization_context_appears_only_when_authoritative(): void
    {
        $org = Organization::create(['name' => 'Acme Construction', 'slug' => 'acme-'.uniqid()]);
        $userWithOrg = User::factory()->create(['email_verified_at' => null, 'organization_id' => $org->id]);
        $userWithoutOrg = User::factory()->create(['email_verified_at' => null, 'organization_id' => null]);

        $urlsWith = $this->signedInvitationUrls($userWithOrg);
        $urlsWithout = $this->signedInvitationUrls($userWithoutOrg);

        $this->getJson($urlsWith['path'] . '?' . http_build_query($urlsWith['query']))
            ->assertJsonPath('data.organization_name', 'Acme Construction');
        $this->getJson($urlsWithout['path'] . '?' . http_build_query($urlsWithout['query']))
            ->assertJsonPath('data.organization_name', null);
    }

    public function test_normal_login_works_after_invitation_acceptance(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $urls = $this->signedInvitationUrls($user);

        $this->postJson($urls['path'] . '?' . http_build_query($urls['query']), [
            'password' => 'MyNewPassphrase1!',
            'password_confirmation' => 'MyNewPassphrase1!',
        ])->assertStatus(200);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'MyNewPassphrase1!',
        ])->assertStatus(200)->assertJsonStructure(['token', 'user']);
    }
}

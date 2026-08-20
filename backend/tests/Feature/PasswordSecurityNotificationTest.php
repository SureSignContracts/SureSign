<?php

namespace Tests\Feature;

use App\Jobs\SendPasswordSecurityNotificationJob;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Unified Password Security Hardening — the post-mutation security
 * notification: exactly-once dispatch on success, none on failure, never
 * a password in the payload, and email-delivery failure never turning a
 * successful password mutation into an ambiguous/failed response.
 *
 * Uses `Bus::fake()` (dispatch assertions), never a live mail provider —
 * `SendPasswordSecurityNotificationJob`'s own handle() is exercised
 * separately, in the "delivery failure" tests below, via a real
 * `EmailNotificationService::sendDirect()` failure path instead of a
 * live network call.
 */
class PasswordSecurityNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserAndOrg(string $email, string $password = 'theExistingPassphrase'): array
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-'.uniqid(), 'timezone' => 'Europe/London']);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'email' => $email,
            'password' => Hash::make($password),
            'is_active' => true,
        ]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        return [$user, $org];
    }

    // ── Self-service Change Password ────────────────────────────────────

    public function test_successful_self_service_change_dispatches_exactly_one_changed_notification(): void
    {
        Bus::fake();
        [$user] = $this->makeUserAndOrg('change1@example.com');
        Sanctum::actingAs($user);

        $this->putJson('/api/auth/password', [
            'current_password' => 'theExistingPassphrase',
            'password' => 'aBrandNewLongPassphrase',
            'password_confirmation' => 'aBrandNewLongPassphrase',
        ])->assertStatus(200);

        Bus::assertDispatched(SendPasswordSecurityNotificationJob::class, function ($job) use ($user) {
            return $job->email === $user->email && $job->type === 'changed';
        });
        Bus::assertDispatchedTimes(SendPasswordSecurityNotificationJob::class, 1);
    }

    public function test_wrong_current_password_sends_no_notification(): void
    {
        Bus::fake();
        [$user] = $this->makeUserAndOrg('change2@example.com');
        Sanctum::actingAs($user);

        $this->putJson('/api/auth/password', [
            'current_password' => 'totallyWrongPassphrase',
            'password' => 'aBrandNewLongPassphrase',
            'password_confirmation' => 'aBrandNewLongPassphrase',
        ])->assertStatus(422);

        Bus::assertNotDispatched(SendPasswordSecurityNotificationJob::class);
    }

    public function test_same_as_current_password_rejected_and_sends_no_notification(): void
    {
        Bus::fake();
        [$user] = $this->makeUserAndOrg('change3@example.com');
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/auth/password', [
            'current_password' => 'theExistingPassphrase',
            'password' => 'theExistingPassphrase',
            'password_confirmation' => 'theExistingPassphrase',
        ]);

        $response->assertStatus(422);
        Bus::assertNotDispatched(SendPasswordSecurityNotificationJob::class);
    }

    public function test_validation_failure_sends_no_notification(): void
    {
        Bus::fake();
        [$user] = $this->makeUserAndOrg('change4@example.com');
        Sanctum::actingAs($user);

        $this->putJson('/api/auth/password', [
            'current_password' => 'theExistingPassphrase',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422);

        Bus::assertNotDispatched(SendPasswordSecurityNotificationJob::class);
    }

    // ── Forced password change ──────────────────────────────────────────

    public function test_successful_forced_change_sends_exactly_one_changed_notification(): void
    {
        Bus::fake();
        [$user] = $this->makeUserAndOrg('force1@example.com');
        $user->update(['must_change_password' => true]);
        Sanctum::actingAs($user);

        $this->putJson('/api/auth/force-password-change', [
            'password' => 'aBrandNewLongPassphrase',
            'password_confirmation' => 'aBrandNewLongPassphrase',
        ])->assertStatus(200);

        Bus::assertDispatchedTimes(SendPasswordSecurityNotificationJob::class, 1);
        Bus::assertDispatched(SendPasswordSecurityNotificationJob::class, fn ($job) => $job->type === 'changed');
    }

    public function test_forced_change_rejects_same_as_current_password(): void
    {
        Bus::fake();
        [$user] = $this->makeUserAndOrg('force2@example.com');
        $user->update(['must_change_password' => true]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/auth/force-password-change', [
            'password' => 'theExistingPassphrase',
            'password_confirmation' => 'theExistingPassphrase',
        ]);

        $response->assertStatus(422);
        Bus::assertNotDispatched(SendPasswordSecurityNotificationJob::class);
    }

    // ── Password reset ──────────────────────────────────────────────────

    public function test_successful_reset_revokes_all_tokens_and_sends_exactly_one_reset_notification(): void
    {
        Bus::fake();
        [$user] = $this->makeUserAndOrg('reset1@example.com');
        $user->createToken('device-a');
        $user->createToken('device-b');
        $this->assertSame(2, $user->tokens()->count());

        $token = \Illuminate\Support\Facades\Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'aFreshResetPassphrase',
            'password_confirmation' => 'aFreshResetPassphrase',
        ])->assertStatus(200);

        $this->assertSame(0, $user->fresh()->tokens()->count());
        Bus::assertDispatchedTimes(SendPasswordSecurityNotificationJob::class, 1);
        Bus::assertDispatched(SendPasswordSecurityNotificationJob::class, fn ($job) => $job->type === 'reset' && $job->email === $user->email);
    }

    public function test_reset_link_email_and_post_reset_notification_are_distinct(): void
    {
        // The pre-reset reset-LINK email (sendPasswordReset()) and the
        // post-reset security NOTIFICATION (sendPasswordResetSecurityNotification())
        // must never be conflated into the same dispatch — requesting a
        // reset link must not itself fire the "your password was reset"
        // notification, since nothing has actually changed yet.
        Bus::fake();
        [$user] = $this->makeUserAndOrg('reset2@example.com');

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertStatus(200);

        Bus::assertDispatched(\App\Jobs\SendPasswordResetEmailJob::class);
        Bus::assertNotDispatched(SendPasswordSecurityNotificationJob::class);
    }

    // ── Admin setPassword ────────────────────────────────────────────────

    public function test_admin_set_password_notifies_target_user_not_the_admin(): void
    {
        Bus::fake();
        [$user] = $this->makeUserAndOrg('target1@example.com');
        $admin = User::factory()->create(['email' => 'admin1@example.com', 'is_active' => true]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($admin);

        $this->postJson("/api/users/{$user->id}/set-password", [
            'password' => 'anAdminChosenLongPassphrase',
        ])->assertStatus(200);

        Bus::assertDispatchedTimes(SendPasswordSecurityNotificationJob::class, 1);
        Bus::assertDispatched(SendPasswordSecurityNotificationJob::class, function ($job) use ($user, $admin) {
            return $job->type === 'admin_changed' && $job->email === $user->email && $job->email !== $admin->email;
        });
    }

    public function test_admin_set_password_rejects_same_as_current(): void
    {
        Bus::fake();
        [$user] = $this->makeUserAndOrg('target2@example.com');
        $admin = User::factory()->create(['email' => 'admin2@example.com', 'is_active' => true]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/users/{$user->id}/set-password", [
            'password' => 'theExistingPassphrase',
        ]);

        $response->assertStatus(422);
        Bus::assertNotDispatched(SendPasswordSecurityNotificationJob::class);
    }

    // ── Invitation acceptance — no misleading "changed" email ──────────

    public function test_invitation_acceptance_sends_no_password_changed_notification(): void
    {
        Bus::fake();
        $user = User::factory()->create(['email_verified_at' => null]);

        // Same signed-URL construction InvitationFlowTest itself uses —
        // App\Services\Organizations\InvitationLinkService::apiUrl().
        $apiUrl = app(\App\Services\InvitationLinkService::class)->apiUrl($user);
        $query = [];
        parse_str((string) parse_url($apiUrl, PHP_URL_QUERY), $query);

        $this->postJson('/api/public/invitations/' . $user->id . '?' . http_build_query($query), [
            'password' => 'myFirstChosenPassphrase',
            'password_confirmation' => 'myFirstChosenPassphrase',
        ])->assertStatus(200);

        Bus::assertNotDispatched(SendPasswordSecurityNotificationJob::class);
    }

    // ── No password ever in any notification ────────────────────────────

    public function test_no_password_field_ever_appears_in_the_dispatched_job(): void
    {
        Bus::fake();
        [$user] = $this->makeUserAndOrg('nopass1@example.com');
        Sanctum::actingAs($user);

        $this->putJson('/api/auth/password', [
            'current_password' => 'theExistingPassphrase',
            'password' => 'aBrandNewLongPassphrase',
            'password_confirmation' => 'aBrandNewLongPassphrase',
        ])->assertStatus(200);

        Bus::assertDispatched(SendPasswordSecurityNotificationJob::class, function ($job) {
            $serialized = serialize($job);
            return !str_contains($serialized, 'aBrandNewLongPassphrase')
                && !str_contains($serialized, 'theExistingPassphrase');
        });
    }

    // ── Email failure must never make a successful mutation look failed ──

    public function test_email_delivery_failure_does_not_undo_or_fail_the_password_change_response(): void
    {
        // Simulate the underlying mail provider failing — the password
        // write has ALREADY committed by the time the job would run;
        // this proves the HTTP response for the mutation itself is
        // unaffected by dispatching a notification job at all (queued via
        // afterCommit(), not awaited synchronously).
        Http::fake(fn () => Http::response('mail provider down', 500));
        [$user] = $this->makeUserAndOrg('emailfail1@example.com');
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/auth/password', [
            'current_password' => 'theExistingPassphrase',
            'password' => 'aBrandNewLongPassphrase',
            'password_confirmation' => 'aBrandNewLongPassphrase',
        ]);

        $response->assertStatus(200);
        $this->assertTrue(Hash::check('aBrandNewLongPassphrase', $user->fresh()->password), 'Password must have actually changed regardless of mail provider state.');
    }

    /**
     * A user with no organisation at all — an edge case
     * TimezoneResolver::effectiveTimezone() is documented to fail safe
     * through (user → organisation → platform → hard UTC fallback) — must
     * still complete the password change and dispatch successfully,
     * never throwing partway through timezone resolution.
     */
    public function test_notifier_handles_a_user_with_no_organisation_without_failing_the_request(): void
    {
        Bus::fake();
        $user = User::factory()->create([
            'organization_id' => null,
            'password' => Hash::make('theExistingPassphrase'),
            'is_active' => true,
        ]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/auth/password', [
            'current_password' => 'theExistingPassphrase',
            'password' => 'aBrandNewLongPassphrase',
            'password_confirmation' => 'aBrandNewLongPassphrase',
        ]);

        $response->assertStatus(200);
        $this->assertTrue(Hash::check('aBrandNewLongPassphrase', $user->fresh()->password));
        Bus::assertDispatchedTimes(SendPasswordSecurityNotificationJob::class, 1);
    }
}

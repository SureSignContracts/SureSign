<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\GoogleConnection;
use App\Models\Organization;
use App\Models\User;
use App\Services\Calendar\CalendarProviderInterface;
use App\Services\Calendar\FakeCalendarProvider;
use App\Services\Google\FakeGoogleApiClient;
use App\Services\Google\GoogleApiClientInterface;
use App\Services\Google\GoogleConnectionService;
use App\Services\Google\GoogleHealthService;
use App\Services\Google\GoogleIntegrationReadinessService;
use App\Services\Google\GoogleOAuthService;
use App\Services\Google\GoogleTokenRefreshService;
use App\Support\Google\GoogleConnectionHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Consultancy Live Booking Upgrade, Stage 4A — Google Integration
 * Foundation. Covers OAuth lifecycle, encrypted token storage, lazy
 * refresh, disconnect, the multi-state health model, readiness, and
 * diagnostics authorization.
 *
 * IMPORTANT: every test here runs entirely against FakeGoogleApiClient/
 * FakeCalendarProvider (bound by GoogleServiceProvider whenever
 * app()->environment('testing') is true — see that provider). No test in
 * this file makes, or could make, a real HTTP call to Google. This proves
 * the OAuth/token/health STATE MACHINE logic is correct; it does NOT and
 * cannot prove that the real google/apiclient SDK integration
 * (GoogleClientAdapter) behaves identically against live Google — that
 * remains unverified until a real Google Cloud OAuth client is configured
 * and exercised manually. See
 * internal-docs/super-admin/google-integration.md's validation-boundary
 * section.
 */
class GoogleIntegrationFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgAndUser(string $role): User
    {
        static $n = 0;
        $n++;
        $org = Organization::create(['name' => "Org {$n}", 'slug' => "org-{$n}", 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));

        return $user;
    }

    private function fakeClient(): FakeGoogleApiClient
    {
        return app(GoogleApiClientInterface::class);
    }

    // ── OAuth lifecycle ──────────────────────────────────────────────────

    public function test_build_authorization_url_returns_state_and_url(): void
    {
        $admin = $this->makeOrgAndUser('Super Admin');

        $result = app(GoogleOAuthService::class)->buildAuthorizationUrl($admin);

        $this->assertNotEmpty($result['state']);
        $this->assertStringContainsString('state=' . $result['state'], $result['url']);
    }

    public function test_complete_connection_persists_connection_with_claims_and_scopes(): void
    {
        $admin = $this->makeOrgAndUser('Super Admin');
        $fake = $this->fakeClient();

        $built = app(GoogleOAuthService::class)->buildAuthorizationUrl($admin);
        $fake->pendingCodes['auth-code-1'] = [
            'access_token'  => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_in'    => 3600,
            'scope'         => 'https://www.googleapis.com/auth/calendar.events openid email',
            'id_token'      => 'idtok-1',
        ];
        $fake->idTokenClaims['idtok-1'] = ['sub' => 'google-sub-1', 'email' => 'consultant@example.com'];

        $connection = app(GoogleOAuthService::class)->completeConnection('auth-code-1', $built['state'], $admin);

        $this->assertSame('connected', $connection->status);
        $this->assertSame('consultant@example.com', $connection->connected_email);
        $this->assertSame('google-sub-1', $connection->google_account_id);
        $this->assertSame('access-1', $connection->access_token);
        $this->assertSame('refresh-1', $connection->refresh_token);
        $this->assertContains('https://www.googleapis.com/auth/calendar.events', $connection->scopes);
        $this->assertContains('openid', $connection->scopes);
    }

    public function test_completing_connection_with_unknown_state_is_rejected(): void
    {
        $admin = $this->makeOrgAndUser('Super Admin');

        $this->expectException(\RuntimeException::class);
        app(GoogleOAuthService::class)->completeConnection('any-code', 'nonexistent-state', $admin);
    }

    public function test_state_cannot_be_replayed(): void
    {
        $admin = $this->makeOrgAndUser('Super Admin');
        $fake = $this->fakeClient();

        $built = app(GoogleOAuthService::class)->buildAuthorizationUrl($admin);
        $fake->pendingCodes['code-a'] = ['access_token' => 'a1', 'expires_in' => 3600, 'scope' => 'https://www.googleapis.com/auth/calendar.events'];
        $fake->pendingCodes['code-b'] = ['access_token' => 'a2', 'expires_in' => 3600, 'scope' => 'https://www.googleapis.com/auth/calendar.events'];

        app(GoogleOAuthService::class)->completeConnection('code-a', $built['state'], $admin);

        $this->expectException(\RuntimeException::class);
        app(GoogleOAuthService::class)->completeConnection('code-b', $built['state'], $admin);
    }

    public function test_rejected_authorization_code_throws(): void
    {
        $admin = $this->makeOrgAndUser('Super Admin');
        $built = app(GoogleOAuthService::class)->buildAuthorizationUrl($admin);

        $this->expectException(\RuntimeException::class);
        app(GoogleOAuthService::class)->completeConnection('code-never-issued', $built['state'], $admin);
    }

    public function test_reconnecting_marks_previous_connection_disconnected_and_clears_its_secrets(): void
    {
        $admin = $this->makeOrgAndUser('Super Admin');
        $fake = $this->fakeClient();

        foreach (['code-1', 'code-2'] as $i => $code) {
            $fake->pendingCodes[$code] = ['access_token' => "tok-{$i}", 'refresh_token' => "rt-{$i}", 'expires_in' => 3600, 'scope' => 'https://www.googleapis.com/auth/calendar.events'];
        }

        $built1 = app(GoogleOAuthService::class)->buildAuthorizationUrl($admin);
        $first = app(GoogleOAuthService::class)->completeConnection('code-1', $built1['state'], $admin);

        $built2 = app(GoogleOAuthService::class)->buildAuthorizationUrl($admin);
        $second = app(GoogleOAuthService::class)->completeConnection('code-2', $built2['state'], $admin);

        $first->refresh();
        $this->assertSame('disconnected', $first->status);
        $this->assertNull($first->access_token);
        $this->assertNull($first->refresh_token);
        $this->assertSame('connected', $second->fresh()->status);
        $this->assertSame(1, GoogleConnection::where('status', 'connected')->count());
    }

    // ── Token encryption ─────────────────────────────────────────────────

    public function test_access_and_refresh_tokens_are_encrypted_at_rest(): void
    {
        $connection = GoogleConnection::create([
            'provider' => 'google', 'purpose' => 'primary', 'status' => 'connected',
            'access_token' => 'plain-access-token', 'refresh_token' => 'plain-refresh-token',
            'token_expires_at' => now()->addHour(), 'scopes' => ['https://www.googleapis.com/auth/calendar.events'],
            'connected_at' => now(),
        ]);

        $raw = \DB::table('google_connections')->where('id', $connection->id)->first();

        $this->assertNotSame('plain-access-token', $raw->access_token);
        $this->assertNotSame('plain-refresh-token', $raw->refresh_token);
        $this->assertSame('plain-access-token', $connection->fresh()->access_token);
        $this->assertSame('plain-refresh-token', $connection->fresh()->refresh_token);
    }

    // ── Lazy token refresh ───────────────────────────────────────────────

    public function test_ensure_fresh_access_token_returns_existing_token_when_not_expired(): void
    {
        $connection = GoogleConnection::create([
            'provider' => 'google', 'purpose' => 'primary', 'status' => 'connected',
            'access_token' => 'still-valid', 'refresh_token' => 'rt', 'token_expires_at' => now()->addHour(),
            'scopes' => [], 'connected_at' => now(),
        ]);

        $token = app(GoogleTokenRefreshService::class)->ensureFreshAccessToken($connection);

        $this->assertSame('still-valid', $token);
    }

    public function test_ensure_fresh_access_token_refreshes_when_expired(): void
    {
        $fake = $this->fakeClient();
        $fake->refreshableTokens['refresh-me'] = ['access_token' => 'new-access', 'expires_in' => 3600];

        $connection = GoogleConnection::create([
            'provider' => 'google', 'purpose' => 'primary', 'status' => 'connected',
            'access_token' => 'stale', 'refresh_token' => 'refresh-me', 'token_expires_at' => now()->subMinute(),
            'scopes' => [], 'connected_at' => now(),
        ]);

        $token = app(GoogleTokenRefreshService::class)->ensureFreshAccessToken($connection);

        $this->assertSame('new-access', $token);
        $this->assertSame('new-access', $connection->fresh()->access_token);
        $this->assertSame(0, $connection->fresh()->consecutive_refresh_failures);
        $this->assertNotNull($connection->fresh()->last_refreshed_at);
    }

    public function test_ensure_fresh_access_token_throws_without_refresh_token(): void
    {
        $connection = GoogleConnection::create([
            'provider' => 'google', 'purpose' => 'primary', 'status' => 'connected',
            'access_token' => 'stale', 'refresh_token' => null, 'token_expires_at' => now()->subMinute(),
            'scopes' => [], 'connected_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        app(GoogleTokenRefreshService::class)->ensureFreshAccessToken($connection);
    }

    public function test_repeated_refresh_failure_increments_counter_and_crosses_threshold(): void
    {
        $connection = GoogleConnection::create([
            'provider' => 'google', 'purpose' => 'primary', 'status' => 'connected',
            'access_token' => 'stale', 'refresh_token' => 'unrecognized-token', 'token_expires_at' => now()->subMinute(),
            'scopes' => [], 'connected_at' => now(),
        ]);

        for ($i = 0; $i < GoogleConnectionHealth::REFRESH_FAILURE_THRESHOLD; $i++) {
            try {
                app(GoogleTokenRefreshService::class)->ensureFreshAccessToken($connection->fresh());
            } catch (\RuntimeException) {
                // expected every time — the refresh token is never valid in this test
            }
        }

        $this->assertSame(GoogleConnectionHealth::REFRESH_FAILURE_THRESHOLD, $connection->fresh()->consecutive_refresh_failures);
        $this->assertNotNull($connection->fresh()->last_failure_reason);

        $health = app(GoogleHealthService::class)->currentHealth();
        $this->assertSame(GoogleConnectionHealth::REFRESH_FAILED, $health['state']);
    }

    public function test_successful_refresh_after_failures_resets_counter_and_logs_recovery(): void
    {
        $fake = $this->fakeClient();
        $connection = GoogleConnection::create([
            'provider' => 'google', 'purpose' => 'primary', 'status' => 'connected',
            'access_token' => 'stale', 'refresh_token' => 'bad-token', 'token_expires_at' => now()->subMinute(),
            'scopes' => [], 'connected_at' => now(), 'consecutive_refresh_failures' => 2,
        ]);

        $fake->refreshableTokens['bad-token'] = ['access_token' => 'recovered', 'expires_in' => 3600];

        app(GoogleTokenRefreshService::class)->ensureFreshAccessToken($connection);

        $this->assertSame(0, $connection->fresh()->consecutive_refresh_failures);
        $this->assertTrue(ActivityLog::where('action', 'google.refresh_recovered')->exists());
    }

    // ── Disconnect ───────────────────────────────────────────────────────

    public function test_disconnect_clears_tokens_revokes_and_preserves_history(): void
    {
        $admin = $this->makeOrgAndUser('Super Admin');
        $connection = GoogleConnection::create([
            'provider' => 'google', 'purpose' => 'primary', 'status' => 'connected',
            'access_token' => 'to-revoke', 'refresh_token' => 'rt', 'token_expires_at' => now()->addHour(),
            'scopes' => [], 'connected_at' => now(),
        ]);

        $result = app(GoogleConnectionService::class)->disconnect($admin);

        $this->assertSame('disconnected', $result->status);
        $this->assertNull($result->access_token);
        $this->assertNull($result->refresh_token);
        $this->assertNotNull($result->disconnected_at);
        $this->assertSame($admin->id, $result->disconnected_by_user_id);
        $this->assertContains('to-revoke', $this->fakeClient()->revokedTokens);
        $this->assertDatabaseHas('google_connections', ['id' => $connection->id]);
        $this->assertTrue(ActivityLog::where('action', 'google.disconnected')->exists());
    }

    public function test_disconnect_is_best_effort_when_revoke_fails(): void
    {
        $admin = $this->makeOrgAndUser('Super Admin');
        $this->fakeClient()->revokeShouldFail = true;

        $connection = GoogleConnection::create([
            'provider' => 'google', 'purpose' => 'primary', 'status' => 'connected',
            'access_token' => 'tok', 'refresh_token' => 'rt', 'token_expires_at' => now()->addHour(),
            'scopes' => [], 'connected_at' => now(),
        ]);

        $result = app(GoogleConnectionService::class)->disconnect($admin);

        $this->assertSame('disconnected', $result->status);
    }

    public function test_disconnect_with_no_active_connection_returns_null(): void
    {
        $admin = $this->makeOrgAndUser('Super Admin');

        $this->assertNull(app(GoogleConnectionService::class)->disconnect($admin));
    }

    // ── Health state model ───────────────────────────────────────────────

    public function test_health_is_not_connected_when_no_connection_exists(): void
    {
        $health = app(GoogleHealthService::class)->currentHealth();
        $this->assertSame(GoogleConnectionHealth::NOT_CONNECTED, $health['state']);
    }

    public function test_health_is_connected_when_never_test_called(): void
    {
        GoogleConnection::create([
            'provider' => 'google', 'purpose' => 'primary', 'status' => 'connected',
            'access_token' => 'tok', 'refresh_token' => 'rt', 'token_expires_at' => now()->addHour(),
            'scopes' => ['https://www.googleapis.com/auth/calendar.events'], 'connected_at' => now(),
        ]);

        $health = app(GoogleHealthService::class)->currentHealth();
        $this->assertSame(GoogleConnectionHealth::CONNECTED, $health['state']);
    }

    public function test_health_is_healthy_after_successful_call(): void
    {
        GoogleConnection::create([
            'provider' => 'google', 'purpose' => 'primary', 'status' => 'connected',
            'access_token' => 'tok', 'refresh_token' => 'rt', 'token_expires_at' => now()->addHour(),
            'scopes' => ['https://www.googleapis.com/auth/calendar.events'], 'connected_at' => now(),
            'last_successful_call_at' => now(),
        ]);

        $health = app(GoogleHealthService::class)->currentHealth();
        $this->assertSame(GoogleConnectionHealth::HEALTHY, $health['state']);
    }

    public function test_health_is_token_expired_when_expired_and_never_called(): void
    {
        GoogleConnection::create([
            'provider' => 'google', 'purpose' => 'primary', 'status' => 'connected',
            'access_token' => 'tok', 'refresh_token' => 'rt', 'token_expires_at' => now()->subMinute(),
            'scopes' => ['https://www.googleapis.com/auth/calendar.events'], 'connected_at' => now(),
        ]);

        $health = app(GoogleHealthService::class)->currentHealth();
        $this->assertSame(GoogleConnectionHealth::TOKEN_EXPIRED, $health['state']);
    }

    public function test_health_is_permissions_missing_when_scope_absent(): void
    {
        GoogleConnection::create([
            'provider' => 'google', 'purpose' => 'primary', 'status' => 'connected',
            'access_token' => 'tok', 'refresh_token' => 'rt', 'token_expires_at' => now()->addHour(),
            'scopes' => ['openid', 'email'], 'connected_at' => now(),
        ]);

        $health = app(GoogleHealthService::class)->currentHealth();
        $this->assertSame(GoogleConnectionHealth::PERMISSIONS_MISSING, $health['state']);
        $this->assertContains('https://www.googleapis.com/auth/calendar.events', $health['missing_scopes']);
    }

    public function test_health_is_calendar_unavailable_when_last_failure_was_calendar_unavailable(): void
    {
        GoogleConnection::create([
            'provider' => 'google', 'purpose' => 'primary', 'status' => 'connected',
            'access_token' => 'tok', 'refresh_token' => 'rt', 'token_expires_at' => now()->addHour(),
            'scopes' => ['https://www.googleapis.com/auth/calendar.events'], 'connected_at' => now(),
            'last_failed_call_at' => now(), 'last_failure_reason' => 'calendar_unavailable: primary calendar not found',
        ]);

        $health = app(GoogleHealthService::class)->currentHealth();
        $this->assertSame(GoogleConnectionHealth::CALENDAR_UNAVAILABLE, $health['state']);
    }

    public function test_health_is_refresh_failed_at_threshold_taking_priority_over_other_states(): void
    {
        GoogleConnection::create([
            'provider' => 'google', 'purpose' => 'primary', 'status' => 'connected',
            'access_token' => 'tok', 'refresh_token' => 'rt', 'token_expires_at' => now()->subMinute(),
            'scopes' => [], 'connected_at' => now(),
            'consecutive_refresh_failures' => GoogleConnectionHealth::REFRESH_FAILURE_THRESHOLD,
        ]);

        $health = app(GoogleHealthService::class)->currentHealth();
        $this->assertSame(GoogleConnectionHealth::REFRESH_FAILED, $health['state']);
    }

    // ── testConnection() via CalendarProviderInterface ──────────────────

    public function test_test_connection_reports_not_connected_when_no_connection(): void
    {
        $provider = app(CalendarProviderInterface::class);
        $this->assertInstanceOf(FakeCalendarProvider::class, $provider);

        $provider->connected = false;
        $result = $provider->testConnection();

        $this->assertFalse($result['healthy']);
    }

    public function test_real_google_calendar_provider_test_connection_updates_connection_state(): void
    {
        // Force the real GoogleCalendarProvider (not the testing-bound
        // fake) to prove its own logic against FakeGoogleApiClient — this
        // still makes zero real network calls, since GoogleApiClientInterface
        // itself is bound to FakeGoogleApiClient regardless of which
        // CalendarProviderInterface implementation is under test.
        $connection = GoogleConnection::create([
            'provider' => 'google', 'purpose' => 'primary', 'status' => 'connected',
            'access_token' => 'tok', 'refresh_token' => 'rt', 'token_expires_at' => now()->addHour(),
            'scopes' => ['https://www.googleapis.com/auth/calendar.events'], 'connected_at' => now(),
        ]);

        $provider = app(\App\Services\Calendar\GoogleCalendarProvider::class);
        $result = $provider->testConnection();

        $this->assertTrue($result['healthy']);
        $this->assertNotNull($connection->fresh()->last_successful_call_at);
    }

    public function test_real_google_calendar_provider_test_connection_records_failure(): void
    {
        $this->fakeClient()->listEventsShouldFail = true;

        $connection = GoogleConnection::create([
            'provider' => 'google', 'purpose' => 'primary', 'status' => 'connected',
            'access_token' => 'tok', 'refresh_token' => 'rt', 'token_expires_at' => now()->addHour(),
            'scopes' => ['https://www.googleapis.com/auth/calendar.events'], 'connected_at' => now(),
        ]);

        $provider = app(\App\Services\Calendar\GoogleCalendarProvider::class);
        $result = $provider->testConnection();

        $this->assertFalse($result['healthy']);
        $this->assertNotNull($connection->fresh()->last_failed_call_at);
    }

    // ── Readiness service ────────────────────────────────────────────────

    public function test_readiness_is_false_when_not_connected(): void
    {
        $readiness = app(GoogleIntegrationReadinessService::class)->check();

        $this->assertFalse($readiness['connected']);
        $this->assertFalse($readiness['ready']);
    }

    public function test_readiness_is_true_when_healthy_and_meet_capable(): void
    {
        GoogleConnection::create([
            'provider' => 'google', 'purpose' => 'primary', 'status' => 'connected',
            'access_token' => 'tok', 'refresh_token' => 'rt', 'token_expires_at' => now()->addHour(),
            'scopes' => ['https://www.googleapis.com/auth/calendar.events'], 'connected_at' => now(),
            'last_successful_call_at' => now(),
        ]);

        $provider = app(CalendarProviderInterface::class);
        if ($provider instanceof FakeCalendarProvider) {
            $provider->connected = true;
            $provider->healthy = true;
            $provider->meetCapable = true;
        }

        $readiness = app(GoogleIntegrationReadinessService::class)->check();

        $this->assertTrue($readiness['connected']);
        $this->assertTrue($readiness['healthy']);
        $this->assertTrue($readiness['meet_available']);
        $this->assertTrue($readiness['ready']);
    }

    // ── Diagnostics endpoint authorization ───────────────────────────────

    public function test_super_admin_can_read_diagnostics(): void
    {
        $admin = $this->makeOrgAndUser('Super Admin');

        $this->actingAs($admin)->getJson('/api/admin/google/diagnostics')
            ->assertOk()
            ->assertJsonStructure(['connection', 'health', 'readiness']);
    }

    public function test_admin_can_read_diagnostics(): void
    {
        $admin = $this->makeOrgAndUser('Admin');

        $this->actingAs($admin)->getJson('/api/admin/google/diagnostics')->assertOk();
    }

    public function test_client_cannot_read_diagnostics(): void
    {
        $client = $this->makeOrgAndUser('Client');

        $this->actingAs($client)->getJson('/api/admin/google/diagnostics')->assertStatus(403);
    }

    public function test_admin_cannot_connect_disconnect_or_test_connection(): void
    {
        $admin = $this->makeOrgAndUser('Admin');

        $this->actingAs($admin)->postJson('/api/admin/google/oauth/connect')->assertStatus(403);
        $this->actingAs($admin)->postJson('/api/admin/google/disconnect')->assertStatus(403);
        $this->actingAs($admin)->postJson('/api/admin/google/test-connection')->assertStatus(403);
    }

    public function test_super_admin_can_build_connect_url_via_api(): void
    {
        $admin = $this->makeOrgAndUser('Super Admin');

        $this->actingAs($admin)->postJson('/api/admin/google/oauth/connect')
            ->assertOk()
            ->assertJsonStructure(['url']);
    }

    public function test_super_admin_can_disconnect_via_api(): void
    {
        $admin = $this->makeOrgAndUser('Super Admin');
        GoogleConnection::create([
            'provider' => 'google', 'purpose' => 'primary', 'status' => 'connected',
            'access_token' => 'tok', 'refresh_token' => 'rt', 'token_expires_at' => now()->addHour(),
            'scopes' => [], 'connected_at' => now(),
        ]);

        $this->actingAs($admin)->postJson('/api/admin/google/disconnect')
            ->assertOk()
            ->assertJsonPath('connection.connected', false);
    }

    public function test_callback_endpoint_rejects_invalid_state(): void
    {
        $admin = $this->makeOrgAndUser('Super Admin');

        $this->actingAs($admin)->postJson('/api/admin/google/oauth/callback', [
            'code'  => 'irrelevant',
            'state' => 'never-issued-state',
        ])->assertStatus(422);
    }

    public function test_callback_endpoint_completes_connection(): void
    {
        $admin = $this->makeOrgAndUser('Super Admin');
        $fake = $this->fakeClient();

        $built = app(GoogleOAuthService::class)->buildAuthorizationUrl($admin);
        $fake->pendingCodes['api-code'] = ['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600, 'scope' => 'https://www.googleapis.com/auth/calendar.events'];

        $this->actingAs($admin)->postJson('/api/admin/google/oauth/callback', [
            'code'  => 'api-code',
            'state' => $built['state'],
        ])->assertOk()->assertJsonPath('connection.connected', true);
    }

    // ── Activity Log — secrets never logged ─────────────────────────────

    public function test_activity_log_never_contains_raw_tokens(): void
    {
        $admin = $this->makeOrgAndUser('Super Admin');
        $fake = $this->fakeClient();

        $built = app(GoogleOAuthService::class)->buildAuthorizationUrl($admin);
        $fake->pendingCodes['log-code'] = [
            'access_token' => 'super-secret-access-token',
            'refresh_token' => 'super-secret-refresh-token',
            'expires_in' => 3600,
            'scope' => 'https://www.googleapis.com/auth/calendar.events',
        ];

        app(GoogleOAuthService::class)->completeConnection('log-code', $built['state'], $admin);

        foreach (ActivityLog::where('action', 'like', 'google.%')->get() as $log) {
            $payload = json_encode($log->getAttributes());
            $this->assertStringNotContainsString('super-secret-access-token', $payload);
            $this->assertStringNotContainsString('super-secret-refresh-token', $payload);
        }
    }
}

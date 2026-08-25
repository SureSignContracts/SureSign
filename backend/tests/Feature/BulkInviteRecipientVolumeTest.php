<?php

namespace Tests\Feature;

use App\Jobs\SendInvitationEmailJob;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * P2 Security Remediation (Bulk Invite Email-Volume Abuse) — application-
 * logic regression coverage for the recipient-aware volume budget in
 * UserController::bulkInvite()/reserveBulkInviteRecipients(). Runs against
 * the default SQLite (:memory:) + CACHE_STORE=array test suite —
 * deliberately does NOT claim to prove real cross-process lock
 * contention; see BulkInviteRecipientConcurrencyTest for that genuine
 * multi-process proof against a dedicated local Redis database.
 *
 * These tests never touch the existing 30 requests/minute route throttle
 * or the 100-emails-per-request validation cap — both remain fully
 * separate, unchanged controls.
 */
class BulkInviteRecipientVolumeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // CACHE_STORE=array persists across tests within one PHPUnit
        // process unless explicitly cleared — same convention already
        // established by AiAnalysisRateLimitingTest for the same reason.
        Cache::flush();
    }

    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create(['organization_id' => null]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($user);

        return $user;
    }

    private function setLimits(int $operatorHourly, int $operatorDaily, int $platformHourly): void
    {
        config([
            'suresign.invitation.bulk_invite_operator_hourly_recipients' => $operatorHourly,
            'suresign.invitation.bulk_invite_operator_daily_recipients'  => $operatorDaily,
            'suresign.invitation.bulk_invite_platform_hourly_recipients' => $platformHourly,
        ]);
    }

    private function emails(int $count, string $prefix = 'bulk'): array
    {
        return array_map(fn (int $i) => "{$prefix}{$i}@example.com", range(1, $count));
    }

    // ── Basic success + full-batch-within-quota ──────────────────────────

    public function test_small_valid_bulk_invite_succeeds(): void
    {
        Queue::fake();
        $this->setLimits(500, 2000, 1500);
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users/bulk-invite', ['emails' => $this->emails(3), 'role' => 'Client'])
            ->assertStatus(201)
            ->assertJsonCount(3, 'data.invited');
    }

    public function test_full_one_hundred_recipient_batch_succeeds_within_quota(): void
    {
        Queue::fake();
        $this->setLimits(500, 2000, 1500);
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users/bulk-invite', ['emails' => $this->emails(100), 'role' => 'Client'])
            ->assertStatus(201)
            ->assertJsonCount(100, 'data.invited');
    }

    // ── Counting semantics — recipients, not requests ────────────────────

    public function test_one_hundred_recipient_request_consumes_one_hundred_units_not_one(): void
    {
        Queue::fake();
        $this->setLimits(500, 2000, 1500);
        $operator = $this->actingAsSuperAdmin();

        $this->postJson('/api/users/bulk-invite', ['emails' => $this->emails(100), 'role' => 'Client'])
            ->assertStatus(201);

        $this->assertSame(100, RateLimiter::attempts("bulk-invite-recipients:operator:{$operator->id}:hour"));
    }

    public function test_repeated_successful_batches_accumulate_recipient_volume(): void
    {
        Queue::fake();
        $this->setLimits(500, 2000, 1500);
        $operator = $this->actingAsSuperAdmin();

        $this->postJson('/api/users/bulk-invite', ['emails' => $this->emails(30, 'first'), 'role' => 'Client'])->assertStatus(201);
        $this->postJson('/api/users/bulk-invite', ['emails' => $this->emails(20, 'second'), 'role' => 'Client'])->assertStatus(201);

        $this->assertSame(50, RateLimiter::attempts("bulk-invite-recipients:operator:{$operator->id}:hour"));
    }

    public function test_duplicate_emails_in_one_batch_consume_only_one_unit(): void
    {
        Queue::fake();
        $this->setLimits(500, 2000, 1500);
        $operator = $this->actingAsSuperAdmin();

        $duplicates = array_fill(0, 50, 'dup@example.com');
        $this->postJson('/api/users/bulk-invite', ['emails' => $duplicates, 'role' => 'Client'])
            ->assertStatus(201)
            ->assertJsonCount(1, 'data.invited');

        $this->assertSame(1, RateLimiter::attempts("bulk-invite-recipients:operator:{$operator->id}:hour"));
    }

    public function test_existing_active_user_consumes_zero_recipient_units(): void
    {
        Queue::fake();
        $this->setLimits(500, 2000, 1500);
        $operator = $this->actingAsSuperAdmin();
        User::factory()->create(['email' => 'existing@example.com']);

        $this->postJson('/api/users/bulk-invite', [
            'emails' => ['new1@example.com', 'existing@example.com', 'new2@example.com'],
            'role'   => 'Client',
        ])->assertStatus(201)->assertJsonCount(2, 'data.invited')->assertJsonCount(1, 'data.failed');

        $this->assertSame(2, RateLimiter::attempts("bulk-invite-recipients:operator:{$operator->id}:hour"));
    }

    public function test_invalid_email_consumes_zero_recipient_units(): void
    {
        Queue::fake();
        $this->setLimits(500, 2000, 1500);
        $operator = $this->actingAsSuperAdmin();

        $this->postJson('/api/users/bulk-invite', [
            'emails' => ['valid1@example.com', 'not-an-email', 'valid2@example.com'],
            'role'   => 'Client',
        ])->assertStatus(201)->assertJsonCount(2, 'data.invited');

        $this->assertSame(2, RateLimiter::attempts("bulk-invite-recipients:operator:{$operator->id}:hour"));
    }

    public function test_soft_deleted_restorable_user_counts_as_one_eligible_recipient(): void
    {
        Queue::fake();
        $this->setLimits(500, 2000, 1500);
        $operator = $this->actingAsSuperAdmin();
        $removed = User::factory()->create(['email' => 'comeback@example.com']);
        $removed->delete(); // soft delete

        $this->postJson('/api/users/bulk-invite', ['emails' => ['comeback@example.com'], 'role' => 'Client'])
            ->assertStatus(201)
            ->assertJsonCount(1, 'data.invited');

        $this->assertSame(1, RateLimiter::attempts("bulk-invite-recipients:operator:{$operator->id}:hour"));
    }

    // ── Zero-eligible: never consumes quota, never 429 ───────────────────

    public function test_fully_invalid_or_existing_batch_consumes_zero_and_does_not_return_429(): void
    {
        Queue::fake();
        $this->setLimits(500, 2000, 1500);
        $operator = $this->actingAsSuperAdmin();
        User::factory()->create(['email' => 'existing@example.com']);

        $this->postJson('/api/users/bulk-invite', [
            'emails' => ['existing@example.com', 'not-an-email'],
            'role'   => 'Client',
        ])->assertStatus(201)->assertJsonCount(0, 'data.invited')->assertJsonCount(2, 'data.failed');

        $this->assertSame(0, RateLimiter::attempts("bulk-invite-recipients:operator:{$operator->id}:hour"));
        Queue::assertNothingPushed();
    }

    // ── Ceiling enforcement ───────────────────────────────────────────────

    public function test_operator_hourly_ceiling_is_enforced(): void
    {
        Queue::fake();
        $this->setLimits(10, 2000, 1500);
        $this->actingAsSuperAdmin();

        $response = $this->postJson('/api/users/bulk-invite', ['emails' => $this->emails(11), 'role' => 'Client']);

        $response->assertStatus(429)->assertJson(['message' => 'Too many invitations have been sent recently. Please try again later.']);
        Queue::assertNothingPushed();
        $this->assertSame(0, User::where('email', 'like', 'bulk%@example.com')->count());
        $this->assertSame(0, ActivityLog::where('event', 'user.invited')->count());
    }

    public function test_operator_daily_ceiling_is_enforced(): void
    {
        Queue::fake();
        $this->setLimits(500, 10, 1500);
        $this->actingAsSuperAdmin();

        $response = $this->postJson('/api/users/bulk-invite', ['emails' => $this->emails(11), 'role' => 'Client']);

        $response->assertStatus(429);
        Queue::assertNothingPushed();
        $this->assertSame(0, User::where('email', 'like', 'bulk%@example.com')->count());
    }

    public function test_platform_hourly_ceiling_is_enforced(): void
    {
        Queue::fake();
        $this->setLimits(500, 2000, 10);
        $this->actingAsSuperAdmin();

        $response = $this->postJson('/api/users/bulk-invite', ['emails' => $this->emails(11), 'role' => 'Client']);

        $response->assertStatus(429);
        Queue::assertNothingPushed();
        $this->assertSame(0, User::where('email', 'like', 'bulk%@example.com')->count());
    }

    public function test_platform_ceiling_is_shared_across_different_operators(): void
    {
        Queue::fake();
        $this->setLimits(500, 2000, 15);
        $this->actingAsSuperAdmin();
        $this->postJson('/api/users/bulk-invite', ['emails' => $this->emails(10, 'first'), 'role' => 'Client'])->assertStatus(201);

        // A second, different Super Admin operator still shares the one
        // platform-wide dimension.
        $this->actingAsSuperAdmin();
        $this->postJson('/api/users/bulk-invite', ['emails' => $this->emails(10, 'second'), 'role' => 'Client'])
            ->assertStatus(429);
    }

    public function test_rejected_batch_returns_retry_after(): void
    {
        Queue::fake();
        $this->setLimits(10, 2000, 1500);
        $this->actingAsSuperAdmin();

        $response = $this->postJson('/api/users/bulk-invite', ['emails' => $this->emails(11), 'role' => 'Client']);

        $response->assertStatus(429);
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    // ── Existing controls unchanged ──────────────────────────────────────

    // ── Config-value safety — Pre-Commit Safety Sweep ────────────────────

    public function test_malformed_config_value_falls_back_to_secure_default_rather_than_disabling_the_limiter(): void
    {
        Queue::fake();
        // Simulates a typo'd env value (e.g. "5oo") casting to 0/negative
        // — must fall back to the documented secure default (500), never
        // to an unlimited/disabled state.
        $this->setLimits(0, 2000, 1500);
        $operator = $this->actingAsSuperAdmin();

        $this->postJson('/api/users/bulk-invite', ['emails' => $this->emails(50), 'role' => 'Client'])
            ->assertStatus(201)
            ->assertJsonCount(50, 'data.invited');

        $this->assertSame(50, RateLimiter::attempts("bulk-invite-recipients:operator:{$operator->id}:hour"));
    }

    public function test_negative_config_value_falls_back_to_secure_default(): void
    {
        Queue::fake();
        $this->setLimits(500, -10, 1500);
        $this->actingAsSuperAdmin();

        // Falls back to the secure default (2000), so a normal small
        // batch still succeeds rather than being permanently rejected by
        // a broken negative limit.
        $this->postJson('/api/users/bulk-invite', ['emails' => $this->emails(5), 'role' => 'Client'])
            ->assertStatus(201);
    }

    public function test_existing_one_hundred_recipient_request_cap_is_unchanged(): void
    {
        Queue::fake();
        $this->setLimits(500, 2000, 1500);
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users/bulk-invite', ['emails' => $this->emails(101), 'role' => 'Client'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['emails']);
    }

    public function test_super_admin_only_authorization_is_unchanged(): void
    {
        Queue::fake();
        $this->setLimits(500, 2000, 1500);
        $admin = User::factory()->create(['organization_id' => null]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($admin);

        $this->postJson('/api/users/bulk-invite', ['emails' => ['someone@example.com'], 'role' => 'Client'])
            ->assertStatus(403);
    }

    public function test_send_invitation_email_job_is_dispatched_once_per_accepted_recipient(): void
    {
        Queue::fake();
        $this->setLimits(500, 2000, 1500);
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users/bulk-invite', ['emails' => $this->emails(5), 'role' => 'Client'])
            ->assertStatus(201);

        Queue::assertPushed(SendInvitationEmailJob::class, 5);
    }
}

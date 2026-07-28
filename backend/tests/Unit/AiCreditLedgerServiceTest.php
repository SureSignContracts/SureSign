<?php

namespace Tests\Unit;

use App\Models\AiCreditLedgerEntry;
use App\Models\Organization;
use App\Models\User;
use App\Services\AI\AiCreditBalanceService;
use App\Services\AI\AiCreditLedgerService;
use App\Support\AI\AiCreditLedgerConflictException;
use App\Support\AI\AiCreditLedgerStateException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase G4C.3A — the immutable ledger foundation. No workflow integration
 * exists yet, so every test drives AiCreditLedgerService/AiCreditBalanceService
 * directly with synthetic references rather than through a real Contract/
 * Trade Package analysis.
 */
class AiCreditLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private AiCreditLedgerService $ledger;
    private AiCreditBalanceService $balance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(AiCreditLedgerService::class);
        $this->balance = app(AiCreditBalanceService::class);
    }

    private function org(): Organization
    {
        return Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
    }

    private function user(Organization $org): User
    {
        return User::factory()->create(['organization_id' => $org->id]);
    }

    // ── Immutability ─────────────────────────────────────────────────────

    public function test_ledger_entries_cannot_be_updated(): void
    {
        $org = $this->org();
        $user = $this->user($org);
        $entry = $this->ledger->grant($org->id, 100, 'Initial grant', 'grant-' . uniqid(), $user->id);

        $this->expectException(\RuntimeException::class);
        $entry->update(['reason' => 'Tampered']);
    }

    public function test_ledger_entries_cannot_be_deleted(): void
    {
        $org = $this->org();
        $user = $this->user($org);
        $entry = $this->ledger->grant($org->id, 100, 'Initial grant', 'grant-' . uniqid(), $user->id);

        $this->expectException(\RuntimeException::class);
        $entry->delete();
    }

    // ── The worked example from the corrected specification ────────────

    public function test_worked_example_grant_reserve_settle(): void
    {
        $org = $this->org();
        $user = $this->user($org);

        $this->ledger->grant($org->id, 100, 'Monthly grant', 'grant-1', $user->id);
        $this->assertSame(100.0, $this->balance->balanceFor($org->id)['available']);

        $this->ledger->reserve($org->id, 'contract_analysis', 'TestSubject', 1, 20, 'Reserve for analysis', 'reserve-1');
        $afterReserve = $this->balance->balanceFor($org->id);
        $this->assertSame(20.0, $afterReserve['reserved']);
        $this->assertSame(80.0, $afterReserve['available']);

        $this->ledger->settle('TestSubject', 1, 'Analysis completed', 'settle-1');
        $afterSettle = $this->balance->balanceFor($org->id);
        $this->assertSame(0.0, $afterSettle['reserved']);
        $this->assertSame(20.0, $afterSettle['consumed']);
        $this->assertSame(80.0, $afterSettle['available'], 'Settling must not restore the consumed amount to available.');
    }

    public function test_release_restores_available_without_consuming(): void
    {
        $org = $this->org();
        $this->ledger->grant($org->id, 100, 'Grant', 'grant-1', $this->user($org)->id);
        $this->ledger->reserve($org->id, 'contract_analysis', 'TestSubject', 2, 30, 'Reserve', 'reserve-2');

        $this->assertSame(70.0, $this->balance->balanceFor($org->id)['available']);

        $this->ledger->release('TestSubject', 2, 'Analysis failed', 'release-2');
        $balance = $this->balance->balanceFor($org->id);

        $this->assertSame(0.0, $balance['reserved']);
        $this->assertSame(0.0, $balance['consumed']);
        $this->assertSame(100.0, $balance['available'], 'A release must fully restore the reserved amount — nothing was consumed.');
    }

    // ── Reservation lifecycle state machine ─────────────────────────────

    public function test_settle_without_reserve_throws(): void
    {
        $this->expectException(AiCreditLedgerStateException::class);
        $this->ledger->settle('TestSubject', 99, 'No reserve exists', 'settle-99');
    }

    public function test_release_without_reserve_throws(): void
    {
        $this->expectException(AiCreditLedgerStateException::class);
        $this->ledger->release('TestSubject', 98, 'No reserve exists', 'release-98');
    }

    public function test_settle_after_release_throws(): void
    {
        $org = $this->org();
        $this->ledger->grant($org->id, 100, 'Grant', 'grant-1', $this->user($org)->id);
        $this->ledger->reserve($org->id, 'contract_analysis', 'TestSubject', 3, 10, 'Reserve', 'reserve-3');
        $this->ledger->release('TestSubject', 3, 'Failed', 'release-3');

        $this->expectException(AiCreditLedgerStateException::class);
        $this->ledger->settle('TestSubject', 3, 'Too late', 'settle-3');
    }

    public function test_release_after_settle_throws(): void
    {
        $org = $this->org();
        $this->ledger->grant($org->id, 100, 'Grant', 'grant-1', $this->user($org)->id);
        $this->ledger->reserve($org->id, 'contract_analysis', 'TestSubject', 4, 10, 'Reserve', 'reserve-4');
        $this->ledger->settle('TestSubject', 4, 'Completed', 'settle-4');

        $this->expectException(AiCreditLedgerStateException::class);
        $this->ledger->release('TestSubject', 4, 'Too late', 'release-4');
    }

    // ── Idempotency ──────────────────────────────────────────────────────

    public function test_duplicate_reserve_with_identical_parameters_is_idempotent(): void
    {
        $org = $this->org();
        $first = $this->ledger->reserve($org->id, 'contract_analysis', 'TestSubject', 5, 15, 'Reserve', 'reserve-5');
        $second = $this->ledger->reserve($org->id, 'contract_analysis', 'TestSubject', 5, 15, 'Reserve (retry)', 'reserve-5-retry');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AiCreditLedgerEntry::where('reference_id', 5)->where('transaction_type', 'reserve')->count());
    }

    public function test_duplicate_reserve_with_different_amount_throws_conflict(): void
    {
        $org = $this->org();
        $this->ledger->reserve($org->id, 'contract_analysis', 'TestSubject', 6, 15, 'Reserve', 'reserve-6');

        $this->expectException(AiCreditLedgerConflictException::class);
        $this->ledger->reserve($org->id, 'contract_analysis', 'TestSubject', 6, 25, 'Different amount', 'reserve-6-different');
    }

    public function test_duplicate_settle_is_idempotent(): void
    {
        $org = $this->org();
        $this->ledger->grant($org->id, 100, 'Grant', 'grant-1', $this->user($org)->id);
        $this->ledger->reserve($org->id, 'contract_analysis', 'TestSubject', 7, 10, 'Reserve', 'reserve-7');
        $first = $this->ledger->settle('TestSubject', 7, 'Completed', 'settle-7');
        $second = $this->ledger->settle('TestSubject', 7, 'Completed (retry)', 'settle-7-retry');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AiCreditLedgerEntry::where('reference_id', 7)->where('transaction_type', 'settle')->count());
    }

    public function test_reused_idempotency_key_across_different_operations_throws_conflict(): void
    {
        $orgA = $this->org();
        $orgB = $this->org();
        $key = 'shared-key-' . uniqid();

        $this->ledger->grant($orgA->id, 50, 'Grant A', $key, $this->user($orgA)->id);

        $this->expectException(AiCreditLedgerConflictException::class);
        $this->ledger->grant($orgB->id, 50, 'Grant B — different organisation, same key', $key, $this->user($orgB)->id);
    }

    public function test_missing_reason_is_rejected(): void
    {
        $org = $this->org();
        $this->expectException(\InvalidArgumentException::class);
        $this->ledger->grant($org->id, 10, '', 'grant-empty-reason', $this->user($org)->id);
    }

    public function test_missing_idempotency_key_is_rejected(): void
    {
        $org = $this->org();
        $this->expectException(\InvalidArgumentException::class);
        $this->ledger->grant($org->id, 10, 'Grant', '', $this->user($org)->id);
    }

    public function test_non_positive_amount_is_rejected(): void
    {
        $org = $this->org();
        $this->expectException(\InvalidArgumentException::class);
        $this->ledger->grant($org->id, 0, 'Grant', 'grant-zero', $this->user($org)->id);
    }

    // ── Balance arithmetic for every transaction type ───────────────────

    public function test_adjustment_credit_increases_available(): void
    {
        $org = $this->org();
        $user = $this->user($org);
        $this->ledger->grant($org->id, 50, 'Grant', 'grant-1', $user->id);
        $this->ledger->adjustCredit($org->id, 10, 'Goodwill credit', 'adj-credit-1', $user->id);

        $this->assertSame(60.0, $this->balance->balanceFor($org->id)['available']);
    }

    public function test_adjustment_debit_decreases_available_without_touching_reserved(): void
    {
        $org = $this->org();
        $user = $this->user($org);
        $this->ledger->grant($org->id, 50, 'Grant', 'grant-1', $user->id);
        $this->ledger->reserve($org->id, 'contract_analysis', 'TestSubject', 8, 5, 'Reserve', 'reserve-8');
        $this->ledger->adjustDebit($org->id, 10, 'Correction', 'adj-debit-1', $user->id);

        $balance = $this->balance->balanceFor($org->id);
        $this->assertSame(5.0, $balance['reserved']);
        $this->assertSame(35.0, $balance['available']); // 50 - 10(debit) - 5(reserved)
    }

    public function test_expiry_decreases_available_without_touching_reserved(): void
    {
        $org = $this->org();
        $user = $this->user($org);
        $this->ledger->grant($org->id, 50, 'Grant', 'grant-1', $user->id);
        $this->ledger->expire($org->id, 15, 'Monthly expiry', 'expiry-1');

        $balance = $this->balance->balanceFor($org->id);
        $this->assertSame(0.0, $balance['reserved']);
        $this->assertSame(35.0, $balance['available']);
    }

    public function test_organizations_are_isolated(): void
    {
        $orgA = $this->org();
        $orgB = $this->org();
        $this->ledger->grant($orgA->id, 100, 'Grant A', 'grant-a', $this->user($orgA)->id);
        $this->ledger->grant($orgB->id, 40, 'Grant B', 'grant-b', $this->user($orgB)->id);

        $this->assertSame(100.0, $this->balance->balanceFor($orgA->id)['available']);
        $this->assertSame(40.0, $this->balance->balanceFor($orgB->id)['available']);
    }

    public function test_has_sufficient_balance(): void
    {
        $org = $this->org();
        $this->ledger->grant($org->id, 50, 'Grant', 'grant-1', $this->user($org)->id);

        $this->assertTrue($this->balance->hasSufficientBalance($org->id, 50));
        $this->assertFalse($this->balance->hasSufficientBalance($org->id, 50.01));
    }

    public function test_grant_actor_is_recorded(): void
    {
        $org = $this->org();
        $user = $this->user($org);
        $entry = $this->ledger->grant($org->id, 10, 'Grant', 'grant-actor', $user->id);

        $this->assertSame('user', $entry->actor_type);
        $this->assertSame($user->id, $entry->actor_id);
    }

    public function test_system_initiated_reserve_has_no_actor(): void
    {
        $org = $this->org();
        $entry = $this->ledger->reserve($org->id, 'contract_analysis', 'TestSubject', 9, 5, 'System reserve', 'reserve-9');

        $this->assertSame('system', $entry->actor_type);
        $this->assertNull($entry->actor_id);
    }
}

<?php

namespace App\Services\AI;

use App\Models\AiCreditLedgerEntry;
use App\Support\AI\AiCreditLedgerConflictException;
use App\Support\AI\AiCreditLedgerStateException;
use App\Support\AI\AiCreditTransactionType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Phase G4C.3A — the sole writer of ai_credit_ledger_entries (mirrors
 * SubscriptionLifecycleService's role for subscriptions: no other code path
 * may insert a row in this table). Policy-agnostic — every method takes an
 * already-resolved $amount; this service never decides how much anything
 * costs.
 *
 * No workflow integration exists yet — nothing in AnalyseContractWithAiJob/
 * AnalyseTradePackageWithAiJob/AnalyseAiTradePackageWithAiJob calls this.
 * That is deliberate scope for a later phase; see
 * internal-docs/commercial/ai-credit-policy-and-consumption-model-v1.md
 * Part Seven.
 *
 * Concurrency/idempotency strategy (no Cache::lock() anywhere in this
 * class — see Part Seven's explicit reasoning): every write happens inside
 * a DB transaction. reserve() has nothing to lock until it exists, so its
 * safety comes entirely from the unique constraints plus graceful recovery
 * from a duplicate-key QueryException. settle()/release() lockForUpdate()
 * the existing reserve row — even though neither ever modifies it — purely
 * to serialize two concurrent settle/release attempts on the same
 * reservation; the second transaction only proceeds once the first commits,
 * by which point the first's settle/release row is already visible to it.
 */
class AiCreditLedgerService
{
    public function reserve(
        int $organizationId,
        string $workflow,
        string $referenceType,
        int $referenceId,
        float $amount,
        string $reason,
        string $idempotencyKey,
        ?int $actorUserId = null
    ): AiCreditLedgerEntry {
        $this->assertPositiveAmount($amount);
        $this->assertNonEmpty($reason, 'reason');
        $this->assertNonEmpty($idempotencyKey, 'idempotencyKey');

        return DB::transaction(function () use ($organizationId, $workflow, $referenceType, $referenceId, $amount, $reason, $idempotencyKey, $actorUserId) {
            try {
                return AiCreditLedgerEntry::create([
                    'organization_id' => $organizationId,
                    'workflow' => $workflow,
                    'transaction_type' => AiCreditTransactionType::RESERVE,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'amount' => $amount,
                    'reason' => $reason,
                    'actor_type' => $actorUserId !== null ? 'user' : 'system',
                    'actor_id' => $actorUserId,
                    'idempotency_key' => $idempotencyKey,
                ]);
            } catch (QueryException $e) {
                if (!$this->isDuplicateKey($e)) {
                    throw $e;
                }

                $existing = AiCreditLedgerEntry::query()
                    ->where('reference_type', $referenceType)
                    ->where('reference_id', $referenceId)
                    ->where('transaction_type', AiCreditTransactionType::RESERVE)
                    ->first();

                if ($existing === null) {
                    // The collision was on idempotency_key itself, reused
                    // across a genuinely different reference — always a bug.
                    throw new AiCreditLedgerConflictException(
                        "Idempotency key '{$idempotencyKey}' is already in use by a different ledger entry."
                    );
                }

                return $this->assertReservationMatch($existing, $organizationId, $workflow, $amount);
            }
        });
    }

    /**
     * Amount is ALWAYS derived from the original reserve entry — never
     * caller-supplied — so a settlement can never diverge from what was
     * actually reserved (the AI Credit Policy's "Reserved = Settled"
     * principle, enforced structurally here rather than by convention).
     */
    public function settle(
        string $referenceType,
        int $referenceId,
        string $reason,
        string $idempotencyKey,
        ?int $actorUserId = null
    ): AiCreditLedgerEntry {
        return $this->resolveReservation(AiCreditTransactionType::SETTLE, $referenceType, $referenceId, $reason, $idempotencyKey, $actorUserId);
    }

    public function release(
        string $referenceType,
        int $referenceId,
        string $reason,
        string $idempotencyKey,
        ?int $actorUserId = null
    ): AiCreditLedgerEntry {
        return $this->resolveReservation(AiCreditTransactionType::RELEASE, $referenceType, $referenceId, $reason, $idempotencyKey, $actorUserId);
    }

    private function resolveReservation(
        string $resolutionType,
        string $referenceType,
        int $referenceId,
        string $reason,
        string $idempotencyKey,
        ?int $actorUserId
    ): AiCreditLedgerEntry {
        $this->assertNonEmpty($reason, 'reason');
        $this->assertNonEmpty($idempotencyKey, 'idempotencyKey');
        $opposite = $resolutionType === AiCreditTransactionType::SETTLE ? AiCreditTransactionType::RELEASE : AiCreditTransactionType::SETTLE;

        return DB::transaction(function () use ($resolutionType, $opposite, $referenceType, $referenceId, $reason, $idempotencyKey, $actorUserId) {
            $reserveEntry = AiCreditLedgerEntry::query()
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->where('transaction_type', AiCreditTransactionType::RESERVE)
                ->lockForUpdate()
                ->first();

            if ($reserveEntry === null) {
                throw new AiCreditLedgerStateException(
                    "No open reserve entry exists for {$referenceType}#{$referenceId} — cannot {$resolutionType}."
                );
            }

            $existingResolution = AiCreditLedgerEntry::query()
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->whereIn('transaction_type', [AiCreditTransactionType::SETTLE, AiCreditTransactionType::RELEASE])
                ->first();

            if ($existingResolution !== null) {
                if ($existingResolution->transaction_type === $opposite) {
                    throw new AiCreditLedgerStateException(
                        "{$referenceType}#{$referenceId} was already {$opposite}d — cannot {$resolutionType} it."
                    );
                }

                // Same resolution already recorded — idempotent replay.
                return $existingResolution;
            }

            try {
                return AiCreditLedgerEntry::create([
                    'organization_id' => $reserveEntry->organization_id,
                    'workflow' => $reserveEntry->workflow,
                    'transaction_type' => $resolutionType,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'amount' => $reserveEntry->amount,
                    'reason' => $reason,
                    'actor_type' => $actorUserId !== null ? 'user' : 'system',
                    'actor_id' => $actorUserId,
                    'idempotency_key' => $idempotencyKey,
                ]);
            } catch (QueryException $e) {
                if (!$this->isDuplicateKey($e)) {
                    throw $e;
                }

                $existing = AiCreditLedgerEntry::query()
                    ->where('reference_type', $referenceType)
                    ->where('reference_id', $referenceId)
                    ->where('transaction_type', $resolutionType)
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }

                throw new AiCreditLedgerConflictException(
                    "Idempotency key '{$idempotencyKey}' is already in use by a different ledger entry."
                );
            }
        });
    }

    public function grant(
        int $organizationId,
        float $amount,
        string $reason,
        string $idempotencyKey,
        int $actorUserId,
        ?string $workflow = null
    ): AiCreditLedgerEntry {
        return $this->recordSimple($organizationId, $workflow, AiCreditTransactionType::GRANT, $amount, $reason, $idempotencyKey, $actorUserId);
    }

    public function adjustCredit(int $organizationId, float $amount, string $reason, string $idempotencyKey, int $actorUserId): AiCreditLedgerEntry
    {
        return $this->recordSimple($organizationId, null, AiCreditTransactionType::ADJUSTMENT_CREDIT, $amount, $reason, $idempotencyKey, $actorUserId);
    }

    public function adjustDebit(int $organizationId, float $amount, string $reason, string $idempotencyKey, int $actorUserId): AiCreditLedgerEntry
    {
        return $this->recordSimple($organizationId, null, AiCreditTransactionType::ADJUSTMENT_DEBIT, $amount, $reason, $idempotencyKey, $actorUserId);
    }

    /** $actorUserId is nullable here — expiry is typically system-initiated (e.g. a future scheduled command), not an admin action. */
    public function expire(int $organizationId, float $amount, string $reason, string $idempotencyKey, ?int $actorUserId = null): AiCreditLedgerEntry
    {
        return $this->recordSimple($organizationId, null, AiCreditTransactionType::EXPIRY, $amount, $reason, $idempotencyKey, $actorUserId);
    }

    private function recordSimple(
        int $organizationId,
        ?string $workflow,
        string $transactionType,
        float $amount,
        string $reason,
        string $idempotencyKey,
        ?int $actorUserId
    ): AiCreditLedgerEntry {
        $this->assertPositiveAmount($amount);
        $this->assertNonEmpty($reason, 'reason');
        $this->assertNonEmpty($idempotencyKey, 'idempotencyKey');

        return DB::transaction(function () use ($organizationId, $workflow, $transactionType, $amount, $reason, $idempotencyKey, $actorUserId) {
            try {
                return AiCreditLedgerEntry::create([
                    'organization_id' => $organizationId,
                    'workflow' => $workflow,
                    'transaction_type' => $transactionType,
                    'reference_type' => null,
                    'reference_id' => null,
                    'amount' => $amount,
                    'reason' => $reason,
                    'actor_type' => $actorUserId !== null ? 'user' : 'system',
                    'actor_id' => $actorUserId,
                    'idempotency_key' => $idempotencyKey,
                ]);
            } catch (QueryException $e) {
                if (!$this->isDuplicateKey($e)) {
                    throw $e;
                }

                $existing = AiCreditLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null
                    && (int) $existing->organization_id === $organizationId
                    && $existing->transaction_type === $transactionType
                    && bccomp((string) $existing->amount, number_format($amount, 2, '.', ''), 2) === 0
                ) {
                    return $existing;
                }

                throw new AiCreditLedgerConflictException(
                    "Idempotency key '{$idempotencyKey}' is already in use by a different ledger entry."
                );
            }
        });
    }

    private function assertReservationMatch(AiCreditLedgerEntry $existing, int $organizationId, string $workflow, float $amount): AiCreditLedgerEntry
    {
        if ((int) $existing->organization_id !== $organizationId
            || $existing->workflow !== $workflow
            || bccomp((string) $existing->amount, number_format($amount, 2, '.', ''), 2) !== 0
        ) {
            throw new AiCreditLedgerConflictException(
                'A reserve entry already exists for this reference with different parameters.'
            );
        }

        return $existing;
    }

    private function assertPositiveAmount(float $amount): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Ledger amount must be positive.');
        }
    }

    private function assertNonEmpty(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("{$field} is required for every ledger entry.");
        }
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            || str_contains($e->getMessage(), 'Duplicate entry')
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}

<?php

namespace App\Support\AI;

/**
 * Phase G4C.3A — the single authoritative definition of AI Credit ledger
 * transaction types (mirrors AiWorkflow's exact shape). Amount is always
 * positive on every ledger row; direction is determined ONLY by which of
 * these constants a row uses — never a signed amount, never a separate
 * mutable direction flag.
 *
 * MIGRATION_CREDIT/MIGRATION_DEBIT are reserved for a future one-time
 * legacy-balance import — no G4C.3A ledger service method writes them yet,
 * since no prior ledger exists in this system to migrate from. REFUND is
 * deliberately absent — its partial-refund/cap semantics were not decided
 * and are explicitly out of scope for this phase; add it only when a real
 * caller and a real decision both exist.
 */
final class AiCreditTransactionType
{
    public const GRANT = 'grant';
    public const RESERVE = 'reserve';
    public const SETTLE = 'settle';
    public const RELEASE = 'release';
    public const ADJUSTMENT_CREDIT = 'adjustment_credit';
    public const ADJUSTMENT_DEBIT = 'adjustment_debit';
    public const EXPIRY = 'expiry';
    public const MIGRATION_CREDIT = 'migration_credit';
    public const MIGRATION_DEBIT = 'migration_debit';

    public const ALL = [
        self::GRANT,
        self::RESERVE,
        self::SETTLE,
        self::RELEASE,
        self::ADJUSTMENT_CREDIT,
        self::ADJUSTMENT_DEBIT,
        self::EXPIRY,
        self::MIGRATION_CREDIT,
        self::MIGRATION_DEBIT,
    ];

    /** Transaction types that increase available balance. */
    public const CREDIT_TYPES = [
        self::GRANT,
        self::ADJUSTMENT_CREDIT,
        self::MIGRATION_CREDIT,
    ];

    /** Transaction types that decrease available balance directly (not via reservation). */
    public const DEBIT_TYPES = [
        self::ADJUSTMENT_DEBIT,
        self::EXPIRY,
        self::MIGRATION_DEBIT,
    ];

    /** Transaction types that always require a reference (reservation lifecycle). */
    public const REFERENCE_REQUIRED_TYPES = [
        self::RESERVE,
        self::SETTLE,
        self::RELEASE,
    ];
}

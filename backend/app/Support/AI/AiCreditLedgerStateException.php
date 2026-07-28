<?php

namespace App\Support\AI;

use RuntimeException;

/**
 * Phase G4C.3A — thrown when a ledger operation would violate the
 * reservation-lifecycle state machine: settle/release with no open reserve,
 * or settle/release on a reference already resolved the other way. Never
 * caught-and-ignored by AiCreditLedgerService — an invalid transition is
 * always a real bug in the caller, never a case to silently no-op.
 */
class AiCreditLedgerStateException extends RuntimeException
{
}

<?php

namespace App\Support\AI;

use RuntimeException;

/**
 * Phase G4C.3A — thrown when an idempotency key or reservation-lifecycle key
 * is reused with materially different parameters (a different amount or
 * workflow than the original entry). A genuine idempotent retry (identical
 * parameters) is never an error — see AiCreditLedgerService's duplicate-key
 * recovery. This exception exists specifically so a real bug (two logically
 * different operations colliding on the same key) is never silently resolved
 * as if it were a safe retry.
 */
class AiCreditLedgerConflictException extends RuntimeException
{
}

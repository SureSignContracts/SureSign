<?php

namespace App\Support\AI;

use RuntimeException;

/**
 * Phase G4C.3I — thrown by AnalyseContractWithAiJob/AnalyseTradePackageWithAiJob
 * when AiCreditWorkflowLifecycle::shouldBlock() finds a resolved,
 * insufficient balance with enforcement enabled. Extends RuntimeException
 * specifically so it flows through each job's existing catch block
 * unchanged — that block already treats a RuntimeException's message as
 * curated and safe to persist to `error_message`/show to the customer (see
 * AnalyseContractWithAiJob's own catch block docblock) and already calls
 * AiCreditWorkflowLifecycle::releaseFor() for every failure, so no new
 * release-handling was needed to add enforcement.
 */
class AiCreditEnforcementException extends RuntimeException
{
}

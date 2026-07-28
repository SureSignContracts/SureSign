<?php

namespace App\Support\AI;

/**
 * The single authoritative definition of provider-backed AI workflow identifiers
 * (Phase G4C.1). Restricted to the two currently confirmed provider-backed
 * workflows (see internal-docs/super-admin/ai-credits-architecture.md) — do not
 * add a case here for Prompt Library (no provider calls) or AI Chat
 * (non-functional). A future real provider-backed workflow adds one constant
 * here and nowhere else needs to invent its own identifier string.
 */
final class AiWorkflow
{
    public const CONTRACT_ANALYSIS = 'contract_analysis';
    public const TRADE_PACKAGE_ANALYSIS = 'trade_package_analysis';

    public const ALL = [
        self::CONTRACT_ANALYSIS,
        self::TRADE_PACKAGE_ANALYSIS,
    ];
}

<?php

namespace App\Support\AI;

/**
 * Phase G4C.2D — the single authoritative definition of the current
 * execution-telemetry SHAPE (which columns exist and what they mean on
 * ContractAiAnalysis/TradePackageAiAnalysis), distinct from
 * AiInputNormalizer::VERSION (how input is measured) and
 * config/ai_credit_simulation_policies.php's policy_version (what a
 * candidate would charge). Bump CURRENT_VERSION only when the set of
 * collected telemetry fields or their meaning changes — never for a
 * reporting/dashboard change, which reads existing telemetry rather than
 * altering what's collected.
 *
 * A row with telemetry_schema_version = null predates this versioning
 * entirely (created before the 2026-08-11 migration) — never assumed to
 * match CURRENT_VERSION's shape.
 */
final class AiTelemetrySchema
{
    public const CURRENT_VERSION = 1;
}

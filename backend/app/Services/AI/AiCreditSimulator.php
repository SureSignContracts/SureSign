<?php

namespace App\Services\AI;

use App\Models\AiCreditSimulationResult;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Phase G4C.2C-2 — non-enforcing AI Credit simulation.
 *
 * For a single already-completed AI execution, computes and persists what
 * EACH configured candidate policy (config/ai_credit_simulation_policies.php)
 * WOULD have charged, purely for internal observation and future calibration.
 *
 * Hard invariants (do not weaken any of these without a deliberate,
 * separate decision — see internal-docs/commercial/ai-credit-policy-and
 * -consumption-model-v1.md Part Two):
 *  - Never deducts, reserves, settles, or grants anything.
 *  - Never touches a subscription, entitlement, invoice, or the analysis
 *    row itself.
 *  - Never invents a number — a policy with no period covering the given
 *    instant, or a caller with no normalized input available, always
 *    records that explicitly (simulation_status = unresolved/unavailable)
 *    rather than silently resolving to 0 credits.
 *  - Idempotent — recalculating the same (analysable, candidate, policy
 *    version, normalization version) updates the same row rather than
 *    creating a duplicate (enforced by the DB's own unique constraint,
 *    see the owning migration).
 *  - Never throws into a caller's own success path — every public entry
 *    point catches internally and logs; a simulation failure must never
 *    fail a customer's AI analysis (see callers in AnalyseContractWithAiJob
 *    / AnalyseTradePackageWithAiJob / the backfill command).
 */
class AiCreditSimulator
{
    public const STATUS_CALCULATED = 'calculated';
    public const STATUS_UNRESOLVED = 'unresolved';
    public const STATUS_UNAVAILABLE = 'unavailable';
    public const STATUS_ERROR = 'error';

    public const SOURCE_PROSPECTIVE = 'prospective';
    public const SOURCE_BACKFILL = 'backfill';

    /**
     * Simulate every configured candidate policy for $workflow against
     * $analysable, recording one AiCreditSimulationResult row per candidate.
     *
     * $normalizedInputCharCount should be null when the source input could
     * not be measured (e.g. an unreconstructable historical document) — this
     * always produces STATUS_UNAVAILABLE, never a guessed value.
     *
     * $at is the instant to resolve each candidate's effective-dated policy
     * period against — always the analysis's own completion time, never
     * "now", so re-running a backfill later doesn't reinterpret history
     * under whatever candidate rates happen to be configured today.
     */
    public function simulate(
        Model $analysable,
        string $workflow,
        ?int $normalizedInputCharCount,
        DateTimeInterface $at,
        string $source
    ): void {
        $candidates = config("ai_credit_simulation_policies.{$workflow}", []);

        if ($candidates === []) {
            Log::warning('AiCreditSimulator: no candidate policies configured for workflow', [
                'workflow' => $workflow,
            ]);
            return;
        }

        foreach ($candidates as $candidateKey => $periods) {
            try {
                $this->simulateCandidate(
                    $analysable,
                    $workflow,
                    $candidateKey,
                    is_array($periods) ? $periods : [],
                    $normalizedInputCharCount,
                    $at,
                    $source
                );
            } catch (\Throwable $e) {
                // A single candidate failing to simulate must never affect the
                // analysis, the customer, or any other candidate's own result.
                Log::error('AiCreditSimulator: candidate simulation failed', [
                    'analysable_type' => $analysable::class,
                    'analysable_id'   => $analysable->getKey(),
                    'workflow'        => $workflow,
                    'candidate'       => $candidateKey,
                    'exception'       => $e,
                ]);

                $this->recordResult($analysable, $workflow, $candidateKey, [
                    'candidate_policy_version'     => 0,
                    'charging_strategy'             => 'unresolved',
                    'normalized_input_char_count'   => $normalizedInputCharCount,
                    'hypothetical_band'             => null,
                    'hypothetical_credits'          => null,
                    'simulation_status'             => self::STATUS_ERROR,
                    'resolution_reason'             => 'An unexpected error occurred while simulating this candidate; see application logs.',
                    'source'                        => $source,
                ]);
            }
        }
    }

    private function simulateCandidate(
        Model $analysable,
        string $workflow,
        string $candidateKey,
        array $periods,
        ?int $normalizedInputCharCount,
        DateTimeInterface $at,
        string $source
    ): void {
        $period = $this->resolvePeriod($periods, $at);

        if ($period === null) {
            $this->recordResult($analysable, $workflow, $candidateKey, [
                'candidate_policy_version'    => 0,
                'charging_strategy'           => 'unresolved',
                'normalized_input_char_count' => $normalizedInputCharCount,
                'hypothetical_band'           => null,
                'hypothetical_credits'        => null,
                'simulation_status'           => self::STATUS_UNRESOLVED,
                'resolution_reason'           => 'No configured policy period covers this instant.',
                'source'                      => $source,
            ]);
            return;
        }

        $strategy = $period['strategy'] ?? 'unresolved';

        if ($normalizedInputCharCount === null) {
            $this->recordResult($analysable, $workflow, $candidateKey, [
                'candidate_policy_version'    => (int) $period['policy_version'],
                'charging_strategy'           => $strategy,
                'normalized_input_char_count' => null,
                'hypothetical_band'           => null,
                'hypothetical_credits'        => null,
                'simulation_status'           => self::STATUS_UNAVAILABLE,
                'resolution_reason'           => 'Source input could not be measured (unavailable for this execution).',
                'source'                      => $source,
            ]);
            return;
        }

        if ($strategy === 'unresolved') {
            $this->recordResult($analysable, $workflow, $candidateKey, [
                'candidate_policy_version'    => (int) $period['policy_version'],
                'charging_strategy'           => 'unresolved',
                'normalized_input_char_count' => $normalizedInputCharCount,
                'hypothetical_band'           => null,
                'hypothetical_credits'        => null,
                'simulation_status'           => self::STATUS_UNRESOLVED,
                'resolution_reason'           => $period['label'] ?? 'This workflow has no resolved charging policy yet.',
                'source'                      => $source,
            ]);
            return;
        }

        if ($strategy === 'flat') {
            $this->recordResult($analysable, $workflow, $candidateKey, [
                'candidate_policy_version'    => (int) $period['policy_version'],
                'charging_strategy'           => 'flat',
                'normalized_input_char_count' => $normalizedInputCharCount,
                'hypothetical_band'           => null,
                'hypothetical_credits'        => (float) $period['flat_credits'],
                'simulation_status'           => self::STATUS_CALCULATED,
                'resolution_reason'           => null,
                'source'                      => $source,
            ]);
            return;
        }

        if ($strategy === 'banded') {
            $band = $this->resolveBand($period['bands'] ?? [], $normalizedInputCharCount);

            if ($band === null) {
                $this->recordResult($analysable, $workflow, $candidateKey, [
                    'candidate_policy_version'    => (int) $period['policy_version'],
                    'charging_strategy'           => 'banded',
                    'normalized_input_char_count' => $normalizedInputCharCount,
                    'hypothetical_band'           => null,
                    'hypothetical_credits'        => null,
                    'simulation_status'           => self::STATUS_UNRESOLVED,
                    'resolution_reason'           => 'No configured band covers this normalized input size.',
                    'source'                      => $source,
                ]);
                return;
            }

            $this->recordResult($analysable, $workflow, $candidateKey, [
                'candidate_policy_version'    => (int) $period['policy_version'],
                'charging_strategy'           => 'banded',
                'normalized_input_char_count' => $normalizedInputCharCount,
                'hypothetical_band'           => $band['label'],
                'hypothetical_credits'        => (float) $band['credits'],
                'simulation_status'           => self::STATUS_CALCULATED,
                'resolution_reason'           => null,
                'source'                      => $source,
            ]);
            return;
        }

        // An unrecognised strategy string is a configuration error, not a
        // real policy state — fail closed, never guess.
        $this->recordResult($analysable, $workflow, $candidateKey, [
            'candidate_policy_version'    => (int) $period['policy_version'],
            'charging_strategy'           => 'unresolved',
            'normalized_input_char_count' => $normalizedInputCharCount,
            'hypothetical_band'           => null,
            'hypothetical_credits'        => null,
            'simulation_status'           => self::STATUS_ERROR,
            'resolution_reason'           => "Unrecognised charging_strategy \"{$strategy}\" in configuration.",
            'source'                      => $source,
        ]);
    }

    /**
     * Phase G4C.3BC — resolves a SINGLE amount for internal shadow-accounting
     * use (the G4C.3A ledger's reserve/settle lifecycle), from whichever
     * candidate `config('ai_credit_shadow.active_candidate')` names. Reuses
     * the exact same resolvePeriod()/resolveBand() logic simulate() already
     * uses — no second pricing engine, no new calculation. Returns null
     * (never a guess) when shadow accounting is disabled (`active_candidate`
     * is null), the named candidate doesn't exist for this workflow, or the
     * candidate's own resolution is unresolved/unavailable for this instant/
     * size — the caller (AiCreditWorkflowLifecycle) records that explicitly
     * as shadow_enforcement_result = 'unresolved', never silently.
     *
     * This selection is for INTERNAL SHADOW ACCOUNTING ONLY — it is not
     * founder approval and does not make the named candidate an approved
     * commercial rate. See config/ai_credit_shadow.php.
     */
    public function resolveShadowAmount(string $workflow, int $normalizedInputCharCount, DateTimeInterface $at): ?float
    {
        $candidateKey = config('ai_credit_shadow.active_candidate');

        if (!$candidateKey) {
            return null;
        }

        $periods = config("ai_credit_simulation_policies.{$workflow}.{$candidateKey}");

        if (!is_array($periods)) {
            return null;
        }

        $period = $this->resolvePeriod($periods, $at);

        if ($period === null) {
            return null;
        }

        $strategy = $period['strategy'] ?? 'unresolved';

        if ($strategy === 'flat') {
            return isset($period['flat_credits']) ? (float) $period['flat_credits'] : null;
        }

        if ($strategy === 'banded') {
            $band = $this->resolveBand($period['bands'] ?? [], $normalizedInputCharCount);

            return $band === null ? null : (float) $band['credits'];
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $periods
     */
    private function resolvePeriod(array $periods, DateTimeInterface $at): ?array
    {
        $instant = CarbonImmutable::instance($at);

        foreach ($periods as $period) {
            $from = CarbonImmutable::parse($period['effective_from'])->startOfDay();

            if ($instant->lt($from)) {
                continue;
            }

            if (($period['effective_until'] ?? null) !== null) {
                $until = CarbonImmutable::parse($period['effective_until'])->endOfDay();

                if ($instant->gt($until)) {
                    continue;
                }
            }

            return $period;
        }

        return null;
    }

    /**
     * @param array<int, array{label: string, max_chars: ?int, credits: int|float}> $bands
     */
    private function resolveBand(array $bands, int $normalizedInputCharCount): ?array
    {
        foreach ($bands as $band) {
            if ($band['max_chars'] === null || $normalizedInputCharCount <= $band['max_chars']) {
                return $band;
            }
        }

        return null;
    }

    /**
     * @param array{
     *     candidate_policy_version: int,
     *     charging_strategy: string,
     *     normalized_input_char_count: ?int,
     *     hypothetical_band: ?string,
     *     hypothetical_credits: ?float,
     *     simulation_status: string,
     *     resolution_reason: ?string,
     *     source: string,
     * } $attributes
     */
    private function recordResult(Model $analysable, string $workflow, string $candidateKey, array $attributes): void
    {
        AiCreditSimulationResult::query()->updateOrCreate(
            [
                'analysable_type'        => $analysable::class,
                'analysable_id'          => $analysable->getKey(),
                'candidate_policy_key'   => $candidateKey,
                'candidate_policy_version' => $attributes['candidate_policy_version'],
                'normalization_version'  => AiInputNormalizer::VERSION,
            ],
            [
                'workflow'                    => $workflow,
                'organization_id'             => $analysable->organization_id,
                'charging_strategy'           => $attributes['charging_strategy'],
                'normalized_input_char_count' => $attributes['normalized_input_char_count'],
                'hypothetical_band'           => $attributes['hypothetical_band'],
                'hypothetical_credits'        => $attributes['hypothetical_credits'],
                'simulation_status'           => $attributes['simulation_status'],
                'resolution_reason'           => $attributes['resolution_reason'],
                'source'                      => $attributes['source'],
                'calculated_at'               => now(),
            ]
        );
    }
}

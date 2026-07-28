<?php

namespace App\Services\AI;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;

/**
 * Resolves effective-dated, per-model AI provider pricing (Phase
 * G4C.1A.1) — the single authoritative source
 * ContractAnalysisService::estimateCost() reads from. Config lives in
 * config/ai_pricing.php.
 *
 * Rate selection is always keyed off an explicit, caller-supplied instant
 * (the provider-call timestamp), never the current date/time — so a
 * historical analysis's cost can be recomputed later (e.g. a future audit
 * or backfill) and still get the rate that was actually in effect when the
 * provider call happened, not whatever rate happens to be current when the
 * recomputation runs.
 */
class AiPricingSchedule
{
    /**
     * Returns ['input_per_million' => float, 'output_per_million' => float]
     * for the given model at the given instant, or null if the model has no
     * configured schedule, or no period in its schedule covers that instant.
     * Callers MUST treat null as "cost cannot be safely calculated" and skip
     * persisting a cost rather than guessing a rate — an invented number is
     * a worse failure mode than a null one.
     */
    public function rateFor(string $model, DateTimeInterface $at): ?array
    {
        $periods = config("ai_pricing.{$model}");

        if (!is_array($periods) || $periods === []) {
            Log::warning('AiPricingSchedule: no pricing schedule configured for model', ['model' => $model]);
            return null;
        }

        $instant = CarbonImmutable::instance($at);

        foreach ($periods as $period) {
            $from = CarbonImmutable::parse($period['effective_from'])->startOfDay();

            if ($instant->lt($from)) {
                continue;
            }

            if ($period['effective_until'] !== null) {
                $until = CarbonImmutable::parse($period['effective_until'])->endOfDay();

                if ($instant->gt($until)) {
                    continue;
                }
            }

            return [
                'input_per_million'  => (float) $period['input_per_million'],
                'output_per_million' => (float) $period['output_per_million'],
            ];
        }

        Log::warning('AiPricingSchedule: no configured pricing period covers the given instant', [
            'model' => $model,
            'at'    => $instant->toIso8601String(),
        ]);

        return null;
    }
}

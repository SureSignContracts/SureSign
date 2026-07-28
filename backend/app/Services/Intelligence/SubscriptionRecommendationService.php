<?php

namespace App\Services\Intelligence;

use App\Models\Organization;
use App\Models\Subscription;
use App\Support\Billing\SubscriptionStatus;

/**
 * Phase G3, Stage 12 — intelligent commercial recommendations derived
 * strictly from real usage/trial data already computed by
 * `UsageMetricsService` and the subscription's own fields. Deliberately
 * conservative: capped at a handful of recommendations, each backed by a
 * concrete number or date, never generic upsell copy ("no marketing
 * spam" per the brief). No recommendation is ever persisted or acted on
 * automatically — this is display-only, same as the rest of this phase.
 */
class SubscriptionRecommendationService
{
    private const MAX_RECOMMENDATIONS = 5;
    private const TRIAL_ENDING_SOON_DAYS = 3;
    private const UNUSED_CAPACITY_THRESHOLD_PERCENT = 10.0;

    public function __construct(private readonly UsageMetricsService $usageMetrics)
    {
    }

    public function recommendationsForOrganization(Organization $organization, ?Subscription $subscription): array
    {
        $recommendations = [];
        $usage = $this->usageMetrics->usageForOrganization($organization);

        foreach ($usage as $metric) {
            if ($metric['is_unlimited'] || $metric['percent_used'] === null) {
                continue;
            }

            if ($metric['percent_used'] >= 80) {
                $recommendations[] = [
                    'key' => "upgrade.{$metric['feature_key']}",
                    'title' => "{$metric['display_name']} upgrade recommended",
                    'detail' => "You've used {$metric['percent_used']}% of your {$metric['display_name']} allowance. Consider upgrading your plan to avoid disruption.",
                    'severity' => $metric['percent_used'] >= 100 ? 'high' : 'medium',
                ];
            } elseif ($metric['percent_used'] < self::UNUSED_CAPACITY_THRESHOLD_PERCENT && $subscription !== null && $subscription->status === SubscriptionStatus::ACTIVE) {
                $recommendations[] = [
                    'key' => "unused.{$metric['feature_key']}",
                    'title' => "Unused {$metric['display_name']} capacity",
                    'detail' => "You're only using {$metric['percent_used']}% of your {$metric['display_name']} allowance — your current plan may have more headroom than you need.",
                    'severity' => 'low',
                ];
            }
        }

        if ($subscription !== null && $subscription->trial_ends_at !== null && $subscription->status === SubscriptionStatus::TRIALING) {
            $daysRemaining = now()->startOfDay()->diffInDays($subscription->trial_ends_at->startOfDay(), false);

            if ($daysRemaining >= 0 && $daysRemaining <= self::TRIAL_ENDING_SOON_DAYS) {
                $recommendations[] = [
                    'key' => 'trial.ending_soon',
                    'title' => 'Trial ending soon',
                    'detail' => $daysRemaining === 0
                        ? 'Your trial ends today — choose a plan to keep access without interruption.'
                        : "Your trial ends in {$daysRemaining} day" . ($daysRemaining === 1 ? '' : 's') . ' — choose a plan to keep access without interruption.',
                    'severity' => 'high',
                ];
            }
        }

        // High-severity first, then medium, then low — the dashboard shows
        // what matters most without the caller needing its own sort logic.
        usort($recommendations, fn ($a, $b) => $this->severityRank($b['severity']) <=> $this->severityRank($a['severity']));

        return array_slice($recommendations, 0, self::MAX_RECOMMENDATIONS);
    }

    private function severityRank(string $severity): int
    {
        return match ($severity) {
            'high' => 2,
            'medium' => 1,
            default => 0,
        };
    }
}

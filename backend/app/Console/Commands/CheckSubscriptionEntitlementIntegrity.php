<?php

namespace App\Console\Commands;

use App\Services\Entitlements\EntitlementSnapshotIntegrityService;
use App\Support\Entitlements\SnapshotIntegrityClassification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Subscription Suspension Completion, Snapshot Integrity & Commercial
 * Automation Hardening checkpoint — a manual/on-demand operational tool,
 * NOT registered on the scheduler (deliberately — see class docblock on
 * `EntitlementSnapshotIntegrityService` and the checkpoint documentation
 * for why: repeated hourly logging of the same persistent ambiguous
 * finding would be exactly the "noisy duplicated logs" the checkpoint
 * brief warns against, and nothing about missing-snapshot detection is
 * time-critical the way webhook/lifecycle automation is).
 *
 * Default behaviour is non-destructive inspection — `--repair` must be
 * passed explicitly to write anything, and even then only subscriptions
 * classified `expected_snapshot_missing_recoverable` are ever touched;
 * `expected_snapshot_missing_ambiguous` is always reported, never guessed
 * at. `--dry-run` combined with `--repair` reports what WOULD be repaired
 * without writing anything.
 */
class CheckSubscriptionEntitlementIntegrity extends Command
{
    protected $signature = 'billing:subscriptions:check-integrity
        {--repair : Repair subscriptions classified as authoritatively recoverable}
        {--dry-run : With --repair, report what would be repaired without writing anything}
        {--subscription= : Only check this specific subscriptions.id}
        {--limit=500 : Maximum subscriptions to scan}';

    protected $description = 'Report (and optionally repair) subscriptions missing a required entitlement snapshot';

    public function handle(EntitlementSnapshotIntegrityService $integrity): int
    {
        $limit = max(1, (int) ($this->option('limit') ?: 500));
        $subscriptionId = $this->option('subscription') !== null ? (int) $this->option('subscription') : null;
        $wantsRepair = (bool) $this->option('repair');
        $dryRun = (bool) $this->option('dry-run');

        $result = $integrity->check($limit, repair: $wantsRepair && !$dryRun, subscriptionId: $subscriptionId);

        foreach ($result['records'] as $record) {
            if ($record['classification'] === SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_PRESENT
                || $record['classification'] === SnapshotIntegrityClassification::NOT_APPLICABLE) {
                continue;
            }

            $line = sprintf('subscription %d (%s): %s', $record['subscription_id'], $record['status'], $record['classification']);

            if ($record['repaired_snapshot_id'] !== null) {
                $line .= " — repaired (snapshot {$record['repaired_snapshot_id']})";
            } elseif ($wantsRepair && $dryRun && $record['classification'] === SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_MISSING_RECOVERABLE) {
                $line .= ' — would repair';
            }

            $this->line($line);
        }

        $counters = $result['counters'];

        $this->info(sprintf(
            'Scanned %d: %d healthy, %d legacy fallback, %d missing (recoverable), %d missing (ambiguous), %d not applicable.',
            $counters['scanned'],
            $counters[SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_PRESENT],
            $counters[SnapshotIntegrityClassification::LEGACY_PRE_SNAPSHOT],
            $counters[SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_MISSING_RECOVERABLE],
            $counters[SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_MISSING_AMBIGUOUS],
            $counters[SnapshotIntegrityClassification::NOT_APPLICABLE],
        ));

        if ($wantsRepair) {
            $this->line($dryRun
                ? "Would repair: {$counters[SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_MISSING_RECOVERABLE]}."
                : "Repaired: {$counters['repaired']}, repair failed: {$counters['repair_failed']}.");
        }

        Log::info('billing:subscriptions:check-integrity completed', ['counters' => $counters]);

        return $counters['repair_failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

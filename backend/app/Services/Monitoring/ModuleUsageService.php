<?php

namespace App\Services\Monitoring;

use App\Models\User;
use App\Services\TimezoneResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Throttles and records module-level usage into the `module_usage_daily`
 * aggregate table. Redis provides the short-lived throttle and daily
 * dedup checks that decide *whether* to write; the database aggregate is
 * the durable, queryable record — see the migration for why there is no
 * separate platform-wide row (organization rows are summed at read time
 * instead).
 *
 * Nothing here writes to the database on every request: a visit only
 * reaches the aggregate table once per user/module every
 * VISIT_THROTTLE_SECONDS, and even then it is a single atomic
 * INSERT ... ON DUPLICATE KEY UPDATE.
 */
class ModuleUsageService
{
    private const VISIT_THROTTLE_PREFIX = 'monitoring:usage:throttle:';
    private const UNIQUE_PREFIX         = 'monitoring:usage:unique:';
    private const DAILY_ACTIVE_PREFIX   = 'monitoring:usage:daily-active:';

    public const VISIT_THROTTLE_SECONDS = 300;       // one counted visit per user/module/5min
    public const UNIQUE_KEY_TTL_SECONDS = 36 * 3600;  // outlives the reporting day with margin

    /**
     * Record a throttled, deduplicated module visit. Safe to call on every
     * matching request — internally a no-op except at most once per
     * VISIT_THROTTLE_SECONDS per user/module. Never throws.
     */
    public function recordVisit(User $user, string $moduleKey): void
    {
        try {
            $date = TimezoneResolver::today()->toDateString();

            $this->recordDailyActiveUser($user, $date);

            $throttleKey = self::VISIT_THROTTLE_PREFIX . $user->id . ':' . $moduleKey;
            $claimed = Redis::set($throttleKey, '1', 'EX', self::VISIT_THROTTLE_SECONDS, 'NX');

            if (! $claimed) {
                return;
            }

            $organizationId = $user->organization_id;
            if (! $organizationId) {
                // Module usage is reported per organization; a user without
                // one (shouldn't normally happen for Super Admin/Admin
                // accounts making it this far) has nothing to aggregate into.
                return;
            }

            $uniqueKey = self::UNIQUE_PREFIX . "{$date}:{$moduleKey}:{$organizationId}:{$user->id}";
            $isFirstToday = (bool) Redis::set($uniqueKey, '1', 'EX', self::UNIQUE_KEY_TTL_SECONDS, 'NX');

            $this->upsertAggregate($date, $moduleKey, $organizationId, $isFirstToday);
        } catch (Throwable $e) {
            Log::warning('ModuleUsageService: failed to record module visit', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Mark this user as active today — the durable source for DAU/WAU/MAU.
     * Gated by a Redis dedup key (not the per-module throttle above), so a
     * user is marked at most once per day regardless of how many different
     * modules they visit. The underlying insert is idempotent
     * (INSERT IGNORE on a unique key) so a Redis key that outlives the
     * calendar day, a retried request, or two replicas racing on the same
     * key never produces a duplicate row.
     */
    private function recordDailyActiveUser(User $user, string $date): void
    {
        $dailyKey = self::DAILY_ACTIVE_PREFIX . $date . ':' . $user->id;
        $isFirstToday = (bool) Redis::set($dailyKey, '1', 'EX', self::UNIQUE_KEY_TTL_SECONDS, 'NX');

        if (! $isFirstToday) {
            return;
        }

        // Belt-and-braces: the Redis dedup key is what normally prevents a
        // second write, but the unique index is what actually guarantees no
        // duplicate row, in case two replicas race on the same Redis check.
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement(
                'INSERT INTO daily_active_users (activity_date, user_id, organization_id, created_at, updated_at)
                 VALUES (?, ?, ?, datetime("now"), datetime("now"))
                 ON CONFLICT(activity_date, user_id) DO NOTHING',
                [$date, $user->id, $user->organization_id]
            );
        } else {
            DB::statement(
                'INSERT IGNORE INTO daily_active_users (activity_date, user_id, organization_id, created_at, updated_at)
                 VALUES (?, ?, ?, NOW(), NOW())',
                [$date, $user->id, $user->organization_id]
            );
        }
    }

    /**
     * Atomic upsert — a single statement per visit, safe under concurrent
     * writes from multiple backend replicas. Avoids any read-then-write
     * race: total_visits always increments by 1, unique_users only
     * increments when this call already proved (via the Redis dedup key)
     * that this is the user's first visit to this module/org today.
     */
    private function upsertAggregate(string $date, string $moduleKey, int $organizationId, bool $isFirstToday): void
    {
        $uniqueIncrement = $isFirstToday ? 1 : 0;

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement(
                'INSERT INTO module_usage_daily (usage_date, module_key, organization_id, total_visits, unique_users, created_at, updated_at)
                 VALUES (?, ?, ?, 1, ?, datetime("now"), datetime("now"))
                 ON CONFLICT(usage_date, module_key, organization_id) DO UPDATE SET
                    total_visits = total_visits + 1,
                    unique_users = unique_users + excluded.unique_users,
                    updated_at = datetime("now")',
                [$date, $moduleKey, $organizationId, $uniqueIncrement]
            );
        } else {
            // MySQL 8.0.19 deprecated the VALUES() function inside
            // ON DUPLICATE KEY UPDATE in favor of a row alias — production
            // runs mysql:8.0 (see docker-compose.prod.yml), so this uses the
            // non-deprecated form rather than logging a deprecation warning
            // on every write.
            DB::statement(
                'INSERT INTO module_usage_daily (usage_date, module_key, organization_id, total_visits, unique_users, created_at, updated_at)
                 VALUES (?, ?, ?, 1, ?, NOW(), NOW()) AS new_row
                 ON DUPLICATE KEY UPDATE
                    total_visits = module_usage_daily.total_visits + 1,
                    unique_users = module_usage_daily.unique_users + new_row.unique_users,
                    updated_at = NOW()',
                [$date, $moduleKey, $organizationId, $uniqueIncrement]
            );
        }
    }

    /**
     * Module usage totals for a date range, summed across organizations
     * (platform-wide view — there is no stored platform row, see above).
     *
     * The `active_user_days` field is a SUM of each day's distinct-user
     * count over the range, not a true period-distinct count — a user
     * active on 3 different days within the range contributes 3, not 1.
     * This is a direct, honest consequence of the storage model (daily
     * aggregates only, no per-user historical identity retained beyond a
     * ~36h Redis dedup key) and is why the field is named "user-days"
     * rather than "unique users" — for a single-day range (`today`) the
     * two concepts happen to coincide, but they diverge for any
     * multi-day range. See ModuleUsageResolver/the internal monitoring doc
     * for the full explanation.
     *
     * @return array<int, array{module_key:string,total_visits:int,active_user_days:int}>
     */
    public function getUsageForRange(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return DB::table('module_usage_daily')
            ->selectRaw('module_key, SUM(total_visits) as total_visits, SUM(unique_users) as active_user_days')
            ->whereBetween('usage_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->groupBy('module_key')
            ->orderByDesc('total_visits')
            ->get()
            ->map(fn ($row) => [
                'module_key'       => $row->module_key,
                'label'            => ModuleUsageResolver::label($row->module_key),
                'total_visits'     => (int) $row->total_visits,
                'active_user_days' => (int) $row->active_user_days,
            ])
            ->all();
    }
}

<?php

namespace App\Services\Monitoring;

use App\Models\ContractAiAnalysis;
use App\Models\FileUpload;
use App\Models\SuresignNotification;
use App\Models\TradePackageAiAnalysis;
use App\Services\TimezoneResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Aggregates everything shown on the Super Admin Application Monitoring
 * page into a single payload. Each section is fetched independently and
 * wrapped so one failing/unavailable source never breaks the rest — a
 * failure is recorded in `warnings`/`unavailable_sources` instead of
 * bubbling into a 500.
 *
 * "Today"/"this week"/"this month" boundaries all use the platform
 * default timezone via TimezoneResolver (no user/org passed — this is a
 * cross-organization platform view, not scoped to any one org), never the
 * server's own UTC day.
 */
class ApplicationMonitoringService
{
    public function __construct(
        private readonly UserPresenceService $presence,
        private readonly ModuleUsageService $usage,
    ) {
    }

    public function summary(): array
    {
        $warnings = [];
        $unavailable = [];

        $now = TimezoneResolver::now();
        $today = TimezoneResolver::today();

        $presenceBlock = $this->presenceBlock($warnings, $unavailable);
        $activeUsers = $this->activeUsersBlock($today, $warnings, $unavailable);
        $moduleUsage = $this->moduleUsageBlock($today, $warnings, $unavailable);
        $queue = $this->queueBlock($warnings, $unavailable);
        $ai = $this->aiBlock($today, $warnings, $unavailable);
        $documents = $this->documentsBlock($today, $warnings, $unavailable);
        $notifications = $this->notificationsBlock($today, $warnings, $unavailable);

        return [
            'generated_at' => $now->toIso8601String(),
            'timezone'     => $now->getTimezone()->getName(),
            'presence_definition' => 'A user is considered online if the platform recorded meaningful '
                . 'authenticated activity from them within the last 5 minutes. Presence is refreshed at '
                . 'most once every 60 seconds per user and is not cleared by logout, so a closed tab '
                . 'remains "online" for up to 5 minutes after the last request.',
            'presence'           => $presenceBlock,
            'active_users'       => $activeUsers,
            'module_usage'       => $moduleUsage,
            'application_actions' => $this->applicationActionsBlock($now, $today, $warnings, $unavailable),
            'queue'              => $queue,
            'ai'                 => $ai,
            'documents'          => $documents,
            'notifications'      => $notifications,
            'warnings'           => $warnings,
            'unavailable_sources' => $unavailable,
        ];
    }

    private function presenceBlock(array &$warnings, array &$unavailable): array
    {
        $available = $this->presence->isAvailable();

        if (! $available) {
            $unavailable[] = 'presence';
            $warnings[] = 'Live presence is unavailable — Redis could not be reached. '
                . 'Online-user counts are not shown; this does not mean zero users are online.';

            return [
                'available' => false,
                'online_count' => null,
                'active_organizations_count' => null,
                'authenticated_activity_last_15_min' => null,
                'online_users' => [],
            ];
        }

        $onlineUsers = $this->presence->getOnlineUsers() ?? [];

        return [
            'available' => true,
            'online_count' => count($onlineUsers),
            'active_organizations_count' => $this->presence->getActiveOrganizationsCount(),
            'authenticated_activity_last_15_min' => $this->presence->getRecentActivityCount(900),
            'online_users' => array_map(fn ($u) => [
                'user_id'           => $u['user_id'] ?? null,
                'name'              => $u['name'] ?? null,
                'email'             => $u['email'] ?? null,
                'role'              => $u['role'] ?? null,
                'organization_id'   => $u['organization_id'] ?? null,
                'organization_name' => $u['organization_name'] ?? null,
                'module_key'        => $u['module_key'] ?? null,
                'module_label'      => isset($u['module_key']) && $u['module_key']
                    ? ModuleUsageResolver::label($u['module_key'])
                    : null,
                'last_active_at'    => isset($u['last_active_at'])
                    ? TimezoneResolver::now()->setTimestamp($u['last_active_at'])->toIso8601String()
                    : null,
            ], $onlineUsers),
        ];
    }

    private function activeUsersBlock(\Carbon\Carbon $today, array &$warnings, array &$unavailable): array
    {
        try {
            $sevenDaysAgo = $today->copy()->subDays(6);
            $thirtyDaysAgo = $today->copy()->subDays(29);

            // Plain equality/range comparisons, not whereDate() — activity_date
            // is already a DATE column, so wrapping it in DATE(...) would only
            // add an unnecessary function call that can stand in the way of an
            // index range scan on some query plans.
            $dau = DB::table('daily_active_users')->where('activity_date', $today->toDateString())->distinct('user_id')->count('user_id');
            $wau = DB::table('daily_active_users')->where('activity_date', '>=', $sevenDaysAgo->toDateString())->distinct('user_id')->count('user_id');
            $mau = DB::table('daily_active_users')->where('activity_date', '>=', $thirtyDaysAgo->toDateString())->distinct('user_id')->count('user_id');

            $trend = DB::table('daily_active_users')
                ->selectRaw('activity_date, COUNT(DISTINCT user_id) as active_users')
                ->where('activity_date', '>=', $sevenDaysAgo->toDateString())
                ->groupBy('activity_date')
                ->orderBy('activity_date')
                ->get()
                ->map(fn ($row) => ['date' => (string) $row->activity_date, 'active_users' => (int) $row->active_users])
                ->all();

            return ['dau' => $dau, 'wau' => $wau, 'mau' => $mau, 'daily_trend' => $trend];
        } catch (Throwable $e) {
            Log::warning('ApplicationMonitoringService: active_users block failed', ['error' => $e->getMessage()]);
            $warnings[] = 'Active-user trend (DAU/WAU/MAU) is temporarily unavailable.';
            $unavailable[] = 'active_users';
            return ['dau' => null, 'wau' => null, 'mau' => null, 'daily_trend' => []];
        }
    }

    private function moduleUsageBlock(\Carbon\Carbon $today, array &$warnings, array &$unavailable): array
    {
        try {
            return [
                'today'        => $this->usage->getUsageForRange($today, $today),
                'last_7_days'  => $this->usage->getUsageForRange($today->copy()->subDays(6), $today),
                'last_30_days' => $this->usage->getUsageForRange($today->copy()->subDays(29), $today),
                'active_user_days_definition' => 'Active user-days is the sum of each day\'s distinct '
                    . 'users during the selected period. A user active on multiple days contributes once '
                    . 'per day, so this is not the same as the number of distinct people who used a module '
                    . 'over the whole period.',
            ];
        } catch (Throwable $e) {
            Log::warning('ApplicationMonitoringService: module_usage block failed', ['error' => $e->getMessage()]);
            $warnings[] = 'Module usage analytics are temporarily unavailable.';
            $unavailable[] = 'module_usage';
            return ['today' => [], 'last_7_days' => [], 'last_30_days' => [], 'active_user_days_definition' => null];
        }
    }

    private function applicationActionsBlock(\Carbon\Carbon $now, \Carbon\Carbon $today, array &$warnings, array &$unavailable): array
    {
        try {
            return [
                'last_15_minutes' => DB::table('activity_logs')->where('created_at', '>=', $now->copy()->subMinutes(15))->count(),
                'last_hour'       => DB::table('activity_logs')->where('created_at', '>=', $now->copy()->subHour())->count(),
                'today'           => DB::table('activity_logs')->whereDate('created_at', $today->toDateString())->count(),
            ];
        } catch (Throwable $e) {
            Log::warning('ApplicationMonitoringService: application_actions block failed', ['error' => $e->getMessage()]);
            $warnings[] = 'Application action counts are temporarily unavailable.';
            $unavailable[] = 'application_actions';
            return ['last_15_minutes' => null, 'last_hour' => null, 'today' => null];
        }
    }

    private function queueBlock(array &$warnings, array &$unavailable): array
    {
        try {
            $pending = DB::table('jobs')->count();
            $oldestPending = DB::table('jobs')->min('available_at');
            $failedTotal = DB::table('failed_jobs')->count();
            $failed24h = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

            $status = 'healthy';
            if ($failed24h > 0) {
                $status = 'attention';
            }
            if ($oldestPending && (time() - (int) $oldestPending) > 1800) {
                $status = 'attention'; // a job has been waiting 30+ minutes
            }

            return [
                'pending_jobs'          => $pending,
                'failed_jobs_total'     => $failedTotal,
                'failed_jobs_24h'       => $failed24h,
                'oldest_pending_job_age_seconds' => $oldestPending ? max(0, time() - (int) $oldestPending) : null,
                'status'                => $status,
            ];
        } catch (Throwable $e) {
            Log::warning('ApplicationMonitoringService: queue block failed', ['error' => $e->getMessage()]);
            $warnings[] = 'Queue health is temporarily unavailable.';
            $unavailable[] = 'queue';
            return ['pending_jobs' => null, 'failed_jobs_total' => null, 'failed_jobs_24h' => null, 'oldest_pending_job_age_seconds' => null, 'status' => 'unknown'];
        }
    }

    private function aiBlock(\Carbon\Carbon $today, array &$warnings, array &$unavailable): array
    {
        try {
            // trade_package_ai_analyses has per-state timestamps; contract_ai_analyses
            // only has created_at/updated_at (see known-limitations doc). "started
            // today"/"completed today"/"failed today" combine both tables, using the
            // most precise timestamp each table actually has.
            $pending = ContractAiAnalysis::where('status', 'pending')->count()
                + TradePackageAiAnalysis::where('status', 'pending')->count();
            $processing = ContractAiAnalysis::where('status', 'processing')->count()
                + TradePackageAiAnalysis::where('status', 'processing')->count();

            $startedToday = ContractAiAnalysis::whereDate('created_at', $today->toDateString())->count()
                + TradePackageAiAnalysis::whereDate('created_at', $today->toDateString())->count();

            $completedToday = ContractAiAnalysis::where('status', 'completed')->whereDate('updated_at', $today->toDateString())->count()
                + TradePackageAiAnalysis::where('status', 'completed')->whereDate('completed_at', $today->toDateString())->count();

            $failedToday = ContractAiAnalysis::where('status', 'failed')->whereDate('updated_at', $today->toDateString())->count()
                + TradePackageAiAnalysis::where('status', 'failed')->whereDate('updated_at', $today->toDateString())->count();

            // "Stuck" — processing far longer than a real analysis ever takes.
            $stuckCutoff = now()->subMinutes(20);
            $stuckCount = ContractAiAnalysis::where('status', 'processing')->where('updated_at', '<', $stuckCutoff)->count()
                + TradePackageAiAnalysis::where('status', 'processing')->where('started_at', '<', $stuckCutoff)->count();

            $oldestProcessing = TradePackageAiAnalysis::where('status', 'processing')->min('started_at');

            return [
                'pending'                 => $pending,
                'processing'              => $processing,
                'started_today'           => $startedToday,
                'completed_today'         => $completedToday,
                'failed_today'            => $failedToday,
                'stuck_count'             => $stuckCount,
                'oldest_processing_started_at' => $oldestProcessing,
                'timestamp_limitation' => 'contract_ai_analyses has no per-state timestamps beyond '
                    . 'created_at/updated_at, so its "completed today"/"failed today" figures use '
                    . 'updated_at and may be imprecise if a record changes state more than once in a day.',
            ];
        } catch (Throwable $e) {
            Log::warning('ApplicationMonitoringService: ai block failed', ['error' => $e->getMessage()]);
            $warnings[] = 'AI analysis status is temporarily unavailable.';
            $unavailable[] = 'ai';
            return [
                'pending' => null, 'processing' => null, 'started_today' => null,
                'completed_today' => null, 'failed_today' => null, 'stuck_count' => null,
                'oldest_processing_started_at' => null, 'timestamp_limitation' => null,
            ];
        }
    }

    private function documentsBlock(\Carbon\Carbon $today, array &$warnings, array &$unavailable): array
    {
        try {
            return [
                'uploaded_today'  => FileUpload::whereDate('created_at', $today->toDateString())->count(),
                'generated_today' => DB::table('documents')->whereDate('created_at', $today->toDateString())->count(),
            ];
        } catch (Throwable $e) {
            Log::warning('ApplicationMonitoringService: documents block failed', ['error' => $e->getMessage()]);
            $warnings[] = 'Document activity is temporarily unavailable.';
            $unavailable[] = 'documents';
            return ['uploaded_today' => null, 'generated_today' => null];
        }
    }

    private function notificationsBlock(\Carbon\Carbon $today, array &$warnings, array &$unavailable): array
    {
        try {
            return [
                'created_today' => SuresignNotification::whereDate('created_at', $today->toDateString())->count(),
                'unread_total'  => SuresignNotification::where('is_read', false)->count(),
            ];
        } catch (Throwable $e) {
            Log::warning('ApplicationMonitoringService: notifications block failed', ['error' => $e->getMessage()]);
            $warnings[] = 'Notification counts are temporarily unavailable.';
            $unavailable[] = 'notifications';
            return ['created_today' => null, 'unread_total' => null];
        }
    }
}

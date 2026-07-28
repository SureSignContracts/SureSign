<?php

namespace App\Services\Monitoring;

use Illuminate\Http\Request;

/**
 * Translates an authenticated API request into a stable, coarse-grained
 * module key for usage reporting — never a raw URL, record id, or query
 * string. Named routes cover only a handful of endpoints in this codebase
 * (see routes/api.php), so resolution walks the request path segments and
 * matches the first segment found in MODULE_MAP. Numeric/dynamic segments
 * (ids) never match and are skipped automatically, which is what keeps
 * `/projects/482/contracts/17` and `/projects/9/contracts/3` resolving to
 * the same `contracts` key instead of fragmenting per record.
 *
 * Routes not yet represented in MODULE_MAP resolve to null rather than a
 * guessed key — new modules are added here explicitly so historical rows
 * are never reinterpreted.
 */
class ModuleUsageResolver
{
    /**
     * Path segment => stable module key. Order does not matter; the first
     * segment of the request path present as a key here wins.
     */
    private const MODULE_MAP = [
        'dashboard'                => 'dashboard',
        'projects'                 => 'projects',
        'commercial'               => 'commercial',
        'documents'                => 'documents',
        'document-register'        => 'documents',
        'reports'                  => 'reports',
        'site-administration'      => 'site_admin',
        'contracts'                => 'contracts',
        'trade-packages'           => 'trade_packages',
        'variations'               => 'variations',
        'payment-applications'     => 'payment_applications',
        'payment-notices'          => 'payment_notices',
        'pay-less-notices'         => 'pay_less_notices',
        'final-account'            => 'final_accounts',
        'final-accounts'           => 'final_accounts',
        'programme'                => 'programme',
        'risks'                    => 'risks',
        'meetings'                 => 'meetings',
        'site-diaries'             => 'site_reports',
        'site-instructions'        => 'site_reports',
        'rfis'                     => 'site_reports',
        'qa-reports'               => 'site_reports',
        // Fixed: the real route resource is 'snagging' (apiResource('snagging', ...)) —
        // 'snags' matched no real route and this bucket has never been counted.
        'snagging'                 => 'site_reports',
        'delay-events'             => 'delay_events',
        'eot-requests'             => 'eot_requests',
        'loss-and-expense-claims'  => 'loss_and_expense',
        'retention-releases'       => 'commercial',
        'closeout'                 => 'reports',
        'adjudication'             => 'reports',
        'organization'             => 'settings',
        'organizations'            => 'settings',
        'suresign-settings'        => 'settings',
        'admin'                    => 'super_admin',

        // Added — real routes previously falling through to null (see
        // ModuleUsageService/App Monitoring known-limitations note).
        'appointments'             => 'appointments',
        'appointment-types'        => 'appointments',
        'appointment-availability' => 'appointments',
        'clients'                  => 'clients',
        'users'                    => 'users',
        // Prompt Library: covers both /prompts/{template}/render (customer
        // copy/render action) and /admin/prompts/... (category/template
        // management) — same feature, one bucket, matching this map's
        // existing coarse-grained convention.
        'prompts'                  => 'prompt_library',
        // Contract AI Analysis detail-view actions (status/analyses/
        // confirm/cancel/reparse/generate-brief) under the top-level /ai
        // prefix — distinct from the nested /contracts/{id}/ai-analysis
        // and /trade-packages/{id}/ai-analysis start actions, which
        // deliberately stay bucketed under 'contracts'/'trade_packages'
        // (coarser, but already counted — not a gap).
        'ai'                       => 'ai_analysis',
        // Organisation-facing Subscription & Billing (/billing/...).
        'billing'                  => 'billing',
        // Super Admin/Admin Pricing Management (/admin/pricing/...) —
        // distinct from the public, unauthenticated marketing /pricing
        // page, which this middleware never sees (fires only when
        // $request->user() exists — see TrackApplicationUsage).
        'pricing'                  => 'pricing_management',
        // Internal AI Usage & Cost reporting (/admin/ai-telemetry/...).
        'ai-telemetry'             => 'ai_telemetry',

        // Deliberately NOT mapped: bare 'templates' is ambiguous — the
        // same segment appears in both /admin/templates (Document
        // Templates CRUD) and /admin/prompts/templates (Prompt Library
        // templates), and this resolver's "last matching segment wins"
        // rule would let one silently mislabel the other. Left unmapped
        // (falls to the 'admin'/'prompts' segment that precedes it,
        // matching its pre-existing behaviour) rather than guessing.
    ];

    /**
     * Path prefixes that must never count as module usage or presence —
     * health/readiness checks, polling endpoints that fire regardless of
     * genuine user interaction, and the monitoring surface itself (so a
     * Super Admin watching this page doesn't inflate its own metrics).
     */
    private const EXCLUDED_PREFIXES = [
        'up',
        'notifications',
        'admin/application-monitoring',
        'auth/me',
        'calendar-events',
    ];

    public static function resolve(Request $request): ?string
    {
        $path = trim($request->path(), '/');

        if ($path === '' || $path === 'api') {
            return null;
        }

        // Strip a leading "api/" — routes are defined without it, but
        // $request->path() includes it.
        if (str_starts_with($path, 'api/')) {
            $path = substr($path, 4);
        }

        foreach (self::EXCLUDED_PREFIXES as $excluded) {
            if ($path === $excluded || str_starts_with($path, $excluded . '/')) {
                return null;
            }
        }

        // The *last* matching segment wins, not the first: nested routes
        // like `projects/{project}/contracts` start with the generic
        // `projects` segment but the more specific `contracts` segment
        // that follows is the more useful module to report.
        $matched = null;
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || is_numeric($segment)) {
                continue;
            }

            if (isset(self::MODULE_MAP[$segment])) {
                $matched = self::MODULE_MAP[$segment];
            }
        }

        return $matched;
    }

    /**
     * Human-readable label for a module key, for display purposes only —
     * never used as the storage/reporting key.
     */
    public static function label(string $moduleKey): string
    {
        return match ($moduleKey) {
            'dashboard'            => 'Dashboard',
            'projects'             => 'Projects',
            'commercial'           => 'Commercial',
            'documents'            => 'Documents',
            'reports'              => 'Reports',
            'site_admin'           => 'Site Admin',
            'contracts'            => 'Contracts',
            'trade_packages'       => 'Trade Packages',
            'variations'           => 'Variations',
            'payment_applications' => 'Payment Applications',
            'payment_notices'      => 'Payment Notices',
            'pay_less_notices'     => 'Pay Less Notices',
            'final_accounts'       => 'Final Accounts',
            'programme'            => 'Programme',
            'risks'                => 'Risks',
            'meetings'             => 'Meetings',
            'site_reports'         => 'Site Reports',
            'delay_events'         => 'Delay Events',
            'eot_requests'         => 'EOT Requests',
            'loss_and_expense'     => 'Loss and Expense',
            'settings'             => 'Settings',
            'super_admin'          => 'Super Admin',
            'appointments'         => 'Appointments',
            'clients'              => 'Clients',
            'users'                => 'Users',
            'prompt_library'       => 'Prompt Library',
            'ai_analysis'          => 'AI Analysis',
            'billing'              => 'Billing',
            'pricing_management'   => 'Pricing Management',
            'ai_telemetry'         => 'AI Usage & Cost',
            default                => ucfirst(str_replace('_', ' ', $moduleKey)),
        };
    }
}

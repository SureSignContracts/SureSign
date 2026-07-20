<?php

namespace App\Services\TradePackages;

/**
 * Single source of truth for mapping an operational source_type (payment
 * application, programme milestone, delay event, etc.) to the Trade Package
 * Workspace tab/sub-tab it lives on, and for building the action_url a
 * dashboard/calendar/notification consumer can navigate to.
 *
 * Consolidates what used to be three near-duplicate classification maps
 * (CalendarController::tradePackageEvents(), TradePackageActivityService,
 * DocumentController::classifyDocumentSource()).
 */
class WorkspaceNavigationResolver
{
    private const TAB_MAP = [
        'payment_application'    => ['tab' => 'commercial'],
        'retention_release'      => ['tab' => 'commercial'],
        'final_account'          => ['tab' => 'commercial'],
        'payment_notice'         => ['tab' => 'commercial'],
        'pay_less_notice'        => ['tab' => 'commercial'],
        'variation'              => ['tab' => 'commercial'],
        'programme_milestone'    => ['tab' => 'programme'],
        'delay_event'            => ['tab' => 'delay-eot', 'subtab' => 'delay'],
        'eot_request'            => ['tab' => 'delay-eot', 'subtab' => 'eot'],
        'loss_and_expense_claim' => ['tab' => 'delay-eot', 'subtab' => 'loss-expense'],
        'contract_risk'          => ['tab' => 'compliance', 'subtab' => 'risks'],
        'delivery_document'      => ['tab' => 'compliance', 'subtab' => 'delivery-documents'],
        'trade_package'          => ['tab' => 'overview'],
        // RFIs, meetings, site diaries, site instructions and QA reports have
        // no trade_package_id column — these entries are only exercised if that ever changes;
        // today target() just needs a non-null match so actionUrl() falls
        // through to PROJECT_FALLBACK below.
        'rfi'                     => ['tab' => 'communication', 'subtab' => 'rfis'],
        'meeting'                 => ['tab' => 'communication', 'subtab' => 'meetings'],
        'site_diary'              => ['tab' => 'compliance', 'subtab' => 'site-reports'],
        'site_instruction'        => ['tab' => 'communication', 'subtab' => 'site-instructions'],
        'qa_report'               => ['tab' => 'compliance', 'subtab' => 'qa'],
        // Contract AI analyses belong to a main Contract, never a trade
        // package (subcontract AI uses the separate 'trade_package_ai_analysis'
        // source_type) — this entry exists only so target() returns non-null;
        // the trade-package branch of actionUrl() is never actually reached.
        'contract_ai_analysis'    => ['tab' => 'contracts'],
        'file_upload'             => ['tab' => 'documents'],
    ];

    /**
     * The generic (non-trade-package) project page a source_type falls back
     * to when the record belongs to a main contract rather than a package.
     */
    private const PROJECT_FALLBACK = [
        'payment_application'    => '/commercial?tab=applications',
        'retention_release'      => '/commercial?tab=applications',
        'payment_notice'         => '/commercial?tab=notices',
        'pay_less_notice'        => '/commercial?tab=notices',
        'programme_milestone'    => '/programme',
        'delay_event'            => '/delay-eot?tab=delay-events',
        'eot_request'            => '/delay-eot?tab=eot',
        'loss_and_expense_claim' => '/delay-eot?tab=loss-and-expense',
        // A dedicated /risks page now exists (no id deep-link support yet,
        // same as rfi/variation below).
        'contract_risk'          => '/risks',
        'delivery_document'      => '/delivery-documents',
        'rfi'                    => '/rfis',
        'variation'              => '/variations',
        'meeting'                => '/meetings',
        'site_diary'             => '/site-reports',
        // Site Instructions is a tab within the Notices page rather than its
        // own route (see notices/page.tsx) — that page doesn't read a `tab`
        // query param, so landing on the page itself (defaulting to its EOT
        // tab) is the same best-effort precedent as contract_ai_analysis and
        // file_upload below, not a broken link.
        'site_instruction'       => '/notices',
        'qa_report'              => '/qa',
        // No dedicated AI review/history or Contract Intelligence route
        // exists yet — the AI analysis review UI is embedded directly in the
        // Contracts list page (confirmed: no per-contract detail route and
        // no query-param deep-link support there today). This matches the
        // existing convention for contract_deadline/contract_notice in
        // NotificationEngineService's own URL_MAP, which already deep-links
        // to the same page for the same reason.
        'contract_ai_analysis'   => '/contracts',
        // Project-level Documents Explorer exists (/documents) but has no
        // folder/id deep-link support today — landing on the page itself is
        // still a genuine, correct destination (same precedent as risks,
        // rfis, meetings, site diaries, qa reports above).
        'file_upload'            => '/documents',
    ];

    /**
     * @return array{type:string,id:int,trade_package_id:?int,tab:string,subtab:?string}|null
     */
    public static function target(string $sourceType, int $sourceId, ?int $tradePackageId = null): ?array
    {
        $entry = self::TAB_MAP[$sourceType] ?? null;
        if (!$entry) {
            return null;
        }

        return [
            'type'             => $sourceType,
            'id'               => $sourceId,
            'trade_package_id' => $tradePackageId,
            'tab'              => $entry['tab'],
            'subtab'           => $entry['subtab'] ?? null,
        ];
    }

    /**
     * Builds the actual navigable URL — the Workspace URL when the record
     * belongs to a trade package, otherwise the generic project-level page.
     */
    public static function actionUrl(int $projectId, string $sourceType, int $sourceId, ?int $tradePackageId = null): ?string
    {
        $target = self::target($sourceType, $sourceId, $tradePackageId);
        if (!$target) {
            return null;
        }

        if ($tradePackageId) {
            $suffix = $target['subtab'] ? "&subtab={$target['subtab']}" : '';
            return "/app/projects/{$projectId}/subcontracts/{$tradePackageId}?tab={$target['tab']}{$suffix}";
        }

        if ($sourceType === 'final_account') {
            return "/app/projects/{$projectId}/commercial?tab=final-account&fa={$sourceId}";
        }

        $fallback = self::PROJECT_FALLBACK[$sourceType] ?? null;

        return $fallback ? "/app/projects/{$projectId}{$fallback}" : "/app/projects/{$projectId}/contracts";
    }
}

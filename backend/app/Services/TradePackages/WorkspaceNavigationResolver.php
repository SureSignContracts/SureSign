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
        // RFIs have no trade_package_id column — this tab/subtab entry is only
        // exercised if that ever changes; today target() just needs a non-null
        // match so actionUrl() falls through to PROJECT_FALLBACK below.
        'rfi'                     => ['tab' => 'communication', 'subtab' => 'rfis'],
    ];

    /**
     * The generic (non-trade-package) project page a source_type falls back
     * to when the record belongs to a main contract rather than a package.
     */
    private const PROJECT_FALLBACK = [
        'payment_application' => '/commercial?tab=applications',
        'retention_release'   => '/commercial?tab=applications',
        'programme_milestone' => '/programme',
        // No dedicated project-level risk register page exists yet — the
        // Overview page's Risk Summary widget is the only place a
        // main-contract risk is actually visible today.
        'contract_risk'       => '/overview',
        'delivery_document'   => '/delivery-documents',
        'rfi'                 => '/rfis',
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

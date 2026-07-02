<?php

namespace App\Services;

use App\Jobs\GenerateProjectNotificationsJob;
use App\Models\SuresignNotification;
use App\Models\User;

class NotificationService
{
    // ── Notification types ────────────────────────────────────────────────────
    public const DOCUMENT_GENERATED          = 'document_generated';
    public const FILE_UPLOADED               = 'file_uploaded';
    public const FILE_DELETED                = 'file_deleted';
    public const TEMPLATE_UPLOADED           = 'template_uploaded';
    public const TEMPLATE_UPDATED            = 'template_updated';
    public const TEMPLATE_DELETED            = 'template_deleted';
    public const TRADE_PACKAGE_GENERATED     = 'trade_package_generated';
    public const TRADE_PACKAGE_CREATED       = 'trade_package_created';
    public const PROJECT_CREATED             = 'project_created';
    public const PROJECT_UPDATED             = 'project_updated';
    public const SYNC_COMPLETED              = 'sync_completed';
    public const SYNC_FAILED                 = 'sync_failed';
    public const USER_INVITED                = 'user_invited';
    public const SYSTEM                      = 'system';
    public const PAYMENT_DEADLINE_APPROACHING = 'payment_deadline_approaching';
    public const AI_ANALYSIS_COMPLETED       = 'ai_analysis_completed';
    // Variation approval workflow
    public const VARIATION_SUBMITTED   = 'variation_submitted';
    public const VARIATION_INSTRUCTED  = 'variation_instructed';
    public const VARIATION_QUOTED      = 'variation_quoted';
    public const VARIATION_ASSESSED    = 'variation_assessed';
    public const VARIATION_APPROVED    = 'variation_approved';
    public const VARIATION_REJECTED    = 'variation_rejected';
    public const VARIATION_RESUBMITTED = 'variation_resubmitted';

    /**
     * Create a manual (non-operational) notification.
     *
     * Accepts optional metadata for richer display:
     *   category   — SuresignNotification::CATEGORY_*
     *   priority   — SuresignNotification::PRIORITY_*
     *   action_url — route the user should navigate to on click
     *   project_id, organization_id — for scoping
     */
    public static function send(
        User   $user,
        string $type,
        string $title,
        string $message,
        array  $data = [],
        array  $meta = [],
    ): SuresignNotification {
        return SuresignNotification::create([
            'user_id'         => $user->id,
            'organization_id' => $meta['organization_id'] ?? null,
            'project_id'      => $meta['project_id']      ?? null,
            'type'            => $type,
            'category'        => $meta['category']        ?? SuresignNotification::CATEGORY_GENERAL,
            'priority'        => $meta['priority']        ?? SuresignNotification::PRIORITY_INFO,
            'status'          => SuresignNotification::STATUS_UNREAD,
            'title'           => $title,
            'message'         => $message,
            'action_url'      => $meta['action_url']      ?? null,
            'data'            => $data,
            'is_read'         => false,
        ]);
    }

    /**
     * Dispatch a queued job to generate operational notifications for a project.
     * Call this after confirming a contract, completing a calendar sync, etc.
     */
    public static function generateFromOperationalIntelligence(int $projectId): void
    {
        GenerateProjectNotificationsJob::dispatch($projectId);
    }
}

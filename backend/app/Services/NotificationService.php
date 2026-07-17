<?php

namespace App\Services;

use App\Jobs\GenerateProjectNotificationsJob;
use App\Models\Organization;
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
    // Support tickets — personal to the submitter only, never fanned out to
    // the rest of the organization (see SupportTicketController).
    public const SUPPORT_TICKET_RECEIVED       = 'support_ticket_received';
    public const SUPPORT_TICKET_STATUS_CHANGED = 'support_ticket_status_changed';
    // Threaded conversation events (Batch 5) — SUPPORT_TICKET_REPLY notifies
    // the ticket owner only (a support reply); SUPPORT_TICKET_CUSTOMER_REPLY
    // notifies platform support operators only (a customer reply). Neither
    // is ever sent via sendToOrganization().
    public const SUPPORT_TICKET_REPLY          = 'support_ticket_reply';
    public const SUPPORT_TICKET_CUSTOMER_REPLY = 'support_ticket_customer_reply';
    // A brand-new ticket submission — notifies every platform operator
    // (Super Admin/Admin) personally, separate from SUPPORT_TICKET_RECEIVED
    // (the submitter's own personal receipt).
    public const SUPPORT_TICKET_SUBMITTED      = 'support_ticket_submitted';
    // Guided tour milestones — personal only, one-off per user (see
    // TourMilestoneController), never fanned out to the organization.
    public const TOUR_MILESTONE_FIRST            = 'tour_milestone_first';
    public const TOUR_MILESTONE_GETTING_STARTED  = 'tour_milestone_getting_started';
    public const TOUR_MILESTONE_ALL_COMPLETE     = 'tour_milestone_all_complete';

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
            'source_type'     => $meta['source_type']     ?? null,
            'source_id'       => $meta['source_id']        ?? null,
            'source_field'    => $meta['source_field']    ?? null,
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

    /**
     * Fan out a manual notification to the relevant users of an organisation,
     * instead of only the acting user.
     *
     * Default recipient set: active, non-banned users with the 'Client' role
     * on $organization. Admin/Super Admin are platform operators and are
     * deliberately excluded unless $recipientFilter widens the set for a
     * platform-level event.
     *
     * $recipientFilter must be trusted, module-authored logic only — it
     * receives and returns an Eloquent query builder scoped to $organization's
     * users. It must never be built from request input.
     *
     * Idempotency: only applied when $meta carries 'source_type' + 'source_id'
     * (the same shape NotificationEngineService keys its own upserts on) — a
     * matching (user_id, type, source_type, source_id, source_field) row
     * already existing means this exact event was already delivered to that
     * user, so it's skipped rather than duplicated. Without source metadata,
     * every call creates a fresh notification (e.g. a one-off system message).
     */
    public static function sendToOrganization(
        Organization $organization,
        string   $type,
        string   $title,
        string   $message,
        array    $data = [],
        array    $meta = [],
        ?User    $actor = null,
        bool     $includeActor = false,
        ?callable $recipientFilter = null,
    ): void {
        $query = $organization->users()
            ->where('is_active', true)
            ->whereNull('banned_at')
            ->whereHas('roles', fn ($q) => $q->where('name', 'Client'));

        if ($recipientFilter) {
            $query = $recipientFilter($query);
        }

        $recipients = $query->get()->unique('id');

        if (!$includeActor && $actor) {
            $recipients = $recipients->reject(fn (User $u) => $u->id === $actor->id);
        }

        $sourceType  = $meta['source_type']  ?? null;
        $sourceId    = $meta['source_id']    ?? null;
        $sourceField = $meta['source_field'] ?? null;

        foreach ($recipients as $recipient) {
            if ($sourceType !== null && $sourceId !== null) {
                $alreadyNotified = SuresignNotification::where('user_id', $recipient->id)
                    ->where('type', $type)
                    ->where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->where('source_field', $sourceField)
                    ->exists();

                if ($alreadyNotified) {
                    continue;
                }
            }

            self::send($recipient, $type, $title, $message, $data, $meta);
        }
    }
}

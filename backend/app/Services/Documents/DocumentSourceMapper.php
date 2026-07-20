<?php

namespace App\Services\Documents;

use App\Models\Contract;
use App\Models\DelayEvent;
use App\Models\Document;
use App\Models\EotRequest;
use App\Models\FileUpload;
use App\Models\FinalAccount;
use App\Models\PaymentApplication;
use App\Models\PaymentNotice;
use App\Models\PayLessNotice;
use App\Models\TradePackage;
use App\Models\Variation;
use App\Services\TradePackages\WorkspaceNavigationResolver;

/**
 * The single place that translates a Document/FileUpload's polymorphic
 * ownership into a WorkspaceNavigationResolver source_type, then into a
 * navigable URL. WorkspaceNavigationResolver itself remains the single
 * authoritative navigation service — this class never builds a URL itself,
 * it only decides which (source_type, source_id) pair to hand the resolver,
 * or returns null when no authoritative mapping exists.
 *
 * Built from an exhaustive audit of every place `documentable_type`
 * (Document) and `attachable_type`/`trade_package_id` (FileUpload) are ever
 * written in this codebase — not assumed. The audit found exactly nine
 * `documentable_type` values and three `attachable_type` values across the
 * entire application; every one of them is accounted for below, either
 * mapped or explicitly deferred with a documented reason.
 *
 * Confirmed real `documentable_type` values (Document): PaymentApplication,
 * PaymentNotice, PayLessNotice, Variation, DelayEvent, EotRequest,
 * FinalAccount, TradePackage, Contract, or null (plain manual upload).
 *
 * Confirmed real `attachable_type` values (FileUpload): Contract,
 * SupportTicket, SupportTicketMessage (the latter two are Help Centre
 * attachments, never project documents, and are excluded from Global
 * Documents entirely at the query level, not resolved here).
 *
 * `Contract` is deliberately unmapped: WorkspaceNavigationResolver has no
 * 'contract' entry in its TAB_MAP today. This is not a new gap introduced by
 * this class — OperationalIntelligenceService already calls
 * WorkspaceNavigationResolver::actionUrl($projectId, 'contract', ...) for
 * contract milestone/key-date items and already receives null back in
 * production. Contract-owned documents are treated the same way here for
 * consistency: no navigation link, document remains fully previewable and
 * downloadable.
 */
class DocumentSourceMapper
{
    /** @var array<class-string, string> */
    private const DOCUMENTABLE_TYPE_MAP = [
        PaymentApplication::class => 'payment_application',
        PaymentNotice::class      => 'payment_notice',
        PayLessNotice::class      => 'pay_less_notice',
        Variation::class          => 'variation',
        DelayEvent::class         => 'delay_event',
        EotRequest::class         => 'eot_request',
        FinalAccount::class       => 'final_account',
        TradePackage::class       => 'trade_package',
        // Contract::class intentionally absent — see class docblock.
    ];

    /**
     * FileUpload.attachable_type values that are Help Centre attachments,
     * never project documents — excluded from Global Documents at the query
     * level (see OrganisationDocumentService), listed here too so this
     * class's own exhaustiveness is self-documenting.
     */
    public const FILE_UPLOAD_EXCLUDED_ATTACHABLE_TYPES = [
        'App\\Models\\SupportTicket',
        'App\\Models\\SupportTicketMessage',
    ];

    public static function actionUrlForDocument(Document $document): ?string
    {
        if (!$document->documentable_type || !$document->documentable_id || !$document->project_id) {
            return null;
        }

        $sourceType = self::DOCUMENTABLE_TYPE_MAP[$document->documentable_type] ?? null;
        if (!$sourceType) {
            return null;
        }

        return WorkspaceNavigationResolver::actionUrl(
            $document->project_id, $sourceType, $document->documentable_id, $document->trade_package_id
        );
    }

    public static function actionUrlForFileUpload(FileUpload $fileUpload): ?string
    {
        if (!$fileUpload->project_id) {
            return null;
        }

        // A trade_package_id is a direct FK on FileUpload (not polymorphic)
        // — the file belongs to that trade package itself (e.g. a drawing
        // uploaded directly against the package), distinct from the
        // Contract::class attachable_type case below.
        if ($fileUpload->trade_package_id && !$fileUpload->attachable_type) {
            return WorkspaceNavigationResolver::actionUrl(
                $fileUpload->project_id, 'trade_package', $fileUpload->trade_package_id, $fileUpload->trade_package_id
            );
        }

        // Contract::class is the only project-relevant attachable_type left
        // (SupportTicket/SupportTicketMessage are filtered out upstream) —
        // unmapped for the same reason as Document's Contract case above.
        return null;
    }
}

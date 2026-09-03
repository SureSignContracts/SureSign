<?php

namespace App\Support\FridayPack;

use App\Models\FridayPack;
use Carbon\Carbon;

/**
 * Automated Friday Pack, V1C — the smallest presentation layer needed to
 * keep the Blade template simple, mirroring how CommercialReportService
 * pre-shapes its own report array before the equivalent
 * commercial-summary-report.blade.php ever sees it.
 *
 * Reads ONLY `FridayPack`'s own already-persisted columns
 * (snapshot_json/settings_snapshot_json/commentary/lifecycle metadata) —
 * never queries a live business model, never calls
 * CommercialAggregationService/UpcomingActionsService/etc. itself, never
 * mutates the pack. Formatting only: dates, currency, section labels,
 * empty states.
 */
class FridayPackPdfPresenter
{
    public const SECTION_LABELS = [
        'programme'           => 'Programme / Milestones',
        'toolbox_talks'       => 'Toolbox Talks',
        'site_reports'        => 'Site Reports',
        'risks'               => 'Risks',
        'rfis'                => 'RFIs',
        'variations'          => 'Variations',
        'commercial'          => 'Commercial',
        'delays_eot'          => 'Delays / EOT',
        'meetings_actions'    => 'Meetings / Actions',
        'delivery_documents'  => 'Delivery Documents',
        'drawings'            => 'Drawings',
        'qa_snagging'         => 'QA / Snagging',
        'upcoming_actions'    => 'Upcoming Actions',
    ];

    /** Rendered in this fixed order — matches the authoritative FridayPackSections order (Phase 0/V1B). */
    private const SECTION_ORDER = [
        'programme', 'toolbox_talks', 'site_reports', 'risks', 'rfis',
        'variations', 'commercial', 'delays_eot', 'meetings_actions',
        'delivery_documents', 'drawings', 'qa_snagging', 'upcoming_actions',
    ];

    public function present(FridayPack $pack): array
    {
        $snapshot = $pack->snapshot_json ?? [];
        $sections = $snapshot['sections'] ?? [];

        $orderedSections = [];
        foreach (self::SECTION_ORDER as $key) {
            if (array_key_exists($key, $sections)) {
                $orderedSections[] = [
                    'key'   => $key,
                    'label' => self::SECTION_LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key)),
                    'data'  => $sections[$key],
                ];
            }
        }

        return [
            'title'              => "Friday Pack — {$pack->project->name} — Week Ending " . $this->formatDate($pack->week_ending),
            'status'             => $pack->status,
            'status_label'       => $this->statusLabel($pack->status),
            'is_draft'           => $pack->status === 'draft',
            'project_name'       => $snapshot['project']['name'] ?? $pack->project->name,
            'organisation_name'  => $snapshot['project']['organisation_name'] ?? $pack->project->organization?->name,
            'contract_title'     => $snapshot['project']['contract_title'] ?? null,
            'week_ending'        => $this->formatDate($pack->week_ending),
            'period_label'       => $this->formatDate($pack->period_start) . ' to ' . $this->formatDate($pack->period_end),
            'generated_at'       => $pack->generated_at ? $this->formatDateTime($pack->generated_at) : '—',
            // V1E — a scheduled pack has no real User (generated_by is
            // genuinely null); never rendered as a blank/dash, which would
            // read as a data gap rather than a deliberate automation event.
            'generated_by'       => $pack->generation_source === 'scheduled' ? 'SureSign Automation' : ($pack->generatedBy?->name ?? '—'),
            // V1D — only populated once the corresponding lifecycle step
            // has actually happened; the template shows these rows only
            // when non-null. Display name only, per report convention —
            // no email/internal ids.
            'reviewed_at'        => $pack->reviewed_at ? $this->formatDateTime($pack->reviewed_at) : null,
            'reviewed_by'        => $pack->reviewedBy?->name,
            'approved_at'        => $pack->approved_at ? $this->formatDateTime($pack->approved_at) : null,
            'approved_by'        => $pack->approvedBy?->name,
            'currency'           => $sections['commercial']['currency'] ?? ($snapshot['project']['currency'] ?? null),
            'executive_summary'    => $pack->executive_summary,
            'progress_commentary'  => $pack->progress_commentary,
            'key_concerns'          => $pack->key_concerns,
            'next_week_priorities'  => $pack->next_week_priorities,
            'sections'           => $orderedSections,
        ];
    }

    private function statusLabel(string $status): string
    {
        // Derived from the stored status, not hardcoded permanently as
        // Draft — a future Approved/Sent pack renders through this exact
        // same template without redesign (V1C spec).
        return match ($status) {
            'draft'            => 'DRAFT — NOT YET APPROVED',
            'ready_for_review' => 'READY FOR REVIEW',
            'approved'         => 'APPROVED',
            'sent'             => 'SENT',
            'failed'           => 'GENERATION FAILED',
            default            => strtoupper($status),
        };
    }

    private function formatDate($date): string
    {
        if (!$date) return '—';
        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $carbon->format('d F Y');
    }

    private function formatDateTime($date): string
    {
        if (!$date) return '—';
        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $carbon->format('d F Y, H:i');
    }
}

<?php

namespace App\Services\FridayPack;

use App\Models\DeliveryDocument;
use App\Models\Project;
use App\Services\TimezoneResolver;
use Carbon\Carbon;

/**
 * Friday Pack Realignment, R1E.1 — RAMS + Permits, corrected in R1F.0
 * (Temporal Boundary Integrity Audit). The ONE focused service reading
 * `App\Models\DeliveryDocument` for Friday Pack RAMS/Permits sections —
 * never a second query built independently by FridayPackSnapshotService,
 * and never a new RAMS/Permit register.
 *
 * `DeliveryDocument::$project_id` is already a direct, denormalized
 * column (the R1E audit confirmed it exists alongside `contract_id`/
 * `trade_package_id`) — this service queries it directly, exactly the
 * same trusted-server-side-Project pattern every other Friday Pack
 * source service in this codebase already uses (see
 * FridayPackWeeklySummarySourceService/FridayPackSiteIssuesSourceService/
 * etc.) — never a client-supplied `contract_id`/`trade_package_id`/
 * `project_id`, always the FridayPack's own authoritative Project.
 *
 * NO compliance conclusions are ever produced here — "current"/
 * "submitted this week"/"approved this week" are all derived strictly
 * from DeliveryDocument's own existing recorded fields (`status`,
 * `submitted_at`, `approved_at`, `expiry_date`), never a legal/regulatory
 * judgement. A record is never described as "revised this week" — no
 * revision-event data exists on DeliveryDocument to prove that; only
 * "submitted this week"/"approved this week" are used.
 *
 * **R1F.0 fix — `submitted_this_week`/`approved_this_week` are genuine
 * UTC_DATETIME classifications, not LOCAL_DATE ones.**
 * `DeliveryDocument::$submitted_at`/`$approved_at` are `datetime`-cast
 * (real UTC instants, per this codebase's "store instants in UTC"
 * convention) — the PREVIOUS implementation converted each timestamp to
 * a date string via `$timestamp->toDateString()` (which reports in
 * `config('app.timezone')`, i.e. UTC) and compared that against the
 * Friday Pack's own LOCAL calendar period boundaries. For any
 * organisation not on UTC, this can misclassify a genuinely
 * local-Monday-morning or local-Friday-evening submission/approval: e.g.
 * a document submitted at 23:30 local time in a UTC-4 organisation is
 * 03:30 UTC the FOLLOWING calendar day — `toDateString()` would report
 * Saturday even though the submission genuinely happened during local
 * Friday, silently excluding it from `approved_this_week`/
 * `submitted_this_week`. Fixed the same way
 * `FridayPackIncidentSourceService` already established: resolve the
 * period's own local Monday 00:00 / Friday 23:59:59 boundaries to UTC
 * instants via the existing `App\Services\TimezoneResolver` (never a
 * second timezone system), then compare the raw UTC `Carbon` instant
 * directly — never via a date-string round-trip.
 *
 * `expiry_date`/`due_date` remain untouched — both are genuinely `date`
 * cast (bare local calendar dates, no timezone concern at all), and
 * `isCurrent()`'s factual date-string comparison against `period_end`
 * was already correct LOCAL_DATE semantics; introducing timezone
 * conversion there would be wrong, not a fix.
 */
class FridayPackComplianceDocumentService
{
    /**
     * A DeliveryDocument counts as CURRENT as of `$periodEnd` only when
     * its own recorded `status` is `approved` AND (it has no
     * `expiry_date`, or that expiry_date has not yet passed
     * `$periodEnd`). Every other status (required/pending/submitted/
     * under_review/rejected/expired/superseded) is never "current" —
     * this reads the field's existing recorded meaning, it never invents
     * a new one. `expiry_date` is a plain DATE column — factual,
     * timezone-free comparison, deliberately unchanged by R1F.0.
     */
    private function isCurrent(DeliveryDocument $doc, string $periodEnd): bool
    {
        if ($doc->status !== 'approved') {
            return false;
        }

        if ($doc->expiry_date && $doc->expiry_date->toDateString() < $periodEnd) {
            return false;
        }

        return true;
    }

    /**
     * R1F.0 — compares the raw UTC instant directly against the
     * period's own UTC-resolved boundaries, mirroring
     * `FridayPackIncidentSourceService`'s established pattern exactly.
     */
    private function inPeriod(?Carbon $timestamp, Carbon $startUtc, Carbon $endUtc): bool
    {
        if (!$timestamp) {
            return false;
        }

        return $timestamp->between($startUtc, $endUtc, true);
    }

    /**
     * DeliveryDocument has no separate "reference"/"permit number" field —
     * only `title`/`revision` — so none is invented here (see this
     * class's own docblock: never fabricate a field that doesn't exist).
     *
     * @param  array{period_start: string, period_end: string, timezone?: string}  $period
     * @return array<int, array{title: string, revision: ?string, status: string, current: bool, submitted_this_week: bool, approved_this_week: bool, expiry_date: ?string}>
     */
    private function items(Project $project, string $category, array $period): array
    {
        $timezone = $period['timezone'] ?? TimezoneResolver::effectiveTimezone(null, $project->organization);
        $startUtc = TimezoneResolver::buildLocalInstant($period['period_start'], '00:00', $timezone);
        $endUtc   = TimezoneResolver::buildLocalInstant($period['period_end'], '23:59', $timezone)->addSeconds(59);

        $docs = DeliveryDocument::where('project_id', $project->id)
            ->where('category', $category)
            ->orderBy('title')
            ->get();

        return $docs->map(fn (DeliveryDocument $doc) => [
            'title'                => $doc->title,
            'revision'             => $doc->revision,
            'status'               => $doc->status,
            'current'              => $this->isCurrent($doc, $period['period_end']),
            'submitted_this_week'  => $this->inPeriod($doc->submitted_at, $startUtc, $endUtc),
            'approved_this_week'   => $this->inPeriod($doc->approved_at, $startUtc, $endUtc),
            'expiry_date'          => optional($doc->expiry_date)->toDateString(),
        ])->values()->all();
    }

    /**
     * @param  array{period_start: string, period_end: string, timezone?: string}  $period
     * @return array{items: array, source_count: int}
     */
    public function rams(Project $project, array $period): array
    {
        $items = $this->items($project, 'rams', $period);

        return ['items' => $items, 'source_count' => count($items)];
    }

    /**
     * @param  array{period_start: string, period_end: string, timezone?: string}  $period
     * @return array{items: array, source_count: int}
     */
    public function permits(Project $project, array $period): array
    {
        $items = $this->items($project, 'permit', $period);

        return ['items' => $items, 'source_count' => count($items)];
    }
}

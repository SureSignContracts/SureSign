<?php

namespace App\Services\FridayPack;

use App\Models\Incident;
use App\Models\Project;
use App\Services\TimezoneResolver;

/**
 * Friday Pack Realignment, R1E.2B — Incidents / Accidents / Near Misses.
 * Read-only, deterministic source aggregation. The FIRST Friday Pack
 * source in this whole initiative that needs real UTC-vs-local timezone
 * conversion — every other source date so far (SiteDiary/ToolboxTalk/
 * SiteInduction) uses a plain DATE column with no timezone concern;
 * `Incident::$occurred_at` is a genuine UTC instant.
 *
 * `App\Services\TimezoneResolver::buildLocalInstant()` (the SAME existing
 * primitive `App\Services\TimezoneResolver` already uses for timed
 * meeting scheduling — never a second timezone system) converts the
 * Friday Pack's own LOCAL Monday 00:00 / Friday 23:59:59 reporting-period
 * boundaries into the correct UTC instants for the `occurred_at` query —
 * a naive `whereDate('occurred_at', ...)` against the raw UTC column
 * would misclassify events near local midnight, since Friday Pack
 * periods are local calendar dates while storage is UTC.
 *
 * **Frozen display timezone (checkpoint's own required decision):** each
 * item freezes BOTH the raw UTC `occurred_at` (objective fact) AND a
 * `local_date`/`local_time` pair pre-formatted using the SAME timezone
 * the reporting period itself was resolved against
 * (`$period['timezone']`, supplied by `FridayPackPeriodResolver::resolve()`
 * and already frozen into the snapshot's own `period` block) — as plain
 * strings, computed once at generation time. A later change to the
 * organisation's timezone setting can never retroactively alter how an
 * already-generated historical Friday Pack displays an incident's local
 * date/time, because nothing re-derives it from a live timezone lookup.
 */
class FridayPackIncidentSourceService
{
    /**
     * @param  array{period_start: string, period_end: string, timezone: string}  $period
     * @return array{items: array, source_count: int}
     */
    public function aggregate(Project $project, array $period): array
    {
        $timezone = $period['timezone'];

        // Local Monday 00:00 → local Friday 23:59:59, converted to the
        // correct UTC instants for the occurred_at query.
        $startUtc = TimezoneResolver::buildLocalInstant($period['period_start'], '00:00', $timezone);
        $endUtc   = TimezoneResolver::buildLocalInstant($period['period_end'], '23:59', $timezone)->addSeconds(59);

        $incidents = Incident::where('project_id', $project->id)
            ->whereBetween('occurred_at', [$startUtc, $endUtc])
            ->orderBy('occurred_at')
            ->get();

        $items = $incidents->map(function (Incident $incident) use ($timezone) {
            $local = $incident->occurred_at->copy()->setTimezone($timezone);

            return [
                'occurred_at'               => $incident->occurred_at->toIso8601String(),
                'local_date'                => $local->toDateString(),
                'local_time'                => $local->format('H:i'),
                'type'                      => $incident->type,
                'title'                     => $incident->title,
                'location'                  => $incident->location,
                // Nullable tri-state preserved exactly — null stays null,
                // never coerced to false. See Incident model's own
                // docblock for why this matters.
                'injury_occurred'           => $incident->injury_occurred,
                'regulatory_reportability'  => $incident->regulatory_reportability,
                'status'                    => $incident->status,
                // Deliberately excluded: `description` — data-minimisation,
                // never frozen into the Friday Pack snapshot (see this
                // class's own docblock and the R1E.2B checkpoint).
            ];
        })->values()->all();

        return ['items' => $items, 'source_count' => count($items)];
    }
}

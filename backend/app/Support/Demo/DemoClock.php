<?php

namespace App\Support\Demo;

use Carbon\Carbon;

/**
 * The demo environment's notion of "today" — deliberately decoupled from
 * the server's real wall-clock time.
 *
 * Every Story class (RiversideWharfStory, AldermereStory, ...) was authored
 * against a fixed point in the narrative — "today = 2026-07-22" — so that
 * a project reading as "9 months into an 18-month programme" or "an
 * overdue payment application" stays true no matter when someone actually
 * runs `demo:seed` or opens the demo months later. Anything that evaluates
 * the environment's *current* state relative to a date (demo:validate's
 * Business signals, and eventually NotificationEngineService once wired
 * up) must compare against this anchor, not `Carbon::now()` — otherwise a
 * demo viewed long after it was seeded silently drifts: items deliberately
 * left "in progress" (e.g. Riverside Wharf's EOT request) age into
 * "overdue" ones purely because real time passed, not because anything in
 * the story changed. This was observed in practice during Phase 4 and is
 * the reason this class exists.
 *
 * The anchor is configurable (`DEMO_ANCHOR_DATE` / `config('demo.anchor_date')`)
 * specifically so the environment can be deliberately "rolled forward" in
 * the future — re-seeded against a later anchor with every Story class's
 * dates shifted by the same number of days — without that being a code
 * change. No such rolling mechanism exists yet (see internal-docs' Demo
 * Freeze section); today this class only stops the *validation tooling*
 * from drifting, which is the concrete problem Phase 4 surfaced.
 */
class DemoClock
{
    public static function anchorDate(): Carbon
    {
        return Carbon::parse(config('demo.anchor_date'))->startOfDay();
    }

    /**
     * How many real-world days have elapsed since the environment's
     * anchor date. A large, growing number is the signal that the
     * environment's authored "today" no longer matches when it's actually
     * being viewed — the point at which a re-seed (ideally against a
     * rolled-forward anchor) should be considered before further live
     * demoing or screenshot capture.
     */
    public static function daysSinceAnchor(): int
    {
        $today = Carbon::now()->startOfDay();
        $anchor = self::anchorDate();

        // Carbon's diffInDays() sign convention varies by direction of the
        // call across versions — computed explicitly here instead so the
        // result is unambiguous: positive once real time has moved past
        // the anchor (the normal, expected case), negative only if the
        // anchor is deliberately set in the future.
        return $today->greaterThanOrEqualTo($anchor)
            ? $anchor->diffInDays($today)
            : -$today->diffInDays($anchor);
    }
}

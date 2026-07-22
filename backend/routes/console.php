<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Batch 7: worldwide-safe scheduling. This now runs hourly, in UTC — the
// infrastructure scheduler stays UTC-only (see docker-compose TZ=UTC +
// config/app.php), exactly as every prior batch established. The command
// itself is a lightweight dispatcher: each tick, it checks every active
// organisation's own current local hour and only actually processes an
// organisation once it reaches its configured local reminder hour
// (default 08:00 organisation-local, see config/suresign.php), guarded by
// a durable per-organisation/per-local-date checkpoint so it fires at most
// once per organisation per organisation-local day — see
// App\Console\Commands\SendDeadlineReminders and the Batch 7 report for
// the full design (DST fallback policy, multi-instance safety, etc).
Schedule::command('suresign:send-deadline-reminders')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Keep CalendarEvent fresh independently of AI-analysis confirmation, the
// only other trigger for CalendarSyncService. Hourly balances freshness
// against the notification-generation job it dispatches per project.
//
// Scheduler timezone audit: intentionally UTC / cadence-based, not a
// wall-clock time — "every hour on the hour" has no organisation-timezone
// meaning to preserve, so this one stays UTC forever, not a future
// migration candidate.
Schedule::command('calendar:sync')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Appointments Phase 4 — every 15 minutes so a "1 hour before" reminder has
// reasonable precision. Deliberately NOT tied to exact wall-clock slices:
// "due" is `now() >= appointment.starts_at - offset`, so a late-running
// tick just catches up on the next run rather than missing a gap — see
// App\Console\Commands\SendAppointmentReminders for the full reasoning.
// The appointment_reminder_sends unique constraint is the actual
// duplicate-send guarantee, not this cadence.
Schedule::command('suresign:send-appointment-reminders')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

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

// Billing webhook recovery — conservative 5-minute cadence, well above the
// 15-minute stale-processing lease and the 2-minute stranded-received grace
// threshold (App\Console\Commands\RecoverBillingWebhookEvents), so a normal
// in-flight event is never mistaken for one needing recovery.
// withoutOverlapping() alone (no onOneServer()) matches every other
// scheduled command in this codebase — this app's deployment/scheduler
// configuration is single-instance, per those existing commands; adding
// onOneServer() would be new, unproven infrastructure ahead of an actual
// need, not a correctness requirement (WebhookEventProcessor's own row
// locking is what actually prevents duplicate processing regardless of how
// many scheduler instances ever call this command — see the command's own
// docblock on why duplicate dispatch is harmless, not incorrect).
Schedule::command('billing:webhooks:recover')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Consultancy Live Booking Upgrade, Stage 2 — hold duration is short
// (config('consultancy.reservation_hold_minutes'), default 15 minutes),
// so cleanup cadence is tighter than billing:webhooks:recover's 5-minute
// interval. This is a durable-state cleanup only, not a correctness
// requirement — an elapsed reservation already stops blocking a slot
// immediately regardless of when this next runs (see
// AppointmentSchedulingService::isSlotFree()'s own expires_at check).
Schedule::command('consultancy:reservations:expire')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Consultancy Live Booking Activation Hardening — same 5-minute cadence as
// billing:webhooks:recover above, and for the same reason: a
// 'conversion_pending' payment means Stripe has ALREADY confirmed payment
// but local Appointment conversion previously failed, so this is genuine
// recovery of a known-recoverable state, not exploratory drift detection
// (contrast billing:stripe:reconcile, which stays deliberately
// unscheduled). withoutOverlapping() alone, no onOneServer(), matches
// every other scheduled command here —
// ConsultancyPaymentConversionService::convert()'s own row locking is what
// actually prevents a double-conversion, not the scheduler. Manual
// execution (including --dry-run) remains fully supported alongside this.
Schedule::command('consultancy:payments:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Stage 4B.1 (Google Calendar Event Synchronisation) — same 5-minute
// cadence and reasoning as consultancy:payments:reconcile/
// billing:webhooks:recover above: recovers due retry_pending, disconnected,
// abandoned-processing, and outcome-uncertain AppointmentExternalSync rows.
// withoutOverlapping() alone, no onOneServer(), matches every other
// scheduled command here — AppointmentCalendarSyncService's own row-locked
// claim is what actually prevents duplicate processing, not the scheduler.
// Manual execution (including --dry-run) remains fully supported.
Schedule::command('appointments:calendar-sync:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Subscription Commercial State Automation checkpoint — turns due grace
// period starts/expiries, trial expiries, and scheduled cancellations into
// real SubscriptionLifecycleService transitions (see
// App\Services\Billing\SubscriptionAutomationService's class docblock for
// exactly what is/isn't automated and why). Hourly matches every other
// lifecycle-adjacent scheduled command in this codebase — every automated
// category here is date/day-grained (grace_period_ends_at, trial_ends_at,
// current_period_ends_at), never a sub-hour precision requirement, so
// hourly cadence is not a compromise. withoutOverlapping() alone (no
// onOneServer()) follows the same single-instance-deployment convention as
// billing:webhooks:recover above — SubscriptionLifecycleService's own row
// locking, not the scheduler, is what actually prevents a duplicate
// transition if this were ever dispatched more than once concurrently.
Schedule::command('billing:subscriptions:process-automation')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

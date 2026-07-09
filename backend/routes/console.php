<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('suresign:send-deadline-reminders')
    ->dailyAt('08:00');

// Keep CalendarEvent fresh independently of AI-analysis confirmation, the
// only other trigger for CalendarSyncService. Hourly balances freshness
// against the notification-generation job it dispatches per project.
Schedule::command('calendar:sync')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

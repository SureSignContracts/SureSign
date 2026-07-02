<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Auto-import files dropped into the local mirror folder every minute.
// Only runs when the mirror is enabled; safely skips if already running.
Schedule::command('suresign:send-deadline-reminders')
    ->dailyAt('08:00');

Schedule::command('suresign:import-from-mirror')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Keep CalendarEvent fresh independently of AI-analysis confirmation, the
// only other trigger for CalendarSyncService. Hourly balances freshness
// against the notification-generation job it dispatches per project.
Schedule::command('calendar:sync')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

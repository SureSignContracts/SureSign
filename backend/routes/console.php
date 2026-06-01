<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Auto-import files dropped into the local mirror folder every minute.
// Only runs when the mirror is enabled; safely skips if already running.
Schedule::command('suresign:import-from-mirror')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// activity log entries older than config('activitylog.clean_after_days')
Schedule::command('activitylog:clean --force')->daily();

// a branch with an open shift and no sale for too long (setting: POS ← idle_alert_hours)
Schedule::command('sales:idle-alert')->everyFifteenMinutes()->withoutOverlapping();

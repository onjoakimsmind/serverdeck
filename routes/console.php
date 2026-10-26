<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('backups:run')->everyMinute()->withoutOverlapping();
Schedule::command('alerts:run')->everyMinute()->withoutOverlapping();
Schedule::command('metrics:sample')->everyMinute()->withoutOverlapping();

$hubIntervalMinutes = max(1, (int) config('serverdeck.hub.report_interval_minutes', 5));
Schedule::command('hub:report')->cron("*/{$hubIntervalMinutes} * * * *")->withoutOverlapping();

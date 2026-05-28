<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('route-sheets:generate-daily')
    ->dailyAt(config('route_sheets.automatic_generation_time', '20:00'))
    ->timezone(config('route_sheets.automatic_generation_timezone', 'Europe/Brussels'))
    ->withoutOverlapping();

Schedule::command('notifications:prune')
    ->dailyAt('02:30')
    ->timezone('Europe/Brussels')
    ->withoutOverlapping();

Schedule::command('notifications:send-digests')
    ->hourly()
    ->timezone('Europe/Brussels')
    ->withoutOverlapping();

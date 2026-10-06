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

Schedule::command('documents:prune-orphan-proofs --days=7')
    ->weeklyOn(0, '03:00')
    ->timezone('Europe/Brussels')
    ->withoutOverlapping();

Schedule::command('notifications:send-digests')
    ->hourly()
    ->timezone('Europe/Brussels')
    ->withoutOverlapping();

Schedule::command('notifications:health --json')
    ->everyFifteenMinutes()
    ->timezone('Europe/Brussels')
    ->withoutOverlapping();

// Note de confiance enregistrée des demandes en attente (tri des files de demandes) : voir la commande.
Schedule::command('requests:refresh-confidence-notes')
    ->hourly()
    ->timezone('Europe/Brussels')
    ->withoutOverlapping();

Schedule::command('reservations:send-passenger-reminders')
    ->dailyAt('08:00')
    ->timezone('Europe/Brussels')
    ->withoutOverlapping();

// Demandes d'aide des chauffeurs restées sans prise en charge : une relance (config driver_journey.alert_reminder_minutes)
Schedule::command('driver-alerts:remind')
    ->everyMinute()
    ->withoutOverlapping();

// Clôture des réservations non confirmées dont la date du trajet est dépassée (la veille ou avant)
Schedule::command('reservations:close-expired')
    ->dailyAt('00:15')
    ->timezone('Europe/Brussels')
    ->withoutOverlapping();

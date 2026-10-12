<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Stringable;

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

// Listes des passagers : supprimées 3 ans après l'année du trajet (données nominatives, RGPD)
Schedule::command('documents:purge-passenger-lists')
    ->dailyAt('03:30')
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

// La commande sort en FAILURE quand un seuil est dépassé : le résultat est journalisé (sinon le scheduler l'ignore).
Schedule::command('notifications:health --json')
    ->everyFifteenMinutes()
    ->timezone('Europe/Brussels')
    ->withoutOverlapping()
    ->onFailureWithOutput(function (Stringable $output): void {
        Log::error('notifications.health.alert', [
            'report' => json_decode(trim((string) $output), true) ?? trim((string) $output),
        ]);
    });

// Note de confiance enregistrée des demandes en attente (tri des files de demandes) : voir la commande.
Schedule::command('requests:refresh-confidence-notes')
    ->hourly()
    ->timezone('Europe/Brussels')
    ->withoutOverlapping();

Schedule::command('reservations:send-passenger-reminders')
    ->dailyAt('08:00')
    ->timezone('Europe/Brussels')
    ->withoutOverlapping();

// Planning hebdomadaire (§5.9) : envoi au moment réglé par la commune (vendredi 12:00 par défaut), puis versions
// corrigées ; vérifié toutes les 15 minutes pour rattraper un serveur arrêté (WeeklyPlanningService)
Schedule::command('planning:send-weekly')
    ->everyFifteenMinutes()
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

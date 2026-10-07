<?php

namespace App\Console\Commands;

use App\Models\DriverAlert;
use App\Services\DriverAlerts\DriverAlertService;
use Illuminate\Console\Command;

/**
 * Relance les demandes d'aide toujours ouvertes (personne ne les a prises en charge) après
 * config driver_journey.alert_reminder_minutes (10 par défaut) : agents, admins et contact de garde sont prévenus une
 * seconde fois. Une seule relance par alerte. Planifiée chaque minute (routes/console.php).
 */
class RemindUnansweredDriverAlertsCommand extends Command
{
    protected $signature = 'driver-alerts:remind';

    protected $description = 'Relance une fois les demandes d’aide des chauffeurs restées sans prise en charge.';

    public function handle(DriverAlertService $driverAlertService): int
    {
        $reminded = $driverAlertService->remindUnanswered();
        $this->info("{$reminded->count()} demande(s) d’aide relancée(s).");
        $reminded->each(fn (DriverAlert $alert) => $this->line("  #{$alert->id} créée le {$alert->created_at->toDateTimeString()}"));

        return self::SUCCESS;
    }
}

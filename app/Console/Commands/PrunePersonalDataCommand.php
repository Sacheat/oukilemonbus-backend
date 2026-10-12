<?php

namespace App\Console\Commands;

use App\Enums\DriverAlertStatus;
use App\Models\DriverAlert;
use App\Models\TripEvent;
use Illuminate\Console\Command;

/**
 * RGPD : applique les durées de conservation de config/privacy.php (planifiée chaque nuit).
 *
 * Positions GPS des chauffeurs : latitude, longitude, précision et instant de mesure sont effacés des étapes de trajet
 * (d'après occurred_at) et des demandes d'aide clôturées (d'après closed_at) ; une alerte ouverte garde sa position.
 * position_state, le dernier lieu connu (fallback_*, un lieu du trajet) et les tracés prévus (trip_routes) restent.
 */
class PrunePersonalDataCommand extends Command
{
    protected $signature = 'privacy:prune {--dry-run : Compte les lignes concernées sans rien modifier}';

    protected $description = 'Efface les données personnelles dont la durée de conservation (config/privacy.php) est dépassée';

    private const array CLEARED_POSITION = [
        'latitude' => null,
        'longitude' => null,
        'accuracy_m' => null,
        'position_captured_at' => null,
    ];

    public function handle(): int
    {
        $days = (int) config('privacy.retention.driver_positions_days', 90);

        if ($days <= 0) {
            $this->info('Purge des positions GPS désactivée (privacy.retention.driver_positions_days = 0).');

            return self::SUCCESS;
        }

        $threshold = now()->subDays($days);

        $tripEvents = TripEvent::query()
            ->where('occurred_at', '<', $threshold)
            ->where(fn ($query) => $query->whereNotNull('latitude')->orWhereNotNull('longitude'));

        $alerts = DriverAlert::query()
            ->where('status', DriverAlertStatus::CLOTUREE->value)
            ->where('closed_at', '<', $threshold)
            ->where(fn ($query) => $query->whereNotNull('latitude')->orWhereNotNull('longitude'));

        if ($this->option('dry-run')) {
            $this->info("Étapes de trajet à effacer : {$tripEvents->count()} (dry-run)");
            $this->info("Demandes d'aide à effacer : {$alerts->count()} (dry-run)");

            return self::SUCCESS;
        }

        $this->info('Positions effacées des étapes de trajet : '.$tripEvents->update(self::CLEARED_POSITION));
        $this->info("Positions effacées des demandes d'aide : ".$alerts->update(self::CLEARED_POSITION));

        return self::SUCCESS;
    }
}

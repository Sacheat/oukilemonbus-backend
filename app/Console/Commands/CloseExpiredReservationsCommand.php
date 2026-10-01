<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Services\Reservations\ReservationExpirationService;
use Illuminate\Console\Command;

/**
 * Clôture les réservations non confirmées dont la date est dépassée (voir ReservationExpirationService).
 * Planifiée chaque nuit à 00:15, heure de Bruxelles (routes/console.php).
 */
class CloseExpiredReservationsCommand extends Command
{
    protected $signature = 'reservations:close-expired
        {--dry-run : Affiche les réservations concernées sans les clôturer}';

    protected $description = 'Clôture les réservations non confirmées dont la date du trajet est dépassée.';

    public function handle(ReservationExpirationService $expirationService): int
    {
        if ($this->option('dry-run')) {
            $reservations = $expirationService->expiredQuery()->get();
            $this->info("{$reservations->count()} réservation(s) seraient clôturées (dry-run).");
            $reservations->each(fn (Reservation $reservation) => $this->line(
                "  #{$reservation->id} {$reservation->trip_date->toDateString()} {$reservation->status->value}"
            ));

            return self::SUCCESS;
        }

        $closed = $expirationService->closeExpired();
        $this->info("{$closed->count()} réservation(s) clôturée(s).");
        $closed->each(fn (Reservation $reservation) => $this->line("  #{$reservation->id} {$reservation->trip_date->toDateString()}"));

        return self::SUCCESS;
    }
}

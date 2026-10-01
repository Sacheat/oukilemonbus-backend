<?php

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Notifications\NotificationRecipientResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Envoie un rappel aux demandeurs pour confirmer la liste de passagers avant le départ.
 * Cible les réservations CONFIRME, avec chauffeur et véhicule affectés, dont passengers_confirmed_at est encore null.
 * Conçue pour être appelée par le scheduler chaque matin (ex: J-2 avant le trajet).
 */
class SendPassengerConfirmationRemindersCommand extends Command
{
    protected $signature = 'reservations:send-passenger-reminders
        {--days=2 : Nombre de jours avant le depart pour envoyer le rappel}
        {--dry-run : Affiche les reservations concernees sans envoyer de notification}';

    protected $description = 'Envoie un rappel aux demandeurs pour confirmer le nombre de passagers avant le trajet.';

    public function handle(
        NotificationDispatchService $dispatcher,
        NotificationRecipientResolver $resolver
    ): int {
        $days = (int) $this->option('days');
        $dryRun = (bool) $this->option('dry-run');
        $targetDate = CarbonImmutable::parse(Reservation::localToday())->addDays($days)->toDateString();

        // On ne rappelle que les réservations entièrement affectées (chauffeur + véhicule)
        // dont le demandeur n'a pas encore validé la liste de passagers.
        $reservations = Reservation::query()
            ->whereDate('trip_date', $targetDate)
            ->where('status', ReservationStatus::CONFIRME->value)
            ->whereNull('passengers_confirmed_at')
            ->whereNotNull('vehicle_id')
            ->whereNotNull('driver_id')
            ->with('requestedByUser')
            ->get();

        if ($reservations->isEmpty()) {
            $this->info("Aucune réservation à rappeler pour le {$targetDate}.");
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d réservation(s) à rappeler pour le %s%s.',
            $reservations->count(),
            $targetDate,
            $dryRun ? ' (dry-run)' : ''
        ));

        foreach ($reservations as $reservation) {
            $requester = $resolver->reservationRequester($reservation);

            if ($requester === null) {
                // Cas rare : la réservation n'a plus de demandeur actif (compte supprimé, etc.).
                $this->line("  #{$reservation->id} : pas de demandeur trouvé, ignoré.");
                continue;
            }

            if ($dryRun) {
                $this->line("  #{$reservation->id} → {$requester->email}");
                continue;
            }

            $dispatcher->dispatch(
                users: collect([$requester]),
                eventKey: 'reservation.confirmation_reminder',
                category: 'reservation_updates',
                title: 'Confirmez vos passagers avant le trajet',
                message: "Votre réservation #{$reservation->id} du {$reservation->trip_date?->format('d/m/Y')} est confirmée. Veuillez vérifier et confirmer le nombre de passagers avant le départ.",
                reservation: $reservation,
            );

            $this->line("  #{$reservation->id} : rappel envoyé à {$requester->email}");
        }

        $this->info('Termine.');
        return self::SUCCESS;
    }
}
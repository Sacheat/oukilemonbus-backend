<?php

namespace App\Services\Reservations;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\ReservationStatusHistory;
use App\Services\Audit\DomainEventLogger;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Notifications\NotificationRecipientResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Clôture automatique des réservations dont la date est dépassée sans avoir été confirmées.
 *
 * Concernées : statut en_attente, en_conflit ou approuve (approuvée mais jamais confirmée, donc sans affectation
 * complète), dont la date du trajet est strictement avant aujourd'hui à Bruxelles. Les réservations confirmées
 * (le trajet a eu lieu) et les statuts finaux ne sont jamais touchés.
 *
 * Chaque réservation passe en « annule » avec le motif CLOSING_REASON, sans auteur (action du système) :
 * historique de statut (changed_by_user_id null), événement « reservation.expired », notification au demandeur
 * et au chauffeur éventuellement affecté. Les agents et admins reçoivent ensuite un récapitulatif de la clôture.
 */
class ReservationExpirationService
{
    public const CLOSING_REASON = 'Le délai de cette réservation est désormais dépassé : elle a été clôturée. '
        .'Pour plus d’informations, veuillez contacter un agent.';

    /** Statuts clôturables : actifs mais pas confirmés. */
    public const EXPIRABLE_STATUSES = [
        ReservationStatus::EN_ATTENTE,
        ReservationStatus::EN_CONFLIT,
        ReservationStatus::APPROUVE,
    ];

    public function __construct(
        private readonly DomainEventLogger $eventLogger,
        private readonly NotificationDispatchService $notificationDispatchService,
        private readonly NotificationRecipientResolver $notificationRecipientResolver
    ) {}

    /** Réservations à clôturer aujourd'hui (date du trajet passée, statut clôturable). */
    public function expiredQuery(): Builder
    {
        return Reservation::query()
            ->whereIn('status', array_map(fn (ReservationStatus $status) => $status->value, self::EXPIRABLE_STATUSES))
            ->whereDate('trip_date', '<', Reservation::localToday())
            ->orderBy('trip_date')
            ->orderBy('id');
    }

    /**
     * Clôture toutes les réservations expirées et prévient les agents et admins.
     *
     * @return Collection<int, Reservation> les réservations clôturées
     */
    public function closeExpired(): Collection
    {
        $closed = $this->expiredQuery()
            ->with(['organization', 'driver'])
            ->get()
            ->map(fn (Reservation $reservation) => $this->close($reservation));

        $this->notifyInternalUsers($closed);

        return $closed;
    }

    private function close(Reservation $reservation): Reservation
    {
        $oldStatus = $reservation->status;

        DB::transaction(function () use ($reservation, $oldStatus): void {
            $reservation->update([
                'status' => ReservationStatus::ANNULE,
                'cancelled_by_user_id' => null,
                'cancelled_at' => now(),
                'cancellation_reason' => self::CLOSING_REASON,
            ]);

            ReservationStatusHistory::create([
                'reservation_id' => $reservation->id,
                'old_status' => $oldStatus,
                'new_status' => ReservationStatus::ANNULE,
                'changed_by_user_id' => null,
                'reason' => self::CLOSING_REASON,
            ]);

            $this->eventLogger->log(
                aggregateType: 'reservation',
                aggregateId: $reservation->id,
                eventType: 'reservation.expired',
                actor: null,
                oldValues: ['status' => $oldStatus->value],
                newValues: ['status' => ReservationStatus::ANNULE->value, 'cancellation_reason' => self::CLOSING_REASON]
            );
        });

        $this->notifyRequesterAndDriver($reservation);

        return $reservation;
    }

    private function notifyRequesterAndDriver(Reservation $reservation): void
    {
        $requester = $this->notificationRecipientResolver->reservationRequester($reservation);
        $driver = $this->notificationRecipientResolver->reservationDriverUser($reservation);
        $tripDate = $reservation->trip_date->format('d/m/Y');

        if ($requester !== null) {
            $this->notificationDispatchService->dispatch(
                users: collect([$requester]),
                eventKey: 'reservation.expired',
                category: 'reservation_updates',
                title: 'Réservation clôturée',
                message: "Votre réservation #{$reservation->id} du {$tripDate} a été clôturée. ".self::CLOSING_REASON,
                reservation: $reservation
            );
        }

        // Le chauffeur n'est pas le demandeur : message propre à sa mission (sauf s'il est aussi le demandeur)
        if ($driver !== null && $driver->id !== $requester?->id) {
            $this->notificationDispatchService->dispatch(
                users: collect([$driver]),
                eventKey: 'reservation.expired',
                category: 'reservation_updates',
                title: 'Mission annulée',
                message: "La mission liée à la réservation #{$reservation->id} du {$tripDate} est annulée : "
                    .'la réservation n’a pas été confirmée avant la date du trajet et a été clôturée automatiquement.',
                reservation: $reservation
            );
        }
    }

    /** Un seul récapitulatif par clôture, pour tous les agents et admins actifs. */
    private function notifyInternalUsers(Collection $closed): void
    {
        if ($closed->isEmpty()) {
            return;
        }

        $list = $closed
            ->map(fn (Reservation $reservation) => "#{$reservation->id} ({$reservation->organization?->name}, {$reservation->trip_date->format('d/m/Y')})")
            ->implode(', ');

        $this->notificationDispatchService->dispatch(
            users: $this->notificationRecipientResolver->internalUsers(),
            eventKey: 'reservation.expired.summary',
            category: 'admin_workflow',
            title: 'Réservations clôturées automatiquement',
            message: "{$closed->count()} réservation(s) non confirmée(s) dont la date est dépassée ont été clôturées : {$list}."
        );
    }
}

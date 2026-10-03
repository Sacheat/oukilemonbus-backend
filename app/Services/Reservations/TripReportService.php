<?php

namespace App\Services\Reservations;

use App\Enums\ReservationStatus;
use App\Enums\TripReportOutcome;
use App\Models\Reservation;
use App\Models\TripReport;
use App\Models\User;
use App\Services\Audit\DomainEventLogger;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Notifications\NotificationRecipientResolver;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Compte rendu d'un trajet (cahier des charges §3 : le chauffeur « réalise et confirme les trajets »).
 *
 * Qui :
 *   - le chauffeur affecté (permission trip-reports.submit) : le compte rendu est attendu le jour même ; il peut le
 *     déclarer ou le corriger de l'heure de départ jusqu'au lendemain à DRIVER_GRACE_UNTIL (heure de Bruxelles) ;
 *   - un agent ou un admin (trip-reports.manage) : dès l'heure de départ, sans limite de délai (rattrapage,
 *     correction). Personne ne peut déclarer un trajet qui n'a pas commencé.
 * Quoi : seul un trajet « confirme » (chauffeur et véhicule affectés) a un compte rendu. Le statut de la réservation
 * ne change pas. Un « non réalisé » (nouveau, ou qui le devient) prévient les agents et admins.
 */
class TripReportService
{
    /** Fin du délai de grâce, le lendemain du trajet (heure de Bruxelles) */
    public const DRIVER_GRACE_UNTIL = '12:00:00';

    public function __construct(
        private readonly DomainEventLogger $eventLogger,
        private readonly NotificationDispatchService $notificationDispatchService,
        private readonly NotificationRecipientResolver $notificationRecipientResolver
    ) {}

    /**
     * Fenêtre pendant laquelle le chauffeur peut déclarer ou corriger son compte rendu (heure de Bruxelles).
     *
     * @return array{opens_at: CarbonImmutable, closes_at: CarbonImmutable}
     */
    public function driverWindow(Reservation $reservation): array
    {
        $timezone = $this->timezone();
        $tripDate = $reservation->trip_date->toDateString();

        return [
            // Heure de départ à Bruxelles (même calcul que les autres délais : Reservation::departureAt)
            'opens_at' => $reservation->departureAt(),
            'closes_at' => CarbonImmutable::parse($tripDate.' '.self::DRIVER_GRACE_UNTIL, $timezone)->addDay(),
        ];
    }

    public function canManage(User $user): bool
    {
        return $user->can('trip-reports.manage');
    }

    public function isAssignedDriver(Reservation $reservation, User $user): bool
    {
        $reservation->loadMissing('driver');

        return $reservation->driver !== null && $reservation->driver->user_id === $user->getKey();
    }

    /** Voir le compte rendu : agent / admin, ou le chauffeur affecté. 403 sinon. */
    public function ensureCanView(Reservation $reservation, User $user): void
    {
        if ($this->canManage($user)) {
            return;
        }

        if (! $user->can('trip-reports.submit') || ! $this->isAssignedDriver($reservation, $user)) {
            throw new AuthorizationException('Vous n’êtes pas autorisé à voir le compte rendu de ce trajet.');
        }
    }

    /**
     * L'utilisateur peut-il déclarer maintenant ? Trajet confirmé et commencé, puis :
     *   agent / admin → oui ; chauffeur affecté → jusqu'au lendemain DRIVER_GRACE_UNTIL ; sinon → non.
     */
    public function canSubmitNow(Reservation $reservation, User $user): bool
    {
        if ($reservation->status !== ReservationStatus::CONFIRME) {
            return false;
        }

        ['opens_at' => $opensAt, 'closes_at' => $closesAt] = $this->driverWindow($reservation);
        $now = CarbonImmutable::now($this->timezone());

        if ($now->lessThan($opensAt)) {
            return false;
        }

        if ($this->canManage($user)) {
            return true;
        }

        return $user->can('trip-reports.submit')
            && $this->isAssignedDriver($reservation, $user)
            && $now->lessThanOrEqualTo($closesAt);
    }

    /**
     * Comptes rendus de plusieurs réservations (badges de listes), limités à ce que l'utilisateur peut voir.
     *
     * @param  list<int>  $reservationIds
     * @return Collection<int, TripReport>
     */
    public function forReservations(array $reservationIds, User $user): Collection
    {
        return TripReport::query()
            ->whereIn('reservation_id', $reservationIds)
            ->when(! $this->canManage($user), fn ($query) => $query->whereHas(
                'reservation.driver',
                fn ($driver) => $driver->where('user_id', $user->getKey())
            ))
            ->with(['driver', 'reportedByUser'])
            ->get();
    }

    /** Crée ou met à jour le compte rendu (un par réservation). */
    public function save(Reservation $reservation, array $data, User $actor): TripReport
    {
        $this->ensureCanSave($reservation, $actor);

        $isRealised = $data['outcome'] === TripReportOutcome::REALISE->value;
        $existing = $reservation->tripReport()->first();
        $previousOutcome = $existing?->outcome;

        $report = DB::transaction(function () use ($reservation, $data, $actor, $isRealised, $existing): TripReport {
            $report = TripReport::query()->updateOrCreate(
                ['reservation_id' => $reservation->id],
                [
                    'driver_id' => $reservation->driver_id,
                    'outcome' => $data['outcome'],
                    'actual_departure_time' => $isRealised ? $data['actual_departure_time'] : null,
                    'actual_return_time' => $isRealised ? $data['actual_return_time'] : null,
                    'actual_nb_children' => $isRealised ? $data['actual_nb_children'] : null,
                    'actual_nb_adults' => $isRealised ? $data['actual_nb_adults'] : null,
                    'remarks' => $data['remarks'] ?? null,
                    'reported_by_user_id' => $actor->id,
                    'reported_at' => now(),
                ]
            );

            $this->eventLogger->log(
                aggregateType: 'reservation',
                aggregateId: $reservation->id,
                eventType: $existing ? 'trip_report.updated' : 'trip_report.created',
                actor: $actor,
                oldValues: $existing ? $this->auditValues($existing) : null,
                newValues: $this->auditValues($report)
            );

            return $report;
        });

        if ($report->outcome === TripReportOutcome::NON_REALISE && $previousOutcome !== TripReportOutcome::NON_REALISE) {
            $this->notifyNotRealised($reservation, $report, $actor);
        }

        return $report->refresh()->load(['driver', 'reportedByUser']);
    }

    /**
     * Valeurs journalisées : heures en HH:MM des deux côtés (la base renvoie HH:MM:SS, la requête HH:MM),
     * pour ne pas faire apparaître de faux changements d'heure dans l'historique.
     */
    private function auditValues(TripReport $report): array
    {
        return [
            'outcome' => $report->outcome?->value,
            'actual_departure_time' => $report->actual_departure_time ? substr((string) $report->actual_departure_time, 0, 5) : null,
            'actual_return_time' => $report->actual_return_time ? substr((string) $report->actual_return_time, 0, 5) : null,
            'actual_nb_children' => $report->actual_nb_children,
            'actual_nb_adults' => $report->actual_nb_adults,
            'remarks' => $report->remarks,
        ];
    }

    /** Fuseau des règles métier (config reservations.local_timezone). */
    private function timezone(): string
    {
        return config('reservations.local_timezone', 'Europe/Brussels');
    }

    private function ensureCanSave(Reservation $reservation, User $actor): void
    {
        if ($reservation->status !== ReservationStatus::CONFIRME) {
            throw ValidationException::withMessages([
                'reservation' => 'Seul un trajet confirmé (chauffeur et véhicule affectés) peut avoir un compte rendu.',
            ]);
        }

        $isManager = $this->canManage($actor);

        if (! $isManager && (! $actor->can('trip-reports.submit') || ! $this->isAssignedDriver($reservation, $actor))) {
            throw new AuthorizationException('Seul le chauffeur affecté peut déclarer le compte rendu de ce trajet.');
        }

        ['opens_at' => $opensAt, 'closes_at' => $closesAt] = $this->driverWindow($reservation);
        $now = CarbonImmutable::now($this->timezone());

        // Pour tout le monde : pas de compte rendu avant l'heure de départ
        if ($now->lessThan($opensAt)) {
            throw ValidationException::withMessages([
                'reservation' => 'Ce trajet pourra être confirmé à partir du '.$opensAt->format('d/m/Y').' à '.$opensAt->format('H:i').'.',
            ]);
        }

        // Agent / admin : pas de délai ; chauffeur : jusqu'au lendemain DRIVER_GRACE_UNTIL
        if (! $isManager && $now->greaterThan($closesAt)) {
            throw ValidationException::withMessages([
                'reservation' => 'Le délai pour confirmer ce trajet (le lendemain à '.$closesAt->format('H:i').') est dépassé : contactez un agent.',
            ]);
        }
    }

    private function notifyNotRealised(Reservation $reservation, TripReport $report, User $actor): void
    {
        $reservation->loadMissing('organization');
        $actorName = trim($actor->first_name.' '.$actor->last_name);

        $this->notificationDispatchService->dispatch(
            // L'auteur (agent ou admin qui saisit) n'est pas prévenu de sa propre déclaration
            users: $this->notificationRecipientResolver->internalUsers()->reject(fn (User $user): bool => $user->is($actor)),
            eventKey: 'trip_report.not_realised',
            category: 'admin_workflow',
            title: 'Trajet non réalisé',
            message: "Le trajet #{$reservation->id} du {$reservation->trip_date->format('d/m/Y')} "
                ."({$reservation->organization?->name}) n'a pas été réalisé, selon {$actorName}. Motif : {$report->remarks}",
            reservation: $reservation,
            priority: 'high',
            reason: $report->remarks
        );
    }
}

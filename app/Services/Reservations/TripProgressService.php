<?php

namespace App\Services\Reservations;

use App\Enums\ReservationStatus;
use App\Enums\TripEventSource;
use App\Enums\TripEventType;
use App\Enums\TripProgressState;
use App\Enums\TripReportOutcome;
use App\Models\Reservation;
use App\Models\TripEvent;
use App\Models\TripReport;
use App\Models\User;
use App\Services\Audit\DomainEventLogger;
use App\Services\DriverJourney\DriverJourneySettingsService;
use App\Support\DevicePosition;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Avancement d'un trajet (« Ma journée » du chauffeur), à partir de ses événements (trip_events).
 *
 * Étapes, dans l'ordre : départ (aller) → arrivée à destination → départ du retour → arrivée au lieu de départ.
 * L'avancement ne dépend que des étapes enregistrées, jamais de leur source : aujourd'hui le chauffeur appuie sur les
 * boutons (source chauffeur) ou un agent corrige (source agent) ; un suivi GPS pourra écrire les mêmes événements.
 *
 * Enregistrer une étape (record) :
 *   si le trajet n'est pas confirmé, ou déclaré non réalisé           → 422
 *   si ni agent / admin (trip-progress.manage) ni chauffeur affecté   → 403
 *   si l'étape est déjà enregistrée                                   → rien ne change (double appui, réseau rejoué)
 *   si ce n'est pas l'étape suivante                                  → 422
 *   chauffeur, premier départ : à partir de N minutes avant l'heure prévue
 *     (délai de la commune, sinon config driver_journey.departure_window_minutes) et jusqu'à la fin du jour du trajet ; autres étapes : jusqu'au
 *     délai du compte rendu (lendemain 12:00) ; agent : sans contrainte d'heure                → 422 sinon
 *   la dernière arrivée crée le compte rendu « réalisé » (heures réelles de départ et de départ du retour, passagers
 *   confirmés par le chauffeur), sauf s'il en existe déjà un.
 */
class TripProgressService
{
    public function __construct(
        private readonly TripReportService $tripReportService,
        private readonly DomainEventLogger $eventLogger,
        private readonly DriverJourneySettingsService $settings
    ) {}

    public function canManage(User $user): bool
    {
        return $user->can('trip-progress.manage');
    }

    public function isAssignedDriver(Reservation $reservation, User $user): bool
    {
        return $this->tripReportService->isAssignedDriver($reservation, $user);
    }

    /** Voir l'avancement : agent / admin, ou le chauffeur affecté (403 sinon). */
    public function ensureCanView(Reservation $reservation, User $user): void
    {
        if ($this->canManage($user)) {
            return;
        }

        if (! $user->can('trip-progress.record') || ! $this->isAssignedDriver($reservation, $user)) {
            throw new AuthorizationException('Vous n’êtes pas autorisé à voir l’avancement de ce trajet.');
        }
    }

    /** @param  Collection<int, TripEvent>  $events */
    public function stateFor(Collection $events): TripProgressState
    {
        $last = $this->lastStep($events);

        return $last === null ? TripProgressState::A_VENIR : TripProgressState::after($last);
    }

    /** @param  Collection<int, TripEvent>  $events */
    public function nextStep(Collection $events): ?TripEventType
    {
        $last = $this->lastStep($events);

        return $last === null ? TripEventType::DEPART_ALLER : (TripEventType::ordered()[$last->position() + 1] ?? null);
    }

    /**
     * Heures (Bruxelles) entre lesquelles le chauffeur peut enregistrer :
     * premier départ de `first_departure_opens_at` à `first_departure_closes_at`, autres étapes jusqu'à `steps_close_at`.
     *
     * @return array{first_departure_opens_at: CarbonImmutable, first_departure_closes_at: CarbonImmutable, steps_close_at: CarbonImmutable}
     */
    public function driverWindow(Reservation $reservation): array
    {
        $timezone = $this->timezone();
        $minutes = $this->settings->departureWindowMinutes();

        return [
            'first_departure_opens_at' => $reservation->departureAt()->subMinutes($minutes),
            'first_departure_closes_at' => CarbonImmutable::parse($reservation->trip_date->toDateString(), $timezone)->endOfDay(),
            'steps_close_at' => $this->tripReportService->driverWindow($reservation)['closes_at'],
        ];
    }

    /** L'utilisateur peut-il enregistrer l'étape suivante maintenant ? (meta.can_record) */
    public function canRecordNow(Reservation $reservation, User $user, Collection $events): bool
    {
        $next = $this->nextStep($events);

        if ($next === null || $reservation->status !== ReservationStatus::CONFIRME || $this->isDeclaredNotRealised($reservation)) {
            return false;
        }

        if ($this->canManage($user)) {
            return true;
        }

        if (! $user->can('trip-progress.record') || ! $this->isAssignedDriver($reservation, $user)) {
            return false;
        }

        return $this->timeProblem($reservation, $next) === null;
    }

    /**
     * Avancement de plusieurs réservations (badges du Planning, « Ma journée »), limité à ce que l'utilisateur voit :
     * agent / admin toutes, chauffeur les siennes.
     *
     * @param  list<int>  $reservationIds
     * @return Collection<int, array{reservation_id: int, state: TripProgressState, next_step: ?TripEventType, events: Collection<int, TripEvent>}>
     */
    public function forReservations(array $reservationIds, User $user): Collection
    {
        $reservations = Reservation::query()
            ->whereIn('id', $reservationIds)
            ->when(! $this->canManage($user), fn ($query) => $query->whereHas(
                'driver',
                fn ($driver) => $driver->where('user_id', $user->getKey())
            ))
            ->with('tripEvents')
            ->get(['id']);

        return $reservations->map(fn (Reservation $reservation): array => $this->summary($reservation->tripEvents, $reservation->id));
    }

    /** @return array{reservation_id: int, state: TripProgressState, next_step: ?TripEventType, events: Collection<int, TripEvent>} */
    public function summary(Collection $events, int $reservationId): array
    {
        return [
            'reservation_id' => $reservationId,
            'state' => $this->stateFor($events),
            'next_step' => $this->nextStep($events),
            'events' => $events->sortBy(fn (TripEvent $event): int => $event->type->position())->values(),
        ];
    }

    /**
     * Contrôle des étapes pour les agents (carte du Planning) : lieu prévu de chaque étape enregistrée et distance entre
     * la position du téléphone et ce lieu. Rien n'est bloqué : une étape loin du lieu prévu est seulement signalée.
     *
     *   départ (aller) et arrivée au lieu de départ → lieu de départ ; arrivée à destination et départ du retour → destination
     *   position ou coordonnées du lieu absentes → distance null, pas de signal
     *   distance > seuil (step_distance_warning_meters) → far_from_expected = true
     *
     * @param  Collection<int, TripEvent>  $events
     * @return list<array{type: string, expected_place: string, expected_latitude: ?float, expected_longitude: ?float, distance_m: ?int, far_from_expected: bool}>
     */
    public function stepChecks(Reservation $reservation, Collection $events): array
    {
        $reservation->loadMissing(['pickupLocation', 'destinationLocation']);
        $threshold = $this->settings->stepDistanceWarningMeters();

        return $events
            ->sortBy(fn (TripEvent $event): int => $event->type->position())
            ->map(function (TripEvent $event) use ($reservation, $threshold): array {
                $atDestination = in_array($event->type, [TripEventType::ARRIVEE_ALLER, TripEventType::DEPART_RETOUR], true);
                $place = $atDestination ? $reservation->destinationLocation : $reservation->pickupLocation;
                $distance = null;

                if ($event->latitude !== null && $event->longitude !== null && $place?->latitude !== null && $place?->longitude !== null) {
                    $distance = (int) round($this->distanceMeters(
                        (float) $event->latitude,
                        (float) $event->longitude,
                        (float) $place->latitude,
                        (float) $place->longitude
                    ));
                }

                return [
                    'type' => $event->type->value,
                    'expected_place' => $atDestination ? 'destination' : 'pickup',
                    'expected_latitude' => $place?->latitude !== null ? (float) $place->latitude : null,
                    'expected_longitude' => $place?->longitude !== null ? (float) $place->longitude : null,
                    'distance_m' => $distance,
                    'far_from_expected' => $distance !== null && $distance > $threshold,
                ];
            })
            ->values()
            ->all();
    }

    /** Distance à vol d'oiseau (haversine), en mètres */
    private function distanceMeters(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($toLat - $fromLat);
        $dLng = deg2rad($toLng - $fromLng);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Enregistre une étape. `passengers` (actual_nb_children, actual_nb_adults) : obligatoire pour la dernière arrivée.
     *
     * @param  array{actual_nb_children?: int, actual_nb_adults?: int}  $passengers
     * @return array{event: TripEvent, created: bool, report: ?TripReport}
     */
    public function record(Reservation $reservation, User $actor, TripEventType $type, DevicePosition $position, array $passengers = []): array
    {
        if ($reservation->status !== ReservationStatus::CONFIRME) {
            throw ValidationException::withMessages([
                'reservation' => 'Seul un trajet confirmé (chauffeur et véhicule affectés) peut être suivi.',
            ]);
        }

        $isManager = $this->canManage($actor);

        if (! $isManager && (! $actor->can('trip-progress.record') || ! $this->isAssignedDriver($reservation, $actor))) {
            throw new AuthorizationException('Seul le chauffeur affecté peut enregistrer les étapes de ce trajet.');
        }

        if ($this->isDeclaredNotRealised($reservation)) {
            throw ValidationException::withMessages([
                'reservation' => 'Ce trajet a été déclaré non réalisé : ses étapes ne peuvent plus être enregistrées.',
            ]);
        }

        $events = $reservation->tripEvents()->get();
        $existing = $events->first(fn (TripEvent $event): bool => $event->type === $type);

        if ($existing !== null) {
            return ['event' => $existing, 'created' => false, 'report' => null];
        }

        $next = $this->nextStep($events);

        if ($next !== $type) {
            throw ValidationException::withMessages([
                'type' => $next === null
                    ? 'Toutes les étapes de ce trajet sont déjà enregistrées.'
                    : 'Étape attendue : « '.$next->label().' ».',
            ]);
        }

        if (! $isManager && ($problem = $this->timeProblem($reservation, $type)) !== null) {
            throw ValidationException::withMessages(['type' => $problem]);
        }

        $source = $isManager && ! $this->isAssignedDriver($reservation, $actor) ? TripEventSource::AGENT : TripEventSource::CHAUFFEUR;

        try {
            $event = DB::transaction(function () use ($reservation, $actor, $type, $position, $source): TripEvent {
                $event = TripEvent::query()->create([
                    'reservation_id' => $reservation->id,
                    'driver_id' => $reservation->driver_id,
                    'type' => $type,
                    'occurred_at' => now(),
                    'source' => $source,
                    'recorded_by_user_id' => $actor->id,
                    ...$position->toColumns(),
                ]);

                $this->eventLogger->log(
                    aggregateType: 'reservation',
                    aggregateId: $reservation->id,
                    eventType: 'trip_event.recorded',
                    actor: $actor,
                    newValues: [
                        'type' => $type->value,
                        'source' => $source->value,
                        'position_state' => $position->state->value,
                    ]
                );

                return $event;
            });
        } catch (UniqueConstraintViolationException) {
            // Deux appuis simultanés : l'autre requête a déjà enregistré l'étape
            return ['event' => $reservation->tripEvents()->where('type', $type->value)->firstOrFail(), 'created' => false, 'report' => null];
        }

        $report = $type === TripEventType::ARRIVEE_RETOUR
            ? $this->createRealisedReport($reservation->fresh(), $actor, $events->push($event), $passengers)
            : null;

        return ['event' => $event, 'created' => true, 'report' => $report];
    }

    /** Compte rendu « réalisé » à la dernière arrivée, s'il n'y en a pas encore (sinon on n'y touche pas). */
    private function createRealisedReport(Reservation $reservation, User $actor, Collection $events, array $passengers): ?TripReport
    {
        if ($reservation->tripReport()->exists()) {
            return null;
        }

        $timeOf = fn (TripEventType $type): ?string => $events
            ->first(fn (TripEvent $event): bool => $event->type === $type)
            ?->occurred_at
            ->setTimezone($this->timezone())
            ->format('H:i');

        return $this->tripReportService->save($reservation, [
            'outcome' => TripReportOutcome::REALISE->value,
            'actual_departure_time' => $timeOf(TripEventType::DEPART_ALLER),
            'actual_return_time' => $timeOf(TripEventType::DEPART_RETOUR),
            'actual_nb_children' => (int) ($passengers['actual_nb_children'] ?? $reservation->nb_children),
            'actual_nb_adults' => (int) ($passengers['actual_nb_adults'] ?? $reservation->nb_adults),
            'remarks' => null,
        ], $actor);
    }

    /** Message si l'heure ne permet pas au chauffeur d'enregistrer cette étape, null sinon */
    private function timeProblem(Reservation $reservation, TripEventType $type): ?string
    {
        $window = $this->driverWindow($reservation);
        $now = CarbonImmutable::now($this->timezone());

        if ($type === TripEventType::DEPART_ALLER) {
            if ($now->lessThan($window['first_departure_opens_at'])) {
                return 'Le départ pourra être enregistré à partir de '.$window['first_departure_opens_at']->setTimezone($this->timezone())->format('H:i')
                    .' le '.$window['first_departure_opens_at']->setTimezone($this->timezone())->format('d/m/Y').'.';
            }

            if ($now->greaterThan($window['first_departure_closes_at'])) {
                return 'Le jour de ce trajet est passé : le départ ne peut plus être enregistré. Contactez un agent.';
            }

            return null;
        }

        if ($now->greaterThan($window['steps_close_at'])) {
            return 'Le délai pour enregistrer les étapes de ce trajet est dépassé : contactez un agent.';
        }

        return null;
    }

    private function isDeclaredNotRealised(Reservation $reservation): bool
    {
        return $reservation->tripReport()->where('outcome', TripReportOutcome::NON_REALISE->value)->exists();
    }

    /** @param  Collection<int, TripEvent>  $events */
    private function lastStep(Collection $events): ?TripEventType
    {
        return $events
            ->map(fn (TripEvent $event): TripEventType => $event->type)
            ->sortBy(fn (TripEventType $type): int => $type->position())
            ->last();
    }

    private function timezone(): string
    {
        return config('reservations.local_timezone', 'Europe/Brussels');
    }
}

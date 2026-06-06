<?php

namespace App\Services\Reservations;

use App\Enums\ApprovalType;
use App\Enums\OrganizationType;
use App\Enums\ReservationActivityType;
use App\Enums\ReservationConflictType;
use App\Enums\ReservationStatus;
use App\Exceptions\GeocodingException;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Services\Locations\LocationService;
use App\Services\Logging\DomainLogger;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Calcule l'aperçu d'une réservation avant sa création ou sa mise à jour.
 *
 * Pipeline de vérifications dans l'ordre :
 *   1. Organisation non bannie
 *   2. Délai minimum de préavis (configurable, défaut 15 j)
 *   3. Date réservable (pas de week-end, pas de date fermée)
 *   4. Trajet belge uniquement
 *   5. Capacité fleet suffisante pour les passagers demandés
 *   6. Quota organisation via ReservationQuotaService
 *   7. Distance ≤ max_distance_km (via ORS ; fallback haversine × 1.25 si ORS indisponible)
 *   8. Conflits bloquants (IMPOSSIBLE_TIME_WINDOW, SCHEDULE_OVERLAP)
 *   9. Avertissements de dérogation (horaire / durée — non bloquants, soumis au collège)
 *
 * `is_hard_block = true` empêche la création même pour un agent.
 * Les avertissements de dérogation n'affectent pas le statut et ne créent pas de ReservationConflict.
 * `detectConflicts()` est publique pour être réutilisée par RecurrenceService (estimation approximative).
 * `availabilityForData()` itère jusqu'à 50 fois pour trouver le prochain créneau libre sur la journée.
 */
class ReservationPreviewService
{
    private const float DISTANCE_FALLBACK_ROUTE_FACTOR = 1.25;
    private const int DISTANCE_FALLBACK_AVERAGE_SPEED_KMH = 45;

    private DomainLogger $logger;

    public function __construct(
        private readonly LocationService $locationService,
        private readonly ReservationQuotaService $quotaService,
        private readonly ReservationOccupationService $occupationService,
        ?DomainLogger $logger = null
    ) {
        $this->logger = $logger ?? app(DomainLogger::class);
    }

    public function preview(array $data): array
    {
        $organization = Organization::findOrFail($data['organization_id']);
        $pickup = Location::findOrFail($data['pickup_location_id']);
        $destination = Location::findOrFail($data['destination_location_id']);

        $this->ensureOrganizationNotBanned($organization);
        $this->ensureMinimumNotice($data['trip_date']);
        $this->ensureBookableDate($data['trip_date']);
        $this->ensureBelgianTrip($pickup, $destination);
        $this->ensureVehicleCapacity($data);
        $this->quotaService->ensureCanCreate($organization, $data['trip_date']);

        $distance = $this->calculateDistance($pickup, $destination);
        if ($distance['distance_km'] !== null && $distance['distance_km'] > config('reservations.max_distance_km', 50)) {
            throw ValidationException::withMessages([
                'destination_location_id' => 'La distance entre le lieu de depart et la destination depasse 50 km.',
            ]);
        }

        $estimatedDuration = $distance['estimated_duration_min'];
        $conflicts = $this->detectConflicts($data, $estimatedDuration, $organization);
        $warnings  = $this->detectDerogationWarnings($data, $estimatedDuration, $organization);

        if ($distance['distance_km'] === null) {
            $conflicts[] = [
                'type' => ReservationConflictType::DISTANCE_NOT_CALCULATED->value,
                'message' => 'La distance n a pas pu etre calculee automatiquement.',
                'related_reservation_id' => null,
            ];
        }

        // Le statut n'est affecté que par les conflits bloquants, pas par les avertissements
        // de dérogation (horaire / durée) qui restent EN_ATTENTE et sont traités par le collège.
        $hasHardBlock = collect($conflicts)->contains(fn (array $c): bool => $c['is_hard_block'] ?? false);

        $status = count($conflicts) > 0
            ? ReservationStatus::EN_CONFLIT
            : ($organization->type?->isAutoApproveType() ? ReservationStatus::APPROUVE : ReservationStatus::EN_ATTENTE);

        $estimatedOccupation = $this->formatOccupationWindow(
            $this->occupationService->windowForData($data, $estimatedDuration)
        );
        $availability = $this->availabilityForData($data, $estimatedDuration);

        if ($conflicts !== []) {
            $this->logger->reservationConflictsDetected($data, $conflicts, $availability);
        }

        return [
            'is_allowed' => ! $hasHardBlock,
            'distance_km' => $distance['distance_km'],
            'estimated_duration_min' => $estimatedDuration,
            'duration_margin_min' => config('reservations.duration_margin_min', 15),
            'estimated_occupation' => $estimatedOccupation,
            'availability' => $availability,
            'status' => $status->value,
            'approval_type' => $organization->type?->isAutoApproveType()
                ? ApprovalType::AUTO->value
                : ApprovalType::MANUEL->value,
            'conflicts' => $conflicts,
            'warnings' => $warnings,
        ];
    }

    public function availabilityForData(array $data, ?int $estimatedDurationMin): array
    {
        $requestedWindow = $this->occupationService->windowForData($data, $estimatedDurationMin);
        $candidateData = $data;
        $candidateWindow = $requestedWindow;
        $blockingReservation = null;
        $blockingWindow = null;
        $overlapDuration = 0;
        $iterations = 0;

        while ($iterations < 50) {
            $iterations++;
            $overlap = $this->findOverlappingReservation($candidateWindow, $data);

            if ($overlap === null) {
                return [
                    'is_available' => $candidateData === $data,
                    'requested_departure_time' => $this->formatTimeString($data['departure_time']),
                    'requested_return_time' => $this->formatTimeString($data['return_time']),
                    'next_available_departure_time' => $this->formatTimeString($candidateData['departure_time']),
                    'next_available_return_time' => $this->formatTimeString($candidateData['return_time']),
                    'bus_available_at' => $candidateWindow['start']->toISOString(),
                    'blocking_reservation_id' => $blockingReservation?->id,
                    'overlap_duration_min' => $overlapDuration,
                    'requested_occupation' => $this->formatOccupationWindow($requestedWindow),
                    'next_available_occupation' => $this->formatOccupationWindow($candidateWindow),
                    'message' => $blockingReservation
                        ? sprintf(
                            'Le bus n est pas disponible a %s. Prochain depart possible estime: %s.',
                            $this->formatTimeString($data['departure_time']),
                            $this->formatTimeString($candidateData['departure_time'])
                        )
                        : null,
                ];
            }

            $blockingReservation = $overlap;
            $blockingWindow = $this->occupationService->windowForReservation($blockingReservation);
            $overlapDuration = $this->occupationService->overlapDurationMinutes($candidateWindow, $blockingWindow);
            $nextDeparture = $this->occupationService->nextDepartureAfterWindow($blockingWindow);

            if ($nextDeparture->toDateString() !== CarbonImmutable::parse($data['trip_date'])->toDateString()) {
                break;
            }

            $candidateData = $this->occupationService->dataWithDepartureTime($data, $nextDeparture);
            $candidateWindow = $this->occupationService->windowForData($candidateData, $estimatedDurationMin);
        }

        return [
            'is_available' => false,
            'requested_departure_time' => $this->formatTimeString($data['departure_time']),
            'requested_return_time' => $this->formatTimeString($data['return_time']),
            'next_available_departure_time' => null,
            'next_available_return_time' => null,
            'bus_available_at' => $blockingWindow ? $blockingWindow['end']->toISOString() : null,
            'blocking_reservation_id' => $blockingReservation?->id,
            'overlap_duration_min' => $overlapDuration,
            'requested_occupation' => $this->formatOccupationWindow($requestedWindow),
            'next_available_occupation' => null,
            'message' => 'Aucun depart disponible n a pu etre estime pour cette journee.',
        ];
    }

    public function detectConflicts(array $data, ?int $estimatedDurationMin, ?Organization $organization = null): array
    {
        $conflicts = [];
        $organization ??= Organization::findOrFail($data['organization_id']);
        $departure = $this->dateTime($data['trip_date'], $data['departure_time']);
        $returnDeparture = $this->dateTime($data['trip_date'], $data['return_time']);
        $duration = $estimatedDurationMin ?? 0;
        $candidateWindow = $this->occupationService->windowForData($data, $estimatedDurationMin);

        if ($estimatedDurationMin !== null && $departure->addMinutes($duration)->greaterThan($returnDeparture)) {
            $conflicts[] = [
                'type'                   => ReservationConflictType::IMPOSSIBLE_TIME_WINDOW->value,
                'message'                => 'Le creneau demande ne permet pas de realiser le trajet aller avant le retour souhaite.',
                'related_reservation_id' => null,
                'is_hard_block'          => true,
            ];
        }

        $overlappingReservation = $this->findOverlappingReservation($candidateWindow, $data);

        if ($overlappingReservation) {
            $priorityMessage = $this->overlapPriorityMessage(
                $data,
                $organization,
                $overlappingReservation,
                $candidateWindow
            );

            $overlappingStatus = $overlappingReservation->status instanceof ReservationStatus
                ? $overlappingReservation->status
                : ReservationStatus::from((string) $overlappingReservation->status);

            $conflicts[] = [
                'type'                   => ReservationConflictType::SCHEDULE_OVERLAP->value,
                'message'                => $priorityMessage,
                'related_reservation_id' => $overlappingReservation->id,
                'is_hard_block'          => in_array($overlappingStatus, [ReservationStatus::APPROUVE, ReservationStatus::CONFIRME], true),
            ];
        }

        return $conflicts;
    }

    /**
     * Détecte les avertissements de dérogation (horaire / durée) qui ne bloquent pas
     * la réservation mais nécessitent une motivation et une décision du collège.
     * Ces warnings sont informatifs uniquement : ils n'affectent pas le statut
     * et ne créent pas d'enregistrement ReservationConflict en base.
     */
    public function detectDerogationWarnings(array $data, ?int $estimatedDurationMin, ?Organization $organization = null): array
    {
        $warnings = [];
        $organization ??= Organization::findOrFail($data['organization_id']);

        if ($organization->type?->isTimeRestrictionExempt()) {
            return [];
        }

        $departure = $this->dateTime($data['trip_date'], $data['departure_time']);
        $returnDeparture = $this->dateTime($data['trip_date'], $data['return_time']);
        $minDepartureTime = config('reservations.non_school_min_departure_time', '07:30');
        $maxReturnTime = config('reservations.non_school_max_return_time', '16:00');
        $maxTotalDuration = (int) config('reservations.non_school_max_total_duration_min', 300);

        if ($departure->format('H:i') < $minDepartureTime) {
            $warnings[] = [
                'type' => ReservationConflictType::DEPARTURE_BEFORE_ALLOWED_TIME->value,
                'message' => "L heure de depart est avant {$minDepartureTime}. Une motivation est requise — soumis a decision du college.",
            ];
        }

        if ($returnDeparture->format('H:i') > $maxReturnTime) {
            $warnings[] = [
                'type' => ReservationConflictType::RETURN_AFTER_ALLOWED_TIME->value,
                'message' => "L heure de retour depasse {$maxReturnTime}. Une motivation est requise — soumis a decision du college.",
            ];
        }

        if ($estimatedDurationMin !== null) {
            $window = $this->occupationService->windowForData($data, $estimatedDurationMin);
            if ($window['duration_min'] > $maxTotalDuration) {
                $warnings[] = [
                    'type' => ReservationConflictType::DURATION_OVER_ALLOWED_LIMIT->value,
                    'message' => "La duree totale d utilisation depasse {$maxTotalDuration} minutes. Une motivation est requise — soumis a decision du college.",
                ];
            }
        }

        return $warnings;
    }

    public function ensureBookableDate(string $tripDate): void
    {
        $date = CarbonImmutable::parse($tripDate);

        if ($date->isWeekend()) {
            throw ValidationException::withMessages([
                'trip_date' => 'Les reservations ne sont pas autorisees le week-end.',
            ]);
        }

        if (in_array($date->toDateString(), config('reservations.closed_dates', []), true)) {
            throw ValidationException::withMessages([
                'trip_date' => 'Les reservations ne sont pas autorisees a cette date.',
            ]);
        }
    }

    private function ensureMinimumNotice(string $tripDate): void
    {
        $minimumDate = now()->startOfDay()->addDays((int) config('reservations.min_notice_days', 15));

        if (CarbonImmutable::parse($tripDate)->startOfDay()->lessThan($minimumDate)) {
            throw ValidationException::withMessages([
                'trip_date' => 'La demande doit etre introduite au moins 15 jours avant la date souhaitee.',
            ]);
        }
    }

    private function ensureOrganizationNotBanned(Organization $organization): void
    {
        if ($organization->is_banned) {
            $reason = $organization->banned_reason
                ? " Motif : {$organization->banned_reason}"
                : '';

            throw ValidationException::withMessages([
                'organization_id' => "Cette organisation est interdite d acces au service.{$reason}",
            ]);
        }
    }

    private function ensureVehicleCapacity(array $data): void
    {
        $required = ($data['nb_children'] ?? 0) + ($data['nb_adults'] ?? 0) + 1;

        $hasCapacity = Vehicle::query()
            ->where('is_active', true)
            ->where('capacity', '>=', $required)
            ->exists();

        if (! $hasCapacity) {
            throw ValidationException::withMessages([
                'nb_children' => "Aucun vehicule de la flotte n'a une capacite suffisante pour {$required} occupants ({$required} = passagers + chauffeur). Verifiez le nombre de participants.",
            ]);
        }
    }

    private function ensureBelgianTrip(Location $pickup, Location $destination): void
    {
        $allowedCountries = array_map(
            fn (string $country): string => mb_strtolower($country),
            config('reservations.allowed_countries', ['BE', 'Belgique', 'Belgium'])
        );

        foreach ([$pickup, $destination] as $location) {
            if (! in_array(mb_strtolower((string) $location->country), $allowedCountries, true)) {
                throw ValidationException::withMessages([
                    'destination_location_id' => 'Le trajet doit etre effectue sur le territoire belge uniquement.',
                ]);
            }
        }
    }

    private function calculateDistance(Location $pickup, Location $destination): array
    {
        $context = [
            'pickup_location_id' => $pickup->id,
            'destination_location_id' => $destination->id,
            'provider' => 'openrouteservice',
        ];

        if (blank($pickup->latitude) || blank($pickup->longitude) || blank($destination->latitude) || blank($destination->longitude)) {
            $this->logger->routeDistanceUnavailable([
                ...$context,
                'reason' => 'missing_coordinates',
            ]);

            return ['distance_km' => null, 'estimated_duration_min' => null];
        }

        try {
            $distance = $this->locationService->calculateDistance(
                (float) $pickup->latitude,
                (float) $pickup->longitude,
                (float) $destination->latitude,
                (float) $destination->longitude,
            );

            $this->logger->routeDistanceCalculated([
                ...$context,
                'distance_km' => $distance['distance_km'] ?? null,
                'estimated_duration_min' => $distance['estimated_duration_min'] ?? null,
            ]);

            return $distance;
        } catch (GeocodingException $exception) {
            $this->logger->routeDistanceCalculationFailed($context, $exception);

            return $this->estimateDistanceFromCoordinates($pickup, $destination, [
                ...$context,
                'reason' => 'provider_unavailable',
            ]);
        }
    }

    private function estimateDistanceFromCoordinates(Location $pickup, Location $destination, array $context): array
    {
        if (blank($pickup->latitude) || blank($pickup->longitude) || blank($destination->latitude) || blank($destination->longitude)) {
            return ['distance_km' => null, 'estimated_duration_min' => null];
        }

        $straightLineKm = $this->haversineDistanceKm(
            (float) $pickup->latitude,
            (float) $pickup->longitude,
            (float) $destination->latitude,
            (float) $destination->longitude
        );
        $estimatedDistanceKm = round($straightLineKm * self::DISTANCE_FALLBACK_ROUTE_FACTOR, 2);
        $estimatedDurationMin = max(1, (int) round(($estimatedDistanceKm / self::DISTANCE_FALLBACK_AVERAGE_SPEED_KMH) * 60));

        $this->logger->routeDistanceCalculated([
            ...$context,
            'provider' => 'coordinate_fallback',
            'distance_km' => $estimatedDistanceKm,
            'estimated_duration_min' => $estimatedDurationMin,
        ]);

        return [
            'distance_km' => $estimatedDistanceKm,
            'estimated_duration_min' => $estimatedDurationMin,
        ];
    }

    private function haversineDistanceKm(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $earthRadiusKm = 6371.0;
        $latDelta = deg2rad($toLat - $fromLat);
        $lngDelta = deg2rad($toLng - $fromLng);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($lngDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function dateTime(string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' '.$time);
    }

    private function findOverlappingReservation(array $candidateWindow, array $data): ?Reservation
    {
        return $this->occupationService->overlappingQuery(
            candidateWindow: $candidateWindow,
            tripDate: $data['trip_date'],
            statuses: [
                ReservationStatus::EN_ATTENTE->value,
                ReservationStatus::EN_CONFLIT->value,
                ReservationStatus::APPROUVE->value,
                ReservationStatus::CONFIRME->value,
            ],
            ignoreId: $data['ignore_reservation_id'] ?? null,
        )->orderBy('departure_time')->first();
    }

    private function formatOccupationWindow(array $window): array
    {
        return [
            'start_at' => $window['start']->format('Y-m-d\TH:i:s'),
            'end_at' => $window['end']->format('Y-m-d\TH:i:s'),
            'duration_min' => $window['duration_min'],
            'return_trip_duration_min' => $window['return_trip_duration_min'],
            'pickup_buffer_min' => $window['pickup_buffer_min'],
            'dropoff_buffer_min' => $window['dropoff_buffer_min'],
            'safety_buffer_min' => $window['safety_buffer_min'],
            'post_return_buffer_min' => $window['post_return_buffer_min'],
        ];
    }

    private function overlapPriorityMessage(
        array $data,
        Organization $organization,
        Reservation $overlappingReservation,
        array $candidateWindow
    ): string {
        $candidatePriority = $this->priorityTuple(
            $organization->type,
            $this->candidateActivityType($data),
            $this->candidateCreatedAt($data)
        );

        $existingPriority = $this->priorityTuple(
            $overlappingReservation->organization->type,
            $overlappingReservation->activity_type ?? ReservationActivityType::STANDARD,
            CarbonImmutable::instance($overlappingReservation->created_at)
        );

        $relatedWindow = $this->occupationService->windowForReservation($overlappingReservation);
        $details = sprintf(
            ' Reservation #%d occupe le bus de %s a %s; cette demande occuperait le bus de %s a %s. Chevauchement estime: %d min.',
            $overlappingReservation->id,
            $this->formatWindowTime($relatedWindow['start']),
            $this->formatWindowTime($relatedWindow['end']),
            $this->formatWindowTime($candidateWindow['start']),
            $this->formatWindowTime($candidateWindow['end']),
            $this->occupationService->overlapDurationMinutes($candidateWindow, $relatedWindow)
        );

        if ($candidatePriority > $existingPriority) {
            return 'Le creneau chevauche une autre reservation active, mais cette demande est prioritaire selon le reglement.'.$details;
        }

        if ($candidatePriority < $existingPriority) {
            return 'Le creneau chevauche une reservation plus prioritaire selon le reglement.'.$details;
        }

        return 'Le creneau chevauche une autre reservation active de meme priorite. La demande introduite en premier est prioritaire.'.$details;
    }

    private function formatWindowTime(CarbonImmutable $dateTime): string
    {
        return $dateTime->format('H\hi');
    }

    private function formatTimeString(string $time): string
    {
        return CarbonImmutable::parse($time)->format('H:i');
    }

    private function candidateActivityType(array $data): ReservationActivityType
    {
        return ReservationActivityType::tryFrom((string) ($data['activity_type'] ?? ReservationActivityType::STANDARD->value))
            ?? ReservationActivityType::STANDARD;
    }

    private function candidateCreatedAt(array $data): CarbonImmutable
    {
        if (isset($data['ignore_reservation_id'])) {
            $reservation = Reservation::query()->find($data['ignore_reservation_id']);

            if ($reservation?->created_at) {
                return CarbonImmutable::instance($reservation->created_at);
            }
        }

        return CarbonImmutable::now();
    }

    private function priorityTuple(
        OrganizationType $organizationType,
        ReservationActivityType $activityType,
        CarbonImmutable $createdAt
    ): array {
        $priorityLevel = match (true) {
            $organizationType->isPriorityByDefault() && $activityType->isSchoolPriorityActivity() => 3,
            $organizationType->isPriorityByDefault() => 2,
            default => 1,
        };

        return [$priorityLevel, -$createdAt->getTimestamp()];
    }
}

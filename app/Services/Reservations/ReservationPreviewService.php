<?php

namespace App\Services\Reservations;

use App\Enums\ApprovalType;
use App\Enums\OrganizationType;
use App\Enums\ReservationConflictType;
use App\Enums\ReservationStatus;
use App\Exceptions\GeocodingException;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Reservation;
use App\Services\Locations\LocationService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class ReservationPreviewService
{
    public function __construct(
        private readonly LocationService $locationService,
        private readonly ReservationQuotaService $quotaService
    ) {}

    public function preview(array $data): array
    {
        $organization = Organization::findOrFail($data['organization_id']);
        $pickup = Location::findOrFail($data['pickup_location_id']);
        $destination = Location::findOrFail($data['destination_location_id']);

        $this->ensureMinimumNotice($data['trip_date']);
        $this->ensureBookableDate($data['trip_date']);
        $this->ensureBelgianTrip($pickup, $destination);
        $this->quotaService->ensureCanCreate($organization, $data['trip_date']);

        $distance = $this->calculateDistance($pickup, $destination);
        if ($distance['distance_km'] !== null && $distance['distance_km'] > config('reservations.max_distance_km', 50)) {
            throw ValidationException::withMessages([
                'destination_location_id' => 'La distance entre le lieu de depart et la destination depasse 50 km.',
            ]);
        }

        $estimatedDuration = $distance['estimated_duration_min'];
        $conflicts = $this->detectConflicts($data, $estimatedDuration, $organization);

        if ($distance['distance_km'] === null) {
            $conflicts[] = [
                'type' => ReservationConflictType::DISTANCE_NOT_CALCULATED->value,
                'message' => 'La distance n a pas pu etre calculee automatiquement.',
                'related_reservation_id' => null,
            ];
        }

        $status = count($conflicts) > 0
            ? ReservationStatus::EN_CONFLIT
            : ($organization->type === OrganizationType::ECOLE_COMMUNALE ? ReservationStatus::APPROUVE : ReservationStatus::EN_ATTENTE);

        return [
            'is_allowed' => true,
            'distance_km' => $distance['distance_km'],
            'estimated_duration_min' => $estimatedDuration,
            'duration_margin_min' => config('reservations.duration_margin_min', 15),
            'status' => $status->value,
            'approval_type' => $organization->type === OrganizationType::ECOLE_COMMUNALE
                ? ApprovalType::AUTO->value
                : ApprovalType::MANUEL->value,
            'conflicts' => $conflicts,
        ];
    }

    public function detectConflicts(array $data, ?int $estimatedDurationMin, ?Organization $organization = null): array
    {
        $conflicts = [];
        $organization ??= Organization::findOrFail($data['organization_id']);
        $departure = $this->dateTime($data['trip_date'], $data['departure_time']);
        $returnDeparture = $this->dateTime($data['trip_date'], $data['return_time']);
        $margin = config('reservations.duration_margin_min', 15);
        $duration = $estimatedDurationMin ?? 0;
        $end = $returnDeparture->addMinutes($duration + $margin);

        if ($organization->type !== OrganizationType::ECOLE_COMMUNALE) {
            $minDepartureTime = config('reservations.non_school_min_departure_time', '07:30');
            $maxReturnTime = config('reservations.non_school_max_return_time', '16:00');
            $maxTotalDuration = (int) config('reservations.non_school_max_total_duration_min', 300);

            if ($departure->format('H:i') < $minDepartureTime) {
                $conflicts[] = [
                    'type' => ReservationConflictType::DEPARTURE_BEFORE_ALLOWED_TIME->value,
                    'message' => "L heure de depart est avant {$minDepartureTime}. Une derogation du college est necessaire.",
                    'related_reservation_id' => null,
                ];
            }

            if ($returnDeparture->format('H:i') > $maxReturnTime) {
                $conflicts[] = [
                    'type' => ReservationConflictType::RETURN_AFTER_ALLOWED_TIME->value,
                    'message' => "L heure de retour demandee depasse {$maxReturnTime}. Une derogation du college est necessaire.",
                    'related_reservation_id' => null,
                ];
            }

            if ($estimatedDurationMin !== null && $departure->diffInMinutes($end) > $maxTotalDuration) {
                $conflicts[] = [
                    'type' => ReservationConflictType::DURATION_OVER_ALLOWED_LIMIT->value,
                    'message' => 'La duree totale d utilisation depasse 5 heures. Une derogation du college est necessaire.',
                    'related_reservation_id' => null,
                ];
            }
        }

        if ($estimatedDurationMin !== null && $departure->addMinutes($duration + $margin)->greaterThan($returnDeparture)) {
            $conflicts[] = [
                'type' => ReservationConflictType::IMPOSSIBLE_TIME_WINDOW->value,
                'message' => 'Le creneau demande ne permet pas de realiser le trajet aller avant le retour souhaite.',
                'related_reservation_id' => null,
            ];
        }

        $overlappingReservation = Reservation::query()
            ->whereIn('status', [
                ReservationStatus::EN_ATTENTE->value,
                ReservationStatus::EN_CONFLIT->value,
                ReservationStatus::APPROUVE->value,
                ReservationStatus::CONFIRME->value,
            ])
            ->whereDate('trip_date', $data['trip_date'])
            ->when(isset($data['ignore_reservation_id']), fn ($query) => $query->whereKeyNot($data['ignore_reservation_id']))
            ->get()
            ->first(fn (Reservation $reservation): bool => $this->overlapsReservation($reservation, $departure, $end, $margin));

        if ($overlappingReservation) {
            $conflicts[] = [
                'type' => ReservationConflictType::SCHEDULE_OVERLAP->value,
                'message' => 'Le creneau chevauche une autre reservation active.',
                'related_reservation_id' => $overlappingReservation->id,
            ];
        }

        return $conflicts;
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
        if (blank($pickup->latitude) || blank($pickup->longitude) || blank($destination->latitude) || blank($destination->longitude)) {
            return ['distance_km' => null, 'estimated_duration_min' => null];
        }

        try {
            return $this->locationService->calculateDistance(
                (float) $pickup->latitude,
                (float) $pickup->longitude,
                (float) $destination->latitude,
                (float) $destination->longitude,
            );
        } catch (GeocodingException) {
            return ['distance_km' => null, 'estimated_duration_min' => null];
        }
    }

    private function overlapsReservation(Reservation $reservation, CarbonImmutable $start, CarbonImmutable $end, int $margin): bool
    {
        $reservationStart = $this->dateTime($reservation->trip_date->toDateString(), $reservation->departure_time->format('H:i:s'));
        $reservationEnd = $this->dateTime($reservation->trip_date->toDateString(), $reservation->return_time->format('H:i:s'))
            ->addMinutes(($reservation->estimated_duration_min ?? 0) + $margin);

        return $start->lessThan($reservationEnd) && $end->greaterThan($reservationStart);
    }

    private function dateTime(string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' '.$time);
    }
}

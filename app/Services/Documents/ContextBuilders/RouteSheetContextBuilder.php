<?php

namespace App\Services\Documents\ContextBuilders;

use App\Enums\ReservationStatus;
use App\Models\Driver;
use App\Models\Location;
use App\Models\Reservation;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use App\Enums\DocumentType;

/**
 * Construit le tableau de contexte pour la génération des feuilles de route.
 *
 * Deux modes de construction :
 *   - `buildForServiceDateVehicle()` : contexte journalier pour un véhicule donné
 *     (template 'route_sheets.daily') — inclut chauffeurs, passagers, itinéraire, etc.
 *   - `buildForPeriod()` : contexte hebdomadaire pour toutes les réservations CONFIRME
 *     sur la période (template 'route_sheets.weekly') ;
 *   - `buildForDriverWeek()` : feuille de travail d'un chauffeur pour une semaine (template 'route_sheets.driver'),
 *     ses seules missions confirmées, avec des cases à remplir à la main (non enregistrée, générée à la demande).
 *
 * L'URL de navigation Google Maps est construite depuis les coordonnées ORS si disponibles,
 * sinon depuis l'adresse formatée (fallback dégradé mais fonctionnel).
 * La durée de conservation légale (retention_until) est calculée à partir de la date de service
 * (configurable via route_sheets.legal_retention_years, défaut 3 ans).
 */
class RouteSheetContextBuilder
{
    private const TEMPLATE_KEY = 'route_sheets.weekly';
    private const DAILY_TEMPLATE_KEY = 'route_sheets.daily';
    public const DRIVER_TEMPLATE_KEY = 'route_sheets.driver';
    private const TEMPLATE_VERSION = '1.0';

    public function buildForServiceDateVehicle(string $serviceDate, int $vehicleId): array
    {
        $date = CarbonImmutable::parse($serviceDate)->startOfDay();
        $vehicle = Vehicle::query()->findOrFail($vehicleId);

        $reservations = Reservation::query()
            ->with([
                'organization',
                'requestedByUser',
                'pickupLocation',
                'destinationLocation',
                'driver.user',
                'vehicle',
                'councilDecision',
            ])
            ->whereDate('trip_date', $date->toDateString())
            ->where('vehicle_id', $vehicle->getKey())
            ->where('status', ReservationStatus::CONFIRME->value)
            ->orderBy('departure_time')
            ->get();

        $drivers = $reservations
            ->pluck('driver')
            ->filter()
            ->unique('id')
            ->values()
            ->map(fn (Driver $driver): array => $this->formatDriver($driver))
            ->all();

        $rows = $reservations
            ->map(fn (Reservation $reservation): array => $this->buildDailyRow($reservation))
            ->values()
            ->all();

        $totalDistance = $reservations->sum(fn (Reservation $reservation): float => $this->roundTripKm($reservation) ?? 0);

        return [
            'document' => [
                'type' => DocumentType::FEUILLE_ROUTE->value,
                'label' => DocumentType::FEUILLE_ROUTE->label(),
                'template_key' => self::DAILY_TEMPLATE_KEY,
                'template_version' => self::TEMPLATE_VERSION,
                'generated_at' => now()->toISOString(),
            ],

            'service' => [
                'date' => $date->toDateString(),
                'date_label' => $date->format('d/m/Y'),
                'day_label' => ucfirst($date->locale('fr')->isoFormat('dddd')),
                'vehicle' => [
                    'id' => $vehicle->id,
                    'licence_plate' => $vehicle->licence_plate,
                    'capacity' => $vehicle->capacity,
                ],
                'drivers' => $drivers,
                'transporter' => [
                    'name' => config('route_sheets.transporter.name'),
                    'identifier' => config('route_sheets.transporter.identifier'),
                    'signature_label' => config('route_sheets.transporter.signature_label'),
                    'license_number' => config('route_sheets.transporter.license_number'),
                    'emergency_contact' => config('route_sheets.transporter.emergency_contact'),
                ],
                'total_passengers' => $reservations->sum(fn (Reservation $reservation): int => ($reservation->nb_children ?? 0) + ($reservation->nb_adults ?? 0)),
                'total_distance_km' => round($totalDistance, 2),
                'retention_until' => $date->addYears((int) config('route_sheets.legal_retention_years', 3))->toDateString(),
            ],

            'rows' => $rows,
        ];
    }

    public function buildForPeriod(string $startDate, string $endDate): array
    {
        $start = CarbonImmutable::parse($startDate);
        $end = CarbonImmutable::parse($endDate);

        $reservations = Reservation::query()
            ->with([
                'organization',
                'requestedByUser',
                'pickupLocation',
                'destinationLocation',
                'driver',
                'vehicle',
                'councilDecision',
            ])
            ->whereBetween('trip_date', [
                $start->toDateString(),
                $end->toDateString(),
            ])
            ->where('status', ReservationStatus::CONFIRME->value)
            ->orderBy('trip_date')
            ->orderBy('departure_time')
            ->get();

        return [
            'document' => [
                'type' => DocumentType::FEUILLE_ROUTE->value,
                'label' => DocumentType::FEUILLE_ROUTE->label(),
                'template_key' => self::TEMPLATE_KEY,
                'template_version' => self::TEMPLATE_VERSION,
                'generated_at' => now()->toISOString(),
            ],

            'period' => [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'label' => sprintf(
                    'du %s au %s',
                    $start->format('d/m/Y'),
                    $end->format('d/m/Y')
                ),
            ],

            'rows' => $reservations
                ->map(fn (Reservation $reservation): array => $this->buildRow($reservation))
                ->values()
                ->all(),
        ];
    }

    /**
     * Semaine d'un chauffeur (lundi → dimanche contenant $dayInWeek) : un bloc par jour, du lundi au vendredi
     * toujours (même vide, comme la feuille papier), samedi et dimanche seulement s'ils ont des missions.
     */
    public function buildForDriverWeek(Driver $driver, string $dayInWeek): array
    {
        $monday = CarbonImmutable::parse($dayInWeek)->startOfWeek(CarbonInterface::MONDAY);
        $sunday = $monday->addDays(6);
        $driver->loadMissing('user');

        $reservations = Reservation::query()
            ->with(['organization', 'requestedByUser', 'pickupLocation', 'destinationLocation', 'vehicle'])
            ->where('driver_id', $driver->getKey())
            ->where('status', ReservationStatus::CONFIRME->value)
            ->whereBetween('trip_date', [$monday->toDateString(), $sunday->toDateString()])
            ->orderBy('trip_date')
            ->orderBy('departure_time')
            ->get()
            // Toutes au même chauffeur : évite de recharger driver et driver.user pour chaque ligne (buildDailyRow)
            ->each(fn (Reservation $reservation) => $reservation->setRelation('driver', $driver));

        $reservationsByDay = $reservations->groupBy(fn (Reservation $reservation): string => $reservation->trip_date->toDateString());

        $days = [];
        for ($offset = 0; $offset < 7; $offset++) {
            $day = $monday->addDays($offset);
            $dayReservations = $reservationsByDay->get($day->toDateString(), collect());

            if ($offset >= 5 && $dayReservations->isEmpty()) {
                continue;
            }

            $days[] = [
                'date' => $day->toDateString(),
                'date_label' => $day->format('d/m/Y'),
                'day_label' => ucfirst($day->locale('fr')->isoFormat('dddd')),
                'rows' => $dayReservations
                    ->map(fn (Reservation $reservation): array => [
                        ...$this->buildDailyRow($reservation),
                        'vehicle' => $reservation->vehicle ? [
                            'licence_plate' => $reservation->vehicle->licence_plate,
                            'capacity' => $reservation->vehicle->capacity,
                        ] : null,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return [
            'document' => [
                'type' => DocumentType::FEUILLE_ROUTE->value,
                'label' => DocumentType::FEUILLE_ROUTE->label(),
                'template_key' => self::DRIVER_TEMPLATE_KEY,
                'template_version' => self::TEMPLATE_VERSION,
                'generated_at' => now()->toISOString(),
            ],
            'period' => [
                'start_date' => $monday->toDateString(),
                'end_date' => $sunday->toDateString(),
                // Jusqu'au dernier jour affiché : vendredi, ou samedi / dimanche s'ils ont des missions
                'label' => sprintf('du %s au %s', $monday->format('d/m/Y'), end($days)['date_label']),
            ],
            'driver' => $this->formatDriver($driver),
            'transporter' => [
                'name' => config('route_sheets.transporter.name'),
                'identifier' => config('route_sheets.transporter.identifier'),
                'emergency_contact' => config('route_sheets.transporter.emergency_contact'),
            ],
            'totals' => [
                'missions' => $reservations->count(),
                'planned_passengers' => $reservations->sum(fn (Reservation $reservation): int => ($reservation->nb_children ?? 0) + ($reservation->nb_adults ?? 0)),
                'planned_distance_km' => round($reservations->sum(fn (Reservation $reservation): float => $this->roundTripKm($reservation) ?? 0), 2),
            ],
            'days' => $days,
        ];
    }

    private function buildRow(Reservation $reservation): array
    {
        return [
            'reservation_id' => $reservation->id,

            'date' => $this->formatDate($reservation->trip_date),
            'day_label' => $this->formatDayLabel($reservation->trip_date),

            'beneficiary' => [
                'name' => $reservation->organization?->name,
                'contact_name' => $this->beneficiaryContactName($reservation),
            ],

            'passengers' => [
                'planned_children' => $reservation->nb_children ?? 0,
                'planned_adults' => $reservation->nb_adults ?? 0,
                'planned_total' => ($reservation->nb_children ?? 0) + ($reservation->nb_adults ?? 0),
                'actual_total' => null,
            ],

            'pickup' => [
                'time' => $this->formatTime($reservation->departure_time),
                'address' => $reservation->pickupLocation?->formatted_address,
            ],

            'destination' => [
                'label' => $reservation->destinationLocation?->formatted_address,
                'address' => $reservation->destinationLocation?->formatted_address,
                'latitude' => $this->formatCoordinate($reservation->destinationLocation?->latitude),
                'longitude' => $this->formatCoordinate($reservation->destinationLocation?->longitude),
                'navigation_url' => $this->buildNavigationUrl($reservation->destinationLocation),
            ],

            'return_pickup_time' => $this->formatTime($reservation->return_time),

            'validation' => $this->validationData($reservation),
        ];
    }

    private function buildDailyRow(Reservation $reservation): array
    {
        $pickupAddress = $reservation->pickupLocation?->formatted_address;
        $destinationAddress = $reservation->destinationLocation?->formatted_address;

        return [
            'reservation_id' => $reservation->id,
            'order_giver' => [
                'name' => $reservation->organization?->name,
                'contact_name' => $this->beneficiaryContactName($reservation),
                'email' => $reservation->organization?->official_email ?? $reservation->requestedByUser?->email,
                'phone' => $reservation->organization?->phone_contact ?? $reservation->requestedByUser?->phone,
            ],
            'driver' => $reservation->driver ? $this->formatDriver($reservation->driver) : null,
            'departure' => [
                'time' => $this->formatTime($reservation->departure_time),
                'place' => $pickupAddress,
            ],
            'destination' => [
                'place' => $destinationAddress,
                'navigation_url' => $this->buildNavigationUrl($reservation->destinationLocation),
            ],
            'return_departure' => [
                'time' => $this->formatTime($reservation->return_time),
                'place' => $destinationAddress,
            ],
            'main_route' => $this->mainRouteLabel($pickupAddress, $destinationAddress),
            'passengers' => [
                'planned_children' => $reservation->nb_children ?? 0,
                'planned_adults' => $reservation->nb_adults ?? 0,
                'planned_total' => ($reservation->nb_children ?? 0) + ($reservation->nb_adults ?? 0),
            ],
            'distance_km' => $reservation->distance_km !== null ? (float) $reservation->distance_km : null,
            'round_trip_km' => $this->roundTripKm($reservation),
            'purpose' => $reservation->trip_purpose,
        ];
    }

    /**
     * Kilomètres du trajet sur une feuille de route : aller-retour. distance_km (ORS) ne mesure que l'aller,
     * départ → destination ; le bus revient ensuite au lieu de départ. Null si la distance est inconnue.
     */
    private function roundTripKm(Reservation $reservation): ?float
    {
        return $reservation->distance_km !== null ? round((float) $reservation->distance_km * 2, 2) : null;
    }

    private function formatDriver(Driver $driver): array
    {
        return [
            'id' => $driver->id,
            'name' => trim(($driver->user?->first_name ?? '').' '.($driver->user?->last_name ?? '')) ?: null,
            'email' => $driver->user?->email,
            'phone' => $driver->user?->phone,
            'licence_number' => $driver->licence_number,
        ];
    }

    private function mainRouteLabel(?string $pickupAddress, ?string $destinationAddress): ?string
    {
        if ($pickupAddress === null && $destinationAddress === null) {
            return null;
        }

        if ($pickupAddress === null) {
            return $destinationAddress;
        }

        if ($destinationAddress === null) {
            return $pickupAddress;
        }

        return $pickupAddress.' - '.$destinationAddress.' - '.$pickupAddress;
    }

    private function beneficiaryContactName(Reservation $reservation): ?string
    {
        if ($reservation->organization?->name_contact) {
            return $reservation->organization->name_contact;
        }

        if ($reservation->requestedByUser) {
            return trim(
                ($reservation->requestedByUser->first_name ?? '')
                .' '.
                ($reservation->requestedByUser->last_name ?? '')
            ) ?: null;
        }

        return null;
    }

    private function validationData(Reservation $reservation): array
    {
        if ($reservation->councilDecision?->validated_at) {
            return [
                'date' => $this->formatDate($reservation->councilDecision->validated_at),
                'source' => 'council_decision',
            ];
        }

        if ($reservation->councilDecision?->decision_date) {
            return [
                'date' => $this->formatDate($reservation->councilDecision->decision_date),
                'source' => 'council_decision',
            ];
        }

        if ($reservation->validated_at) {
            return [
                'date' => $this->formatDate($reservation->validated_at),
                'source' => 'reservation_validation',
            ];
        }

        return [
            'date' => null,
            'source' => null,
        ];
    }

    private function buildNavigationUrl(?Location $location): ?string
    {
        if (! $location) {
            return null;
        }

        if ($location->latitude && $location->longitude) {
            return 'https://www.google.com/maps/dir/?api=1&destination='
                .rawurlencode($location->latitude.','.$location->longitude);
        }

        if ($location->formatted_address) {
            return 'https://www.google.com/maps/search/?api=1&query='
                .rawurlencode($location->formatted_address);
        }

        return null;
    }

    private function formatDate(mixed $date): ?string
    {
        if (! $date) {
            return null;
        }

        return $this->toCarbon($date)->format('d/m/Y');
    }

    private function formatTime(mixed $time): ?string
    {
        if (! $time) {
            return null;
        }

        return $this->toCarbon($time)->format('H\hi');
    }

    private function formatDayLabel(mixed $date): ?string
    {
        if (! $date) {
            return null;
        }

        return ucfirst($this->toCarbon($date)->locale('fr')->isoFormat('dddd'));
    }

    private function formatCoordinate(mixed $coordinate): ?string
    {
        if ($coordinate === null || $coordinate === '') {
            return null;
        }

        return (string) $coordinate;
    }

    private function toCarbon(mixed $value): CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        return CarbonImmutable::parse($value);
    }
}

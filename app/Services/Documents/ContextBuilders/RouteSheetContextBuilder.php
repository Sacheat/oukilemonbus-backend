<?php

namespace App\Services\Documents\ContextBuilders;

use App\Enums\ReservationStatus;
use App\Models\Location;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use App\Enums\DocumentType;

class RouteSheetContextBuilder
{
    private const TEMPLATE_KEY = 'route_sheets.weekly';
    private const TEMPLATE_VERSION = '1.0';

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

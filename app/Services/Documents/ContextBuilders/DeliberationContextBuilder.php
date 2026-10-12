<?php

namespace App\Services\Documents\ContextBuilders;

use App\Models\CouncilDecision;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Construit le contexte métier nécessaire à la génération d'une délibération.
 *
 * Cette classe transforme simplement une CouncilDecision et ses relations en tableau structuré,
 * prêt à être utilisé par un futur générateur ou template Blade.
 */
class DeliberationContextBuilder
{
    private const TEMPLATE_KEY = 'deliberations.external';
    private const TEMPLATE_VERSION = '1.0';

    public function build(CouncilDecision $councilDecision): array
    {
        $councilDecision->loadMissing([
            'recordedByUser',
            'reservation.requestedByUser',
            'reservation.organization.location',
            'reservation.pickupLocation',
            'reservation.destinationLocation',
            'reservation.driver.user',
            'reservation.vehicle',
        ]);

        $reservation = $councilDecision->reservation;
        $organization = $reservation?->organization;

        return [
            'document' => [
                'type' => 'deliberation',
                'template_key' => self::TEMPLATE_KEY,
                'template_version' => self::TEMPLATE_VERSION,
                'generated_at' => now()->toISOString(),
            ],

            'decision' => [
                'id' => $councilDecision->id,
                'status' => $this->enumValue($councilDecision->decision_status),
                'status_label' => $this->enumLabel($councilDecision->decision_status),
                'date' => $this->formatDate($councilDecision->decision_date),
                'reference' => $councilDecision->decision_reference,
                'validated_at' => $this->formatDateTime($councilDecision->validated_at),
                'notes' => $councilDecision->notes,
                'recorded_by' => $this->formatUser($councilDecision->recordedByUser),
            ],

            'reservation' => [
                'id' => $reservation?->id,
                'trip_date' => $this->formatDate($reservation?->trip_date),
                'departure_time' => $this->formatTime($reservation?->departure_time),
                'return_time' => $this->formatTime($reservation?->return_time),
                'trip_purpose' => $reservation?->trip_purpose,
                'activity_type' => $this->enumValue($reservation?->activity_type),
                'activity_type_label' => $this->enumLabel($reservation?->activity_type),
                'nb_children' => $reservation?->nb_children,
                'nb_adults' => $reservation?->nb_adults,
                'total_passengers' => ($reservation?->nb_children ?? 0) + ($reservation?->nb_adults ?? 0),
                'distance_km' => $reservation?->distance_km,
                'estimated_duration_min' => $reservation?->estimated_duration_min,
                'status' => $this->enumValue($reservation?->status),
                'status_label' => $this->enumLabel($reservation?->status),
                'approval_type' => $this->enumValue($reservation?->approval_type),
                'approval_type_label' => $this->enumLabel($reservation?->approval_type),
                'requested_by' => $this->formatUser($reservation?->requestedByUser),
            ],

            'organization' => [
                'id' => $organization?->id,
                'name' => $organization?->name,
                'type' => $this->enumValue($organization?->type),
                'type_label' => $this->enumLabel($organization?->type),
                'official_email' => $organization?->official_email,
                'phone_contact' => $organization?->phone_contact,
                'name_contact' => $organization?->name_contact,
                'is_priority' => $organization?->is_priority,
                'annual_quota' => $organization?->annual_quota,
                'reservations_used_this_year' => $organization?->reservations_used_this_year,
            ],

            'locations' => [
                'organization' => $this->formatLocation($organization?->location),
                'pickup' => $this->formatLocation($reservation?->pickupLocation),
                'destination' => $this->formatLocation($reservation?->destinationLocation),
            ],

            'assignment' => [
                'driver' => [
                    'id' => $reservation?->driver?->id,
                    // RGPD, minimisation : le modèle n'affiche que le nom ; ni contact ni permis dans le contexte gardé
                    'name' => $this->fullName($reservation?->driver?->user),
                ],
                'vehicle' => [
                    'id' => $reservation?->vehicle?->id,
                    'licence_plate' => $reservation?->vehicle?->licence_plate,
                    'capacity' => $reservation?->vehicle?->capacity,
                ],
            ],
        ];
    }

    private function formatUser(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'full_name' => $this->fullName($user),
            'email' => $user->email,
            'phone' => $user->phone,
        ];
    }

    private function fullName(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        return trim($user->first_name.' '.$user->last_name);
    }

    private function formatLocation(?Location $location): ?array
    {
        if ($location === null) {
            return null;
        }

        return [
            'id' => $location->id,
            'formatted_address' => $location->formatted_address,
            'street' => $location->street,
            'street_number' => $location->street_number,
            'postal_code' => $location->postal_code,
            'city' => $location->city,
            'country' => $location->country,
            'latitude' => $location->latitude,
            'longitude' => $location->longitude,
        ];
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->toDateString();
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->toISOString();
    }

    private function formatTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->format('H:i');
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }

    private function enumLabel(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (method_exists($value, 'label')) {
            return $value->label();
        }

        return $this->enumValue($value);
    }
}

<?php

namespace App\Enums;

/**
 * Types de conflits détectés lors de la création ou de la modification d'une réservation.
 *
 * Deux natures de conflits coexistent :
 * - Hard-block : la réservation est rejetée (422) car le créneau est physiquement occupé.
 *   → Seul SCHEDULE_OVERLAP avec une réservation APPROUVE ou CONFIRME est un hard-block.
 * - Soft conflict : la réservation est créée en statut EN_CONFLIT et nécessite une action
 *   d'un agent pour être résolue (manuellement ou automatiquement à l'annulation du bloquant).
 *
 * Tous les autres types sont des soft conflicts.
 */
enum ReservationConflictType: string
{
    // Soft conflict — ORS et le fallback Haversine ont tous les deux échoué.
    case DISTANCE_NOT_CALCULATED = 'distance_not_calculated';

    // Hard-block si la réservation chevauchante est APPROUVE ou CONFIRME,
    // soft conflict si elle est seulement EN_ATTENTE.
    case SCHEDULE_OVERLAP = 'schedule_overlap';

    // Soft conflicts — violations des règles horaires du règlement communal.
    case DEPARTURE_BEFORE_08H           = 'departure_before_08h';
    case RETURN_AFTER_17H               = 'return_after_17h';
    case DEPARTURE_BEFORE_ALLOWED_TIME  = 'departure_before_allowed_time';
    case RETURN_AFTER_ALLOWED_TIME      = 'return_after_allowed_time';

    // Hard-block — la fenêtre aller-retour est physiquement impossible (retour avant le départ, etc.).
    case IMPOSSIBLE_TIME_WINDOW = 'impossible_time_window';

    // Soft conflicts — règles de durée, quota et disponibilité des ressources.
    case DURATION_OVER_ALLOWED_LIMIT    = 'duration_over_allowed_limit';
    case QUOTA_EXCEEDED                 = 'quota_exceeded';
    case NO_DRIVER_AVAILABLE            = 'no_driver_available';
    case NO_VEHICLE_AVAILABLE           = 'no_vehicle_available';
    case VEHICLE_CAPACITY_UNAVAILABLE   = 'vehicle_capacity_unavailable';

    public function label(): string
    {
        return match ($this) {
            self::DISTANCE_NOT_CALCULATED      => 'Distance non calculée',
            self::SCHEDULE_OVERLAP             => 'Chevauchement de planning',
            self::DEPARTURE_BEFORE_08H         => 'Départ avant 08h00',
            self::RETURN_AFTER_17H             => 'Retour après 17h00',
            self::DEPARTURE_BEFORE_ALLOWED_TIME => 'Départ avant l’heure autorisée',
            self::RETURN_AFTER_ALLOWED_TIME    => 'Retour après l’heure autorisée',
            self::IMPOSSIBLE_TIME_WINDOW       => 'Créneau impossible',
            self::DURATION_OVER_ALLOWED_LIMIT  => 'Durée maximale dépassée',
            self::QUOTA_EXCEEDED               => 'Quota dépassé',
            self::NO_DRIVER_AVAILABLE          => 'Aucun chauffeur disponible',
            self::NO_VEHICLE_AVAILABLE         => 'Aucun véhicule disponible',
            self::VEHICLE_CAPACITY_UNAVAILABLE => 'Aucun véhicule de capacité suffisante',
        };
    }

    public static function toArray(): array
    {
        return array_column(
            array_map(fn ($case) => [$case->value, $case->label()], self::cases()),
            1,
            0
        );
    }
}

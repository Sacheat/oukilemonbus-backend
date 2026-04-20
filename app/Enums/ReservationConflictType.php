<?php

namespace App\Enums;

enum ReservationConflictType: string
{
    case DISTANCE_NOT_CALCULATED = 'distance_not_calculated';
    case SCHEDULE_OVERLAP = 'schedule_overlap';
    case DEPARTURE_BEFORE_08H = 'departure_before_08h';
    case RETURN_AFTER_17H = 'return_after_17h';
    case DEPARTURE_BEFORE_ALLOWED_TIME = 'departure_before_allowed_time';
    case RETURN_AFTER_ALLOWED_TIME = 'return_after_allowed_time';
    case IMPOSSIBLE_TIME_WINDOW = 'impossible_time_window';
    case DURATION_OVER_ALLOWED_LIMIT = 'duration_over_allowed_limit';
    case QUOTA_EXCEEDED = 'quota_exceeded';
    case NO_DRIVER_AVAILABLE = 'no_driver_available';
    case NO_VEHICLE_AVAILABLE = 'no_vehicle_available';
    case VEHICLE_CAPACITY_UNAVAILABLE = 'vehicle_capacity_unavailable';

    public function label(): string
    {
        return match ($this) {
            self::DISTANCE_NOT_CALCULATED => 'Distance non calculee',
            self::SCHEDULE_OVERLAP => 'Chevauchement de planning',
            self::DEPARTURE_BEFORE_08H => 'Depart avant 08h00',
            self::RETURN_AFTER_17H => 'Retour apres 17h00',
            self::DEPARTURE_BEFORE_ALLOWED_TIME => 'Depart avant l heure autorisee',
            self::RETURN_AFTER_ALLOWED_TIME => 'Retour apres l heure autorisee',
            self::IMPOSSIBLE_TIME_WINDOW => 'Creneau impossible',
            self::DURATION_OVER_ALLOWED_LIMIT => 'Duree maximale depassee',
            self::QUOTA_EXCEEDED => 'Quota depasse',
            self::NO_DRIVER_AVAILABLE => 'Aucun chauffeur disponible',
            self::NO_VEHICLE_AVAILABLE => 'Aucun vehicule disponible',
            self::VEHICLE_CAPACITY_UNAVAILABLE => 'Aucun vehicule de capacite suffisante',
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

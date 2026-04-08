<?php

namespace App\Enums;

enum UnavailabilityReasonType: string
{
    case DRIVER = 'driver';
    case VEHICLE = 'vehicle';

    public function label(): string
    {
        return match ($this) {
            self::DRIVER => 'Chauffeur',
            self::VEHICLE => 'Véhicule',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::DRIVER => 'Indisponibilité liée au chauffeur',
            self::VEHICLE => 'Indisponibilité liée au véhicule',
        };
    }

    public static function toArray(): array
    {
        return array_column(
            array_map(fn($case) => [$case->value, $case->label()], self::cases()),
            1,
            0
        );
    }
}

<?php

namespace App\Enums;

enum AssignmentType: string
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
            self::DRIVER => 'Assignation de chauffeur',
            self::VEHICLE => 'Assignation de véhicule',
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

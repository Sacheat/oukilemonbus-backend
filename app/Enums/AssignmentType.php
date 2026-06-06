<?php

namespace App\Enums;

/**
 * Nature de la ressource affectée à une réservation.
 * Utilisé dans l'historique d'affectation pour distinguer les changements de chauffeur
 * des changements de véhicule et conserver une trace séparée de chaque type.
 */
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

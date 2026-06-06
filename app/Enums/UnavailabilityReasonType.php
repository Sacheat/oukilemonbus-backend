<?php

namespace App\Enums;

/**
 * Ressource concernée par une indisponibilité.
 * Une indisponibilité DRIVER bloque l'affectation du chauffeur sur le créneau.
 * Une indisponibilité VEHICLE bloque l'affectation du véhicule sur le créneau.
 * Les deux types sont vérifiés indépendamment lors de la détection des conflits.
 */
enum UnavailabilityReasonType: string
{
    case DRIVER  = 'driver';
    case VEHICLE = 'vehicle';

    public function label(): string
    {
        return match ($this) {
            self::DRIVER  => 'Chauffeur',
            self::VEHICLE => 'Véhicule',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::DRIVER  => 'Indisponibilité liée au chauffeur',
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

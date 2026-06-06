<?php

namespace App\Enums;

/**
 * Type d'activité d'une réservation scolaire.
 * Influence la priorité d'ordonnancement : la gymnastique et la psychomotricité
 * sont des activités physiques à horaire contraint, traitées en priorité haute
 * pour les écoles communales afin de respecter les créneaux imposés par les programmes.
 */
enum ReservationActivityType: string
{
    case STANDARD        = 'standard';
    case GYMNASTIQUE     = 'gymnastique';
    case PSYCHOMOTRICITE = 'psychomotricite';

    public function label(): string
    {
        return match ($this) {
            self::STANDARD        => 'Standard',
            self::GYMNASTIQUE     => 'Gymnastique',
            self::PSYCHOMOTRICITE => 'Psychomotricite',
        };
    }

    /**
     * Indique si l'activité bénéficie d'une priorité haute pour les écoles communales.
     * Ces activités ont des créneaux horaires imposés par l'infrastructure sportive externe.
     */
    public function isSchoolPriorityActivity(): bool
    {
        return in_array($this, [self::GYMNASTIQUE, self::PSYCHOMOTRICITE], true);
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

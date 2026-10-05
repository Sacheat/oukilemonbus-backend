<?php

namespace App\Enums;

/**
 * Étapes d'un trajet, dans l'ordre : l'aller (lieu de départ → destination), puis le retour (destination → lieu de
 * départ). Chaque étape suppose la précédente (voir TripProgressService).
 */
enum TripEventType: string
{
    case DEPART_ALLER = 'depart_aller';
    case ARRIVEE_ALLER = 'arrivee_aller';
    case DEPART_RETOUR = 'depart_retour';
    case ARRIVEE_RETOUR = 'arrivee_retour';

    /** @return list<self> dans l'ordre du trajet */
    public static function ordered(): array
    {
        return [self::DEPART_ALLER, self::ARRIVEE_ALLER, self::DEPART_RETOUR, self::ARRIVEE_RETOUR];
    }

    public function position(): int
    {
        return array_search($this, self::ordered(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::DEPART_ALLER => 'Départ',
            self::ARRIVEE_ALLER => 'Arrivée à destination',
            self::DEPART_RETOUR => 'Départ du retour',
            self::ARRIVEE_RETOUR => 'Arrivée au lieu de départ',
        };
    }
}

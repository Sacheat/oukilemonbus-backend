<?php

namespace App\Enums;

/** Résultat d'un trajet déclaré par le chauffeur (ou corrigé par un agent). */
enum TripReportOutcome: string
{
    case REALISE = 'realise';
    case NON_REALISE = 'non_realise';

    public function label(): string
    {
        return match ($this) {
            self::REALISE => 'Réalisé',
            self::NON_REALISE => 'Non réalisé',
        };
    }
}

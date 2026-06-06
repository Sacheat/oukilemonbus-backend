<?php

namespace App\Enums;

/**
 * Fréquence de répétition d'une série de réservations récurrentes.
 * La génération des occurrences exclut automatiquement les weekends et les dates fermées.
 */
enum RecurrenceFrequency: string
{
    case HEBDOMADAIRE    = 'hebdomadaire';
    case BIHEBDOMADAIRE  = 'bihebdomadaire';
    case MENSUEL         = 'mensuel';

    public function label(): string
    {
        return match ($this) {
            self::HEBDOMADAIRE   => 'Hebdomadaire',
            self::BIHEBDOMADAIRE => 'Bihebdomadaire',
            self::MENSUEL        => 'Mensuel',
        };
    }

    /**
     * Intervalle en jours entre deux occurrences.
     * Retourne 0 pour MENSUEL car la logique mensuelle est calculée par Carbon (mois calendaires)
     * plutôt qu'un nombre fixe de jours.
     */
    public function intervalDays(): int
    {
        return match ($this) {
            self::HEBDOMADAIRE   => 7,
            self::BIHEBDOMADAIRE => 14,
            self::MENSUEL        => 0,
        };
    }
}

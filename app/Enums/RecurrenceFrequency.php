<?php

namespace App\Enums;

enum RecurrenceFrequency: string
{
    case HEBDOMADAIRE = 'hebdomadaire';
    case BIHEBDOMADAIRE = 'bihebdomadaire';
    case MENSUEL = 'mensuel';

    public function label(): string
    {
        return match ($this) {
            self::HEBDOMADAIRE => 'Hebdomadaire',
            self::BIHEBDOMADAIRE => 'Bihebdomadaire',
            self::MENSUEL => 'Mensuel',
        };
    }

    public function intervalDays(): int
    {
        return match ($this) {
            self::HEBDOMADAIRE => 7,
            self::BIHEBDOMADAIRE => 14,
            self::MENSUEL => 0,
        };
    }
}
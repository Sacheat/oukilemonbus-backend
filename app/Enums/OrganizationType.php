<?php

namespace App\Enums;

enum OrganizationType: string
{
    case ECOLE_COMMUNALE = 'ecole_communale';
    case ASBL = 'asbl';
    case EXTERNE = 'externe';

    /**
     * Obtenir le label lisible en français
     */
    public function label(): string
    {
        return match ($this) {
            self::ECOLE_COMMUNALE => 'École Communale',
            self::ASBL => 'ASBL',
            self::EXTERNE => 'Externe',
        };
    }

    /**
     * Vérifier si l'organisation est prioritaire par défaut
     */
    public function isPriorityByDefault(): bool
    {
        return $this === self::ECOLE_COMMUNALE;
    }

    /**
     * Obtenir tous les types sous forme de tableau [value => label]
     */
    public static function toArray(): array
    {
        return array_column(
            array_map(fn($case) => [$case->value, $case->label()], self::cases()),
            1,
            0
        );
    }
}

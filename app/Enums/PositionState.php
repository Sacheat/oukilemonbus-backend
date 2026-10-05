<?php

namespace App\Enums;

/** État de la position relevée avec un événement ou une alerte : envoyée, refusée par le téléphone, ou indisponible. */
enum PositionState: string
{
    case ENVOYEE = 'envoyee';
    case REFUSEE = 'refusee';
    case INDISPONIBLE = 'indisponible';

    public function label(): string
    {
        return match ($this) {
            self::ENVOYEE => 'Position envoyée',
            self::REFUSEE => 'Partage de position refusé',
            self::INDISPONIBLE => 'Position indisponible',
        };
    }
}

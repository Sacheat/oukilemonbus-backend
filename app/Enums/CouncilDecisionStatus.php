<?php

namespace App\Enums;

/**
 * Statut d'une décision du Collège communal liée à une réservation.
 * Une décision APPROUVE peut lever certains conflits de dérogation (ex: horaire).
 * Une décision REFUSE entraîne le refus automatique de la réservation associée.
 */
enum CouncilDecisionStatus: string
{
    case APPROUVE = 'approuve';
    case REFUSE = 'refuse';
    case EN_ATTENTE = 'en_attente';

    public function label(): string
    {
        return match ($this) {
            self::APPROUVE => 'Approuvé',
            self::REFUSE => 'Refusé',
            self::EN_ATTENTE => 'En attente',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::APPROUVE => 'success',
            self::REFUSE => 'danger',
            self::EN_ATTENTE => 'warning',
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

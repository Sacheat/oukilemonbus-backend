<?php

namespace App\Support;

/**
 * Niveau lisible d'une note de confiance (0-100), pour que le front colore une pastille sans connaître les seuils :
 * faible (moins de 40), moyen (40 à 69), élevé (70 et plus).
 */
final class ConfidenceLevel
{
    public const string LOW = 'faible';

    public const string MEDIUM = 'moyen';

    public const string HIGH = 'eleve';

    private const int MEDIUM_FROM = 40;

    private const int HIGH_FROM = 70;

    public static function fromScore(?int $score): ?string
    {
        return match (true) {
            $score === null => null,
            $score >= self::HIGH_FROM => self::HIGH,
            $score >= self::MEDIUM_FROM => self::MEDIUM,
            default => self::LOW,
        };
    }

    public static function label(?string $level): ?string
    {
        return match ($level) {
            self::LOW => 'Faible',
            self::MEDIUM => 'Moyen',
            self::HIGH => 'Élevé',
            default => null,
        };
    }
}

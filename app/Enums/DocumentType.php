<?php

namespace App\Enums;

enum DocumentType: string
{
    case FEUILLE_ROUTE = 'feuille_route';
    case ATTESTATION = 'attestation';
    case DELIBERATION = 'deliberation';
    case PLANNING = 'planning';

    public function label(): string
    {
        return match ($this) {
            self::FEUILLE_ROUTE => 'Feuille de route',
            self::ATTESTATION => 'Attestation',
            self::DELIBERATION => 'Délibération',
            self::PLANNING => 'Planning',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::FEUILLE_ROUTE => 'document-text',
            self::ATTESTATION => 'badge-check',
            self::DELIBERATION => 'clipboard-document-check',
            self::PLANNING => 'calendar',
        };
    }

    public function requiresOfficial(): bool
    {
        return in_array($this, [self::DELIBERATION, self::ATTESTATION]);
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

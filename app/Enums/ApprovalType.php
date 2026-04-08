<?php

namespace App\Enums;

enum ApprovalType: string
{
    case AUTO = 'auto';
    case MANUEL = 'manuel';

    public function label(): string
    {
        return match ($this) {
            self::AUTO => 'Automatique',
            self::MANUEL => 'Manuel',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AUTO => 'Validation automatique sans intervention',
            self::MANUEL => 'Validation manuelle requise',
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

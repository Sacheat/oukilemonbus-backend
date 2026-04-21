<?php

namespace App\Enums;

enum ReservationActivityType: string
{
    case STANDARD = 'standard';
    case GYMNASTIQUE = 'gymnastique';
    case PSYCHOMOTRICITE = 'psychomotricite';

    public function label(): string
    {
        return match ($this) {
            self::STANDARD => 'Standard',
            self::GYMNASTIQUE => 'Gymnastique',
            self::PSYCHOMOTRICITE => 'Psychomotricite',
        };
    }

    public function isSchoolPriorityActivity(): bool
    {
        return in_array($this, [self::GYMNASTIQUE, self::PSYCHOMOTRICITE], true);
    }

    public static function toArray(): array
    {
        return array_column(
            array_map(fn ($case) => [$case->value, $case->label()], self::cases()),
            1,
            0
        );
    }
}

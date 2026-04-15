<?php

namespace App\Enums;

enum RequestStatus: string
{
    case EN_ATTENTE = 'en_attente';
    case APPROUVE = 'approuve';
    case REFUSE = 'refuse';

    public function label(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'En attente',
            self::APPROUVE => 'Approuve',
            self::REFUSE => 'Refuse',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'warning',
            self::APPROUVE => 'success',
            self::REFUSE => 'danger',
        };
    }

    public function isPending(): bool
    {
        return $this === self::EN_ATTENTE;
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

<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case EN_ATTENTE = 'en_attente';
    case APPROUVE = 'approuve';
    case CONFIRME = 'confirme';
    case REFUSE = 'refuse';
    case ANNULE = 'annule';
    case EN_CONFLIT = 'en_conflit';

    public function label(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'En attente',
            self::APPROUVE => 'Approuve',
            self::CONFIRME => 'Confirme',
            self::REFUSE => 'Refuse',
            self::ANNULE => 'Annule',
            self::EN_CONFLIT => 'En conflit',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'warning',
            self::APPROUVE => 'success',
            self::CONFIRME => 'success',
            self::REFUSE => 'danger',
            self::ANNULE => 'secondary',
            self::EN_CONFLIT => 'error',
        };
    }

    public function isFinal(): bool
    {
        return match ($this) {
            self::REFUSE, self::ANNULE => true,
            default => false,
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::EN_ATTENTE => [self::EN_CONFLIT, self::APPROUVE, self::REFUSE, self::ANNULE],
            self::EN_CONFLIT => [self::EN_ATTENTE, self::EN_CONFLIT, self::APPROUVE, self::REFUSE, self::ANNULE],
            self::APPROUVE   => [self::EN_CONFLIT, self::CONFIRME, self::REFUSE, self::ANNULE],
            self::CONFIRME   => [self::EN_CONFLIT, self::ANNULE],
            self::REFUSE, self::ANNULE => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
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

<?php

namespace App\Enums;

/**
 * Statut d'une tentative de livraison de notification (table notification_deliveries).
 * PENDING : en attente dans la queue ou bloquée par les quiet hours / le digest.
 * SENT : livraison confirmée auprès du canal (in-app enregistrée, email transmis au provider).
 * FAILED : toutes les tentatives ont échoué ; consultable via notifications:health.
 */
enum DeliveryStatus: string
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'En attente',
            self::SENT => 'Envoyé',
            self::FAILED => 'Échec',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::SENT => 'success',
            self::FAILED => 'danger',
        };
    }

    public function isSuccessful(): bool
    {
        return $this === self::SENT;
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

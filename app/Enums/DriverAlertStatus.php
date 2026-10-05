<?php

namespace App\Enums;

/** Cycle d'une demande d'aide : ouverte → prise en charge par un agent → clôturée (avec une note). */
enum DriverAlertStatus: string
{
    case OUVERTE = 'ouverte';
    case PRISE_EN_CHARGE = 'prise_en_charge';
    case CLOTUREE = 'cloturee';

    public function label(): string
    {
        return match ($this) {
            self::OUVERTE => 'Ouverte',
            self::PRISE_EN_CHARGE => 'Prise en charge',
            self::CLOTUREE => 'Clôturée',
        };
    }

    public function isOpen(): bool
    {
        return $this !== self::CLOTUREE;
    }
}

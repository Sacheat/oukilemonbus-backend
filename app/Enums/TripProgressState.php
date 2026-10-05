<?php

namespace App\Enums;

/** Avancement d'un trajet, déduit de ses événements (TripProgressService::stateFor). */
enum TripProgressState: string
{
    case A_VENIR = 'a_venir';
    case EN_ROUTE_ALLER = 'en_route_aller';
    case SUR_PLACE = 'sur_place';
    case EN_ROUTE_RETOUR = 'en_route_retour';
    case TERMINE = 'termine';

    public function label(): string
    {
        return match ($this) {
            self::A_VENIR => 'À venir',
            self::EN_ROUTE_ALLER => 'En route',
            self::SUR_PLACE => 'Sur place',
            self::EN_ROUTE_RETOUR => 'Retour en cours',
            self::TERMINE => 'Terminé',
        };
    }

    /** État atteint après une étape */
    public static function after(TripEventType $type): self
    {
        return match ($type) {
            TripEventType::DEPART_ALLER => self::EN_ROUTE_ALLER,
            TripEventType::ARRIVEE_ALLER => self::SUR_PLACE,
            TripEventType::DEPART_RETOUR => self::EN_ROUTE_RETOUR,
            TripEventType::ARRIVEE_RETOUR => self::TERMINE,
        };
    }
}

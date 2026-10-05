<?php

namespace App\Enums;

/**
 * Qui a produit un événement de trajet. Aujourd'hui le chauffeur (bouton) ou un agent ; GPS et SYSTEME sont prévus
 * pour un suivi automatique (application de suivi, géorepérage), qui écrira les mêmes événements.
 */
enum TripEventSource: string
{
    case CHAUFFEUR = 'chauffeur';
    case AGENT = 'agent';
    case GPS = 'gps';
    case SYSTEME = 'systeme';
}

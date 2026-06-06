<?php

namespace App\Exceptions;

use Exception;

/**
 * Lancée par LocationService quand le géocodage ou le calcul d'itinéraire échoue.
 *
 * Cas couverts :
 * - Coordonnées manquantes sur le lieu de départ ou la destination.
 * - L'API OpenRouteService est indisponible ou renvoie une erreur.
 * - La configuration ORS est incomplète (ORS_API_KEY absent).
 *
 * Attrapée par ReservationPreviewService pour produire un conflit DISTANCE_NOT_CALCULATED
 * (soft conflict) plutôt que de bloquer la création de la réservation.
 * Attrapée par LocationController pour renvoyer une réponse 422 lors du géocodage manuel.
 */
class GeocodingException extends Exception
{
}

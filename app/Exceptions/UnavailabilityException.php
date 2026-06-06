<?php

namespace App\Exceptions;

use Exception;

/**
 * Lancée par UnavailabilityService quand une règle métier d'indisponibilité est violée.
 *
 * Cas couverts :
 * - La ressource (chauffeur ou véhicule) est déjà affectée à une réservation sur ce créneau.
 * - Une indisponibilité qui se chevauche existe déjà pour cette ressource.
 * - Tentative d'annulation d'une indisponibilité déjà terminée ou déjà annulée.
 * - Tentative de modification d'une indisponibilité inactive.
 *
 * Attrapée par UnavailabilityController pour renvoyer une réponse 422 avec le message métier.
 * Ne doit pas être laissée remonter jusqu'au handler global (pas un bug, c'est une règle métier).
 */
class UnavailabilityException extends Exception
{
}

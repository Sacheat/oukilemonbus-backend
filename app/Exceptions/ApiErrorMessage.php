<?php

namespace App\Exceptions;

/**
 * Messages d'erreur HTTP renvoyés au frontend en français.
 *
 * Laravel et Spatie écrivent certains messages en dur en anglais (sans passer par les
 * fichiers de langue) : ils sont remplacés ici. Un message métier déjà rédigé
 * (ex. abort(403, 'Vous ne pouvez pas annuler cette récurrence.')) est conservé tel quel.
 */
final class ApiErrorMessage
{
    private const array KNOWN = [
        'Unauthenticated.' => 'Vous devez être connecté.',
        'Your email address is not verified.' => 'Votre adresse e-mail n’est pas encore vérifiée.',
        'This action is unauthorized.' => 'Vous n’êtes pas autorisé à effectuer cette action.',
        'User does not have the right permissions.' => 'Vous n’avez pas les droits nécessaires pour cette action.',
        'User does not have the right roles.' => 'Vous n’avez pas les droits nécessaires pour cette action.',
        'CSRF token mismatch.' => 'Votre session a expiré. Rechargez la page et réessayez.',
        'Too Many Attempts.' => 'Trop de tentatives. Réessayez dans un instant.',
    ];

    private const array BY_STATUS = [
        401 => 'Vous devez être connecté.',
        403 => 'Vous n’êtes pas autorisé à effectuer cette action.',
        404 => 'Ressource introuvable.',
        405 => 'Méthode non autorisée pour cette route.',
        409 => 'Cette requête est déjà en cours de traitement.',
        419 => 'Votre session a expiré. Rechargez la page et réessayez.',
        429 => 'Trop de tentatives. Réessayez dans un instant.',
        500 => 'Une erreur interne est survenue.',
        503 => 'Le service est momentanément indisponible.',
    ];

    public static function for(int $status, string $message): string
    {
        if (isset(self::KNOWN[$message])) {
            return self::KNOWN[$message];
        }

        // Messages techniques (vides, anglais génériques, « No query results for model … ») : libellé par statut.
        if ($message === '' || str_starts_with($message, 'No query results') || $status === 404 || $status >= 500) {
            return self::BY_STATUS[$status] ?? $message;
        }

        return $message;
    }
}

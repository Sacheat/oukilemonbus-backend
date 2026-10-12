<?php

/**
 * Limites de requêtes par minute (limiteurs nommés, AppServiceProvider::configureRateLimiting).
 *
 * Chaque famille a son propre compteur, par utilisateur (ou par adresse IP sans session) et par route :
 * consulter des pages n'use plus la limite d'un preview ou d'un envoi d'email.
 * `consultation` est partagé par toutes les lectures limitées (une page de gestion en fait une dizaine à une trentaine).
 */
return [
    // Lectures : planning, séries, tableau de bord, liste, détail et compteurs des réservations
    'consultation' => (int) env('RATE_LIMIT_READ', 300),

    // Calculs coûteux : preview d'une réservation ou d'une série, export du planning
    'action' => (int) env('RATE_LIMIT_ACTION', 20),

    // Vérifications à la frappe et petits appels : check-trip, suggestions d'adresse, santé, déconnexion, lien signé
    'verification' => (int) env('RATE_LIMIT_VERIFICATION', 30),

    // Envois d'emails et liens sensibles : mot de passe oublié, réinitialisation, vérification d'email, lien admin
    'envoi' => (int) env('RATE_LIMIT_MAIL', 6),

    'connexion' => (int) env('RATE_LIMIT_LOGIN', 20),
    'inscription' => (int) env('RATE_LIMIT_REGISTER', 10),

    // Bouton « Aide » du chauffeur
    'aide' => (int) env('RATE_LIMIT_DRIVER_ALERT', 10),
];

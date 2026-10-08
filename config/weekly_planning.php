<?php

/**
 * Planning hebdomadaire envoyé automatiquement (cahier des charges §5.9) : le planning de la semaine suivante part
 * chaque semaine aux agents et aux admins (ou au responsable du planning de la commune), puis une version corrigée
 * part si un trajet change. Règles : WeeklyPlanningService, doc docs/weekly_planning.md.
 *
 * Chaque commune peut régler l'envoi dans la page Communes (admins) : colonnes municipalities.weekly_planning_* ;
 * null = valeur par défaut ci-dessous.
 */
return [
    // Envoi automatique activé par défaut (une commune qui n'en veut pas le coupe)
    'enabled' => (bool) env('WEEKLY_PLANNING_ENABLED', true),

    // Jour de l'envoi : 1 = lundi … 7 = dimanche (ISO), et heure de Bruxelles (HH:MM)
    'send_day' => (int) env('WEEKLY_PLANNING_SEND_DAY', 5),
    'send_time' => env('WEEKLY_PLANNING_SEND_TIME', '12:00'),

    // Version corrigée : au plus une par cet intervalle, et seulement si un trajet a changé
    'update_min_interval_minutes' => (int) env('WEEKLY_PLANNING_UPDATE_INTERVAL_MINUTES', 60),

    // Trajets du fichier : ceux qui ont lieu (les demandes en attente sont seulement comptées dans le message)
    'statuses' => ['approuve', 'confirme'],
];

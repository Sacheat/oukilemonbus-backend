<?php

return [
    // Valeurs par défaut : la commune active peut les surcharger (max_trip_distance_km,
    // max_pickup_distance_km) via PATCH /municipalities/{id}.
    // Distance maximale du trajet, lieu de départ → destination (itinéraire ORS).
    'max_distance_km' => 50,
    // Distance maximale, à vol d'oiseau, entre le dépôt du bus (ou le centre de la commune) et le lieu de départ.
    'max_pickup_distance_km' => 50,
    'duration_margin_min' => 15,
    // Fuseau des heures « murales » (réservations, indisponibilités) : les valeurs reçues avec
    // un décalage explicite y sont converties avant stockage.
    'local_timezone' => 'Europe/Brussels',
    'pickup_buffer_min' => 0,
    'dropoff_buffer_min' => 10,
    'safety_buffer_min' => 5,
    'non_school_min_departure_time' => '07:30',
    'non_school_max_return_time' => '16:00',
    'non_school_max_total_duration_min' => 300,
    'min_notice_days' => 15,
    // Fenêtre (en jours, à partir d'aujourd'hui) dans laquelle un trajet actif sans passagers
    // confirmés est signalé à traiter (tableau de bord de gestion).
    'passenger_confirmation_window_days' => 14,
    'allowed_countries' => ['BE', 'Belgique', 'Belgium'],
    'annual_quota' => 5,
    'max_pending_requests' => 2,
    'max_confirmed_reservations' => 2,

    // Affectations simultanées sur un même jour : attente du verrou (secondes) avant de répondre 409
    'assignment_lock_wait_seconds' => (int) env('RESERVATIONS_ASSIGNMENT_LOCK_WAIT_SECONDS', 5),
    // Délai minimum d'annulation pour le demandeur (en heures avant le départ).
    'cancellation_requester_min_hours_before' => 24,

    // Délai minimum d'annulation pour la commune (en jours entiers avant la date du trajet).
    // 1 = doit annuler au plus tard la veille (pas le jour J).
    'cancellation_commune_min_days_before' => 1,

    // Types d'organisation exemptés du quota annuel et de la limite de demandes actives.
    'quota_exempt_types' => ['ecole_communale', 'personnel_communal'],

    // Types auto-approuvés à la création (pas de décision du Collège requise).
    'auto_approve_types' => ['ecole_communale', 'personnel_communal'],

    // Types exemptés des restrictions horaires et de durée.
    'time_restriction_exempt_types' => ['ecole_communale', 'personnel_communal'],

    // Types pour lesquels aucune décision du Collège n'est exigée.
    'council_decision_exempt_types' => ['ecole_communale', 'personnel_communal'],

    // Types autorisés à créer des récurrences.
    'recurrence_allowed_types' => ['ecole_communale', 'personnel_communal'],

    // Types considérés prioritaires (niveau 2 dans le calcul de priorité).
    'high_priority_types' => ['ecole_communale', 'personnel_communal'],

    // Les 10 jours fériés légaux belges sont calculés pour toute année (App\Services\Calendar\ClosedDaysService) ;
    // les fermetures propres à la commune se règlent dans Communes (municipality_calendar_periods).
    // false seulement dans des tests qui vérifient autre chose que le calendrier.
    'public_holidays' => true,

    // Ancien réglage : dates fermées en plus, toujours lues (vide par défaut, les fériés sont calculés).
    'closed_dates' => [],
];

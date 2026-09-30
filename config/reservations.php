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
    'allowed_countries' => ['BE', 'Belgique', 'Belgium'],
    'annual_quota' => 5,
    'max_pending_requests' => 2,
    'max_confirmed_reservations' => 2,
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

    'closed_dates' => [
        '2026-01-01',
        '2026-04-06',
        '2026-05-01',
        '2026-05-14',
        '2026-05-25',
        '2026-07-21',
        '2026-08-15',
        '2026-11-01',
        '2026-11-11',
        '2026-12-25',
    ],
];

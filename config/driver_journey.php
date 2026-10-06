<?php

/**
 * Journée du chauffeur (« Ma journée ») : valeurs par défaut et catalogue des applications de navigation.
 *
 * Chaque commune choisit son moteur et son délai du premier départ dans la page Communes (admins) : colonnes
 * municipalities.navigation_engine et driver_departure_window_min ; null = valeur par défaut ci-dessous
 * (DriverJourneySettingsService). Ajouter un moteur = ajouter une entrée dans `navigation_engines`.
 *
 * Gabarits des liens (remplis par le front) : {lat}, {lng} = destination ; {label} = nom du lieu (encodé) ;
 * {from_lat}, {from_lng} = point de départ connu (étape « départ → destination »).
 *   android      : lien sur téléphone Android (geo: ouvre l'application de navigation installée, utilisable hors ligne
 *                  si la région est téléchargée) ; null = lien web
 *   web          : itinéraire depuis la position du téléphone (destination seule)
 *   web_from     : itinéraire d'un point connu à la destination
 */
return [
    // Le premier « Départ » d'un trajet est possible à partir de N minutes avant l'heure prévue (jusqu'à la fin du jour)
    'departure_window_minutes' => (int) env('DRIVER_DEPARTURE_WINDOW_MINUTES', 60),

    // Une étape enregistrée à plus de N mètres du lieu prévu est signalée aux agents (Planning), sans rien bloquer
    'step_distance_warning_meters' => (int) env('DRIVER_STEP_DISTANCE_WARNING_METERS', 500),

    // Demande d'aide toujours ouverte après N minutes : une relance aux agents, admins et contact de garde
    'alert_reminder_minutes' => (int) env('DRIVER_ALERT_REMINDER_MINUTES', 10),

    'default_navigation_engine' => env('DRIVER_NAVIGATION_ENGINE', 'openstreetmap'),

    'navigation_engines' => [
        'openstreetmap' => [
            'label' => 'OpenStreetMap',
            'android' => 'geo:{lat},{lng}?q={lat},{lng}({label})',
            'web' => 'https://www.openstreetmap.org/?mlat={lat}&mlon={lng}#map=17/{lat}/{lng}',
            'web_from' => 'https://www.openstreetmap.org/directions?engine=fossgis_osrm_car&route={from_lat}%2C{from_lng}%3B{lat}%2C{lng}',
        ],
        'google' => [
            'label' => 'Google Maps',
            'android' => null,
            'web' => 'https://www.google.com/maps/dir/?api=1&destination={lat}%2C{lng}&travelmode=driving',
            'web_from' => 'https://www.google.com/maps/dir/?api=1&origin={from_lat}%2C{from_lng}&destination={lat}%2C{lng}&travelmode=driving',
        ],
    ],
];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dev Toolbox (module Tooling)
    |--------------------------------------------------------------------------
    |
    | - Commande interactive `php artisan dev:toolbox` : maintenance, debug,
    |   backups, reset de la base. Bloquée hors des environnements `env_guard`
    |   et tant que DEV_TOOLBOX_ENABLED n'est pas à true.
    | - API lecture seule /api/admin/tooling/toolbox/* (permission tooling.access),
    |   disponible dans tous les environnements : elle n'exécute aucune action.
    |
    */

    'enabled'   => env('DEV_TOOLBOX_ENABLED', false),
    'env_guard' => ['local', 'testing'],

    /*
    |--------------------------------------------------------------------------
    | Section Défense — Règles métier
    |--------------------------------------------------------------------------
    | config_key : clé du fichier de config Laravel qui contient les règles.
    | labels     : correspondance clé → libellé lisible + unité.
    */

    'business_rules' => [
        'config_key' => 'reservations',
        'labels'     => [
            'max_distance_km'                         => ['label' => 'Distance max',                   'unit' => 'km'],
            'min_notice_days'                         => ['label' => 'Délai minimum de réservation',    'unit' => 'jours'],
            'annual_quota'                            => ['label' => 'Quota annuel / organisation',     'unit' => 'réservations'],
            'max_pending_requests'                    => ['label' => 'Demandes en attente max',         'unit' => ''],
            'max_confirmed_reservations'              => ['label' => 'Réservations confirmées max',     'unit' => ''],
            'cancellation_requester_min_hours_before' => ['label' => "Délai d'annulation (demandeur)",  'unit' => 'h'],
            'cancellation_commune_min_days_before'    => ['label' => "Délai d'annulation (commune)",    'unit' => 'jour(s)'],
            'non_school_min_departure_time'           => ['label' => 'Heure départ min (hors école)',   'unit' => ''],
            'non_school_max_return_time'              => ['label' => 'Heure retour max (hors école)',   'unit' => ''],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Section Défense — Statistiques base de données
    |--------------------------------------------------------------------------
    | Chaque entrée produit une ligne dans le tableau de stats.
    | 'where' : filtres optionnels (colonne => valeur, null = whereNull).
    */

    'db_stats' => [
        ['label' => 'Utilisateurs',     'table' => 'users'],
        ['label' => 'Organisations',    'table' => 'organizations'],
        ['label' => 'Réservations',     'table' => 'reservations'],
        ['label' => 'Véhicules',        'table' => 'vehicles'],
        ['label' => 'Chauffeurs',       'table' => 'drivers'],
        ['label' => 'Locations',        'table' => 'locations'],
        ['label' => 'Conflits actifs',  'table' => 'reservation_conflicts', 'where' => ['resolved_at' => null]],
        ['label' => 'Jobs en attente',  'table' => 'jobs'],
        ['label' => 'Jobs échoués',     'table' => 'failed_jobs'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Section Défense — Architecture du projet
    |--------------------------------------------------------------------------
    | Chaque entrée compte les fichiers .php dans le répertoire indiqué
    | (chemin relatif depuis base_path()).
    */

    'architecture' => [
        'paths' => [
            'Controllers API'   => 'app/Http/Controllers/Api',
            'Services métier'   => 'app/Services',
            'Models Eloquent'   => 'app/Models',
            'Commandes custom'  => 'app/Console/Commands',
            'Tests Feature'     => 'tests/Feature',
            'Tests Unit'        => 'tests/Unit',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Section Défense — Catalogue des APIs externes
    |--------------------------------------------------------------------------
    */

    'api_catalog' => [
        'external' => [
            [
                'name'            => 'OpenRouteService — Géocodage',
                'env_key'         => 'ORS_API_KEY',
                'url'             => 'https://api.openrouteservice.org/geocode/search',
                'used_for'        => "Convertir une adresse texte en coordonnées GPS (longitude, latitude)",
                'request'         => [
                    'api_key'          => '(ORS_API_KEY)',
                    'text'             => '"Rue de la Station 12, Dinant"',
                    'boundary.country' => '"BE"',
                    'size'             => 1,
                ],
                'response_fields' => [
                    'features[0].geometry.coordinates' => '[longitude, latitude]',
                    'features[0].properties.label'     => 'Adresse normalisée retournée par ORS',
                ],
                'fallback'        => "Formule Haversine : distance vol d'oiseau × 1.25 (si ORS indisponible)",
            ],
            [
                'name'            => 'OpenRouteService — Calcul d\'itinéraire',
                'env_key'         => 'ORS_API_KEY',
                'url'             => 'https://api.openrouteservice.org/v2/directions/driving-car',
                'used_for'        => 'Calculer la distance et durée réelles par la route entre deux points GPS',
                'request'         => [
                    'Authorization' => '(ORS_API_KEY)',
                    'coordinates'   => '[[lon_départ, lat_départ], [lon_arrivée, lat_arrivée]]',
                ],
                'response_fields' => [
                    'routes[0].summary.distance' => 'Distance en mètres  →  ÷ 1000 pour obtenir les km',
                    'routes[0].summary.duration' => 'Durée en secondes   →  ÷ 60 pour obtenir les minutes',
                ],
                'fallback'        => "Formule Haversine : distance vol d'oiseau × 1.25 (si ORS indisponible)",
            ],
            [
                'name'            => 'GeoNames — Autocomplétion de localités',
                'env_key'         => 'GEONAMES_USERNAME',
                'url'             => 'https://secure.geonames.org/searchJSON',
                'used_for'        => 'Autocomplétion des noms de communes et localités belges lors de la saisie',
                'request'         => [
                    'name_equals' => '"Dinant"',
                    'country'     => '"BE"',
                    'maxRows'     => 10,
                    'username'    => '(GEONAMES_USERNAME)',
                ],
                'response_fields' => [
                    'geonames[].name'        => 'Nom de la localité',
                    'geonames[].adminName1'  => 'Province',
                    'geonames[].lat / .lng'  => 'Coordonnées GPS',
                    'totalResultsCount'      => 'Nombre total de résultats',
                ],
                'fallback'        => null,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Section Nos Commandes Custom
    |--------------------------------------------------------------------------
    | 'command' : signature Artisan sans le préfixe "php artisan".
    | 'options' : tableau passé directement à Artisan::call().
    | Les commandes sont numérotées automatiquement à partir de [7].
    */

    'custom_commands' => [
        [
            'label'   => 'app:health — Healthcheck système complet',
            'command' => 'app:health',
        ],
        [
            'label'   => 'notifications:health — Santé du module notifications',
            'command' => 'notifications:health',
        ],
        [
            'label'   => 'notifications:send-digests — Envoyer les digests en attente',
            'command' => 'notifications:send-digests',
        ],
        [
            'label'   => 'notifications:prune — Purger les notifications expirées',
            'command' => 'notifications:prune',
        ],
        [
            'label'   => 'reservations:send-passenger-reminders — Rappels passagers (dry-run)',
            'command' => 'reservations:send-passenger-reminders',
            'options' => ['--dry-run' => true],
        ],
        [
            'label'   => 'route-sheets:generate-daily — Générer les feuilles de route',
            'command' => 'route-sheets:generate-daily',
            'interactive' => true,
        ],
        [
            'label'   => 'documents:prune-orphan-proofs — Justificatifs orphelins (dry-run)',
            'command' => 'documents:prune-orphan-proofs',
            'options' => ['--dry-run' => true],
        ],
        [
            'label'   => 'backup:run — Backup complet (DB + fichiers)',
            'command' => 'backup:run',
            'options' => ['--only' => 'full'],
        ],
        [
            'label'   => 'backup:run — Backup base de données uniquement',
            'command' => 'backup:run',
            'options' => ['--only' => 'db'],
        ],
        [
            'label'   => 'backup:run — Backup fichiers uniquement',
            'command' => 'backup:run',
            'options' => ['--only' => 'files'],
        ],
        [
            'label'   => 'backup:run — Backup complet avec logs applicatifs',
            'command' => 'backup:run',
            'options' => ['--include-logs' => true],
        ],
        [
            'label'   => 'backup:list — Lister les backups disponibles',
            'command' => 'backup:list',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Section Maintenance — Réparation des permissions
    |--------------------------------------------------------------------------
    */

    'repair_permissions' => [
        'seeder' => 'RolesAndPermissionsSeeder',
    ],

    /*
    |--------------------------------------------------------------------------
    | Section Maintenance — Utilisateurs de démo
    |--------------------------------------------------------------------------
    | email_pattern : fragment présent dans les emails des comptes fictifs.
    */

    'demo' => [
        'email_pattern' => '.test@bus-communal.local',
    ],

    /*
    |--------------------------------------------------------------------------
    | Section Base de données — Reset complet démo
    |--------------------------------------------------------------------------
    | confirmation_word : mot que l'utilisateur doit taper pour confirmer.
    | seeders           : liste des seeders exécutés après migrate:fresh.
    */

    'reset_demo' => [
        'confirmation_word' => 'RESET',
        'seeders'           => [
            'RolesAndPermissionsSeeder',
            'TestMunicipalitySeeder',
            'TestUsersSeeder',
            'TestFleetSeeder',
            'TestOrganizationsSeeder',
            'TestLocationsSeeder',
            'TestRequestsSeeder',
            'TestReservationsSeeder',
            'TestUnavailabilitiesSeeder',
            'TestScenariosSeeder',
            'RouteSheetDemoSeeder',
        ],
    ],

];

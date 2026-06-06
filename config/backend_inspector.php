<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backend Inspector
    |--------------------------------------------------------------------------
    |
    | Outil pédagogique réservé à la démo.
    | Il permet d'expliquer ce qui se passe côté backend lorsqu'une action
    | est exécutée dans les pages de démonstration.
    |
    */

    'enabled' => env('BACKEND_INSPECTOR_ENABLED', false),

    'session_keys' => [
        'enabled' => 'backend_inspector.enabled',
        'current_scenario' => 'backend_inspector.current_scenario',
        'last_action' => 'backend_inspector.last_action',
        'last_context' => 'backend_inspector.last_context',
        'last_recorded_at' => 'backend_inspector.last_recorded_at',
        'current_actor_id' => 'backend_inspector.current_actor_id',
        'history'          => 'backend_inspector.history',
    ],

    /*
    |--------------------------------------------------------------------------
    | Route map — API route name → inspector flow key
    |--------------------------------------------------------------------------
    |
    | Utilisé par BackendInspectorMiddleware pour identifier le flow à enregistrer
    | lorsqu’une route API de la démo v2 est appelée avec succès.
    |
    */

    'route_map' => [

        // Auth
        'api.auth.register' => 'auth.register',
        'api.auth.login'    => 'auth.login',
        'api.auth.logout'   => 'auth.logout',

        // Reservations
        'api.reservations.store'              => 'reservations.store',
        'api.reservations.update'             => 'reservations.update',
        'api.reservations.approve'            => 'reservations.approve',
        'api.reservations.reject'             => 'reservations.reject',
        'api.reservations.cancel'             => 'reservations.cancel',
        'api.reservations.confirm'            => 'reservations.confirm',
        'api.reservations.assign'             => 'reservations.assign',
        'api.reservations.retry-assign'       => 'reservations.retry_assign',
        'api.reservations.confirm-passengers' => 'reservations.confirm_passengers',
        'api.reservations.conflicts.resolve'  => 'reservations.conflicts.resolve',

        // Recurrences
        'api.recurrences.store' => 'recurrences.store',

        // Council decisions
        'api.council-decisions.store'  => 'reservations.council_decision',
        'api.council-decisions.update' => 'reservations.council_decision',

        // Org registration requests
        'api.org-registration-requests.store'        => 'requests.organizations.store',
        'api.org-registration-requests.approve'      => 'requests.organizations.approve',
        'api.org-registration-requests.reject'       => 'requests.organizations.reject',
        'api.org-registration-requests.proof.upload' => 'requests.organizations.proof_upload',
        'api.org-registration-requests.proof.delete' => 'requests.organizations.proof_delete',

        // User org requests
        'api.user-org-requests.store'          => 'requests.user_organizations.store',
        'api.user-org-requests.approve'        => 'requests.user_organizations.approve',
        'api.user-org-requests.reject'         => 'requests.user_organizations.reject',
        'api.user-org-requests.proof.upload'   => 'requests.user_organizations.proof_upload',
        'api.user-org-requests.proof.delete'   => 'requests.user_organizations.proof_delete',

        // Notifications
        'api.notifications.mark-read'          => 'notifications.read',
        'api.notifications.mark-all-read'      => 'notifications.read_all',
        'api.notifications.preferences.update' => 'notifications.preferences.update',

        // Documents
        'api.documents.route-sheets.generate' => 'documents.route_sheets.generate',

        // Locations
        'api.locations.store' => 'locations.store',

        // Unavailabilities
        'api.unavailabilities.store'  => 'admin.unavailabilities.store',
        'api.unavailabilities.update' => 'admin.unavailabilities.update',
        'api.unavailabilities.cancel' => 'admin.unavailabilities.cancel',

        // Drivers
        'api.drivers.store'      => 'admin.drivers.store',
        'api.drivers.update'     => 'admin.drivers.update',
        'api.drivers.activate'   => 'admin.drivers.activate',
        'api.drivers.deactivate' => 'admin.drivers.deactivate',

        // Vehicles
        'api.vehicles.store'      => 'admin.vehicles.store',
        'api.vehicles.update'     => 'admin.vehicles.update',
        'api.vehicles.activate'   => 'admin.vehicles.activate',
        'api.vehicles.deactivate' => 'admin.vehicles.deactivate',

        // Users
        'api.users.update-status' => 'admin.users.update_status',
        'api.users.deactivate'    => 'admin.users.deactivate',

        // Previews
        'api.reservations.preview' => 'reservations.preview',
        'api.recurrences.preview'  => 'recurrences.preview',

        // Organisations
        'api.organizations.store' => 'organizations.store',
        'api.organizations.ban'   => 'organizations.ban',
        'api.organizations.unban' => 'organizations.unban',

        // Email verification
        'api.verification.send' => 'auth.email_resend',

    ],

    'scenarios' => [

        'user' => [
            'title' => 'Scénario User',
            'description' => 'Parcours d’un utilisateur classique : demandes d’organisation, rattachement et réservations.',
            'actor_type' => 'Utilisateur',
        ],

        'admin' => [
            'title' => 'Scénario Admin / Agent communal',
            'description' => 'Parcours de gestion : validation, réservation, planning, documents et notifications.',
            'actor_type' => 'Admin / Agent communal',
        ],

        'driver' => [
            'title' => 'Scénario Chauffeur',
            'description' => 'Parcours chauffeur : planning, missions assignées, feuilles de route et notifications.',
            'actor_type' => 'Chauffeur',
        ],

    ],

    'flows' => [

        'demo.actor.changed' => [
            'title' => 'Changement d’acteur dans la démo',
            'category' => 'Démo',
            'scenario' => null,
            'goal' => 'Changer l’utilisateur actif utilisé par les pages de démonstration.',

            'route' => [
                'demo' => 'POST /demo/actor',
                'api' => null,
            ],

            'demo_controller' => [
                'class' => 'DemoActorController',
                'method' => 'update',
            ],

            'api_controller' => null,

            'request' => null,

            'service' => [
                'class' => 'Session Laravel',
                'method' => 'session()',
                'internal_methods' => [
                    'Stockage de l’identifiant utilisateur sélectionné',
                    'Rechargement de l’acteur courant',
                    'Mise à jour du contexte de démonstration',
                ],
            ],

            'related_services' => [
                'UserInspectorService',
                'BackendInspectorService',
            ],

            'tables' => [
                'users',
                'roles',
                'permissions',
                'model_has_roles',
                'model_has_permissions',
            ],

            'effects' => [
                'L’acteur courant de la démo est modifié.',
                'Les rôles et permissions de cet utilisateur peuvent être affichés.',
                'Les actions suivantes seront expliquées selon cet acteur.',
            ],
        ],

        // _________________________________________________________________________________________PLANNING
        'planning.index' => [
            'title' => 'Consultation du planning',
            'category' => 'Planning',
            'scenario' => 'admin',
            'goal' => 'Afficher les réservations planifiées sur une période donnée.',

            'route' => [
                'demo' => 'GET /demo/planning',
                'api' => 'GET /api/planning',
            ],

            'demo_controller' => [
                'class' => 'DemoPlanningController',
                'method' => '__invoke',
            ],

            'api_controller' => [
                'class' => 'PlanningController',
                'method' => 'index',
            ],

            'request' => [
                'class' => 'PlanningIndexRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'PlanningService',
                'method' => 'forPeriod',
                'internal_methods' => [
                    'Filtrage de la période',
                    'Récupération des réservations',
                    'Calcul des occupations',
                    'Préparation des données d’affichage',
                ],
            ],

            'related_services' => [
                'ReservationOccupationService',
                'PlanningAvailabilityService',
            ],

            'tables' => [
                'reservations',
                'drivers',
                'vehicles',
                'locations',
                'organizations',
                'reservation_conflicts',
                'unavailabilities',
            ],

            'effects' => [
                'Les réservations de la période sont chargées.',
                'Les affectations chauffeur/véhicule sont prises en compte.',
                'Les disponibilités peuvent être calculées.',
                'Le planning est préparé pour l’affichage.',
            ],
        ],

        // _________________________________________________________________________________________DASHBOARD
        'demo.dashboard.viewed' => [
            'title' => 'Consultation du tableau de bord',
            'category' => 'Démo',
            'scenario' => null,
            'goal' => 'Afficher une vue d’ensemble des données disponibles dans la démo.',

            'route' => [
                'demo' => 'GET /demo',
                'api' => null,
            ],
            'demo_controller' => [
                'class' => 'DemoDashboardController',
                'method' => '__invoke',
            ],

            'api_controller' => null,
            'request' => null,

            'service' => [
                'class' => 'DemoDashboardController',
                'method' => '__invoke',
                'internal_methods' => [
                    'actor',
                    'shared',
                    'NotificationHealthService::summary',
                ],
            ],

            'related_services' => [
                'NotificationHealthService',
            ],

            'tables' => [
                'users',
                'organizations',
                'reservations',
                'documents',
                'notifications',
                'notification_deliveries',
            ],

            'effects' => [
                'Les compteurs principaux sont chargés.',
                'Les dernières réservations sont récupérées.',
                'Les notifications de l’acteur courant sont affichées.',
                'Un résumé de santé des notifications est calculé.',
            ],
        ],

        // _________________________________________________________________________________________RÉSERVATIONS
        // INDEX
        'reservations.index' => [
            'title' => 'Consultation des réservations',
            'category' => 'Réservations',
            'scenario' => 'admin',
            'goal' => 'Afficher les réservations disponibles dans la démo avec leurs affectations et conflits.',

            'route' => [
                'demo' => 'GET /demo/reservations',
                'api' => 'GET /api/reservations',
            ],

            'demo_controller' => [
                'class' => 'DemoReservationController',
                'method' => 'index',
            ],

            'api_controller' => [
                'class' => 'ReservationController',
                'method' => 'index',
            ],

            'request' => null,

            'service' => [
                'class' => 'Reservation',
                'method' => 'query',
                'internal_methods' => [
                    'with organization/requestedByUser/locations/driver/vehicle/conflicts',
                    'filtrage éventuel par statut',
                    'pagination',
                ],
            ],

            'related_services' => [
                'ReservationService',
                'ReservationAssignmentService',
            ],

            'tables' => [
                'reservations',
                'organizations',
                'users',
                'locations',
                'drivers',
                'vehicles',
                'reservation_conflicts',
            ],

            'effects' => [
                'La liste des réservations est chargée.',
                'Les relations utiles sont préchargées.',
                'Les chauffeurs et véhicules actifs sont chargés pour les formulaires d’affectation.',
                'Les conflits actifs peuvent être affichés.',
            ],
        ],

        // CREATE
        'reservations.create' => [
            'title' => 'Ouverture du formulaire de création',
            'category' => 'Réservations',
            'scenario' => 'admin',
            'goal' => 'Afficher le formulaire permettant de créer une nouvelle réservation.',

            'route' => [
                'demo' => 'GET /demo/reservations/create',
                'api' => 'Non applicable : l’API ne fournit généralement pas de formulaire HTML.',
            ],

            'demo_controller' => [
                'class' => 'DemoReservationController',
                'method' => 'create',
            ],

            'api_controller' => null,

            'request' => null,

            'service' => [
                'class' => 'DemoReservationController',
                'method' => 'create',
                'internal_methods' => [
                    'Récupération de l’acteur courant',
                    'Vérification du droit de création',
                    'Chargement des organisations visibles',
                    'Chargement des lieux disponibles',
                    'Chargement des types d’activité',
                    'Affichage du formulaire de création',
                ],
            ],

            'related_services' => [
                'DemoActorResolver',
                'DemoDataService',
            ],

            'tables' => [
                'users',
                'organizations',
                'locations',
            ],

            'effects' => [
                'Le formulaire de création est affiché.',
                'Aucune réservation n’est encore créée.',
                'La création réelle se fait uniquement au clic sur Enregistrer.',
                'L’action suivante attendue est reservations.store.',
            ],
        ],

        // STORE
        'reservations.store' => [
            'title' => 'Création d’une réservation',
            'category' => 'Réservations',
            'scenario' => 'admin',
            'goal' => 'Créer une nouvelle réservation à partir des données du formulaire.',

            'route' => [
                'demo' => 'POST /demo/reservations',
                'api' => 'POST /api/reservations',
            ],

            'demo_controller' => [
                'class' => 'DemoReservationController',
                'method' => 'store',
            ],

            'api_controller' => [
                'class' => 'ReservationController',
                'method' => 'store',
            ],

            'request' => [
                'organization_id',
                'pickup_location_id',
                'destination_location_id',
                'trip_date',
                'departure_time',
                'return_time',
                'nb_children',
                'nb_adults',
                'trip_purpose',
                'activity_type',
            ],

            'service' => [
                'class' => 'ReservationService',
                'method' => 'create',
                'internal_methods' => [
                    'Validation du payload de réservation',
                    'Chargement de l’organisation demandée',
                    'Vérification du droit de création pour cette organisation',
                    'Préparation des données métier',
                    'Calcul du preview de trajet',
                    'Détection éventuelle de conflits',
                    'Création de la réservation',
                    'Création éventuelle des conflits associés',
                    'Notification éventuelle des acteurs concernés',
                    'Redirection vers la liste des réservations',
                ],
            ],

            'related_services' => [
                'ReservationService',
                'ReservationPreviewService',
                'NotificationDispatchService::dispatch',
                'DomainEventLogger::log',
            ],

            'tables' => [
                'reservations',
                'organizations',
                'locations',
                'reservation_conflicts',
                'notifications',
                'notification_deliveries',
                'domain_events',
            ],

            'effects' => [
                'Une nouvelle réservation est créée.',
                'Les lieux, horaires, participants et motif de trajet sont enregistrés.',
                'Des conflits peuvent être détectés selon les disponibilités.',
                'La réservation apparaît ensuite dans la liste des réservations.',
                'L’inspector conserve l’action POST malgré la redirection.',
            ],
        ],

        // EDIT
        'reservations.edit' => [
            'title' => 'Ouverture du formulaire de modification',
            'category' => 'Réservations',
            'scenario' => 'admin',
            'goal' => 'Afficher le formulaire permettant de modifier une réservation existante.',

            'route' => [
                'demo' => 'GET /demo/reservations/{reservation}/edit',
                'api' => 'Non applicable : l’API ne fournit généralement pas de formulaire HTML.',
            ],

            'demo_controller' => [
                'class' => 'DemoReservationController',
                'method' => 'edit',
            ],

            'api_controller' => null,

            'request' => null,

            'service' => [
                'class' => 'Reservation',
                'method' => 'load/query',
                'internal_methods' => [
                    'Récupération de l’acteur courant',
                    'Vérification du droit de modification',
                    'Chargement de la réservation',
                    'Chargement des données nécessaires au formulaire',
                    'Affichage de la vue de modification',
                ],
            ],

            'related_services' => [
                'DemoActorResolver',
                'DemoDataService',
            ],

            'tables' => [
                'reservations',
                'organizations',
                'locations',
                'drivers',
                'vehicles',
            ],

            'effects' => [
                'Le formulaire de modification est affiché.',
                'Aucune donnée métier n’est encore modifiée.',
                'La modification réelle se fait uniquement au clic sur Enregistrer.',
                'L’action suivante attendue est reservations.update.',
            ],
        ],

        // UPDATE
        'reservations.update' => [
            'title' => 'Modification d’une réservation',
            'category' => 'Réservations',
            'scenario' => 'admin',
            'goal' => 'Mettre à jour les informations principales d’une réservation existante.',

            'route' => [
                'demo' => 'PATCH /demo/reservations/{reservation}',
                'api' => 'PATCH /api/reservations/{reservation}',
            ],

            'demo_controller' => [
                'class' => 'DemoReservationController',
                'method' => 'update',
            ],

            'api_controller' => [
                'class' => 'ReservationController',
                'method' => 'update',
            ],

            'request' => [
                'pickup_location_id',
                'destination_location_id',
                'trip_date',
                'departure_time',
                'return_time',
                'nb_children',
                'nb_adults',
                'trip_purpose',
                'activity_type',
            ],

            'service' => [
                'class' => 'ReservationService',
                'method' => 'update',
                'internal_methods' => [
                    'Validation des champs envoyés',
                    'Vérification des droits de modification',
                    'Préparation du nouveau payload de réservation',
                    'Recalcul du preview de trajet',
                    'Détection éventuelle de conflits',
                    'Mise à jour de la réservation',
                    'Mise à jour éventuelle du statut',
                    'Réinitialisation et recréation éventuelle des conflits',
                    'Redirection vers la liste des réservations',
                ],
            ],

            'related_services' => [
                'ReservationService',
                'ReservationPreviewService',
                'ReservationConflictService',
                'DomainEventLogger::log',
            ],

            'tables' => [
                'reservations',
                'locations',
                'reservation_conflicts',
                'reservation_status_history',
                'domain_events',
            ],

            'effects' => [
                'Les informations de la réservation sont mises à jour.',
                'Le trajet, les horaires et les participants peuvent être modifiés.',
                'Les conflits sont recalculés selon les nouvelles données.',
                'Le statut peut évoluer si de nouveaux conflits apparaissent.',
                'L’inspector conserve l’action PATCH malgré la redirection.',
            ],
        ],

        // APPROVE
        'reservations.approve' => [
            'title' => 'Approbation d’une réservation',
            'category' => 'Réservations',
            'scenario' => 'admin',
            'goal' => 'Faire passer une réservation vers le statut approuvé.',

            'route' => [
                'demo' => 'POST /demo/reservations/{reservation}/approve',
                'api' => 'PATCH /api/reservations/{reservation}/approve',
            ],

            'demo_controller' => [
                'class' => 'DemoReservationController',
                'method' => 'approve',
            ],

            'api_controller' => [
                'class' => 'ReservationController',
                'method' => 'approve',
            ],

            'request' => [
                'class' => 'ApproveReservationRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'ReservationService',
                'method' => 'approve',
                'internal_methods' => [
                    'ensureCouncilDecisionAllowsApproval',
                    'transition',
                    'recordStatus',
                    'notifyRequester',
                ],
            ],

            'related_services' => [
                'ReservationAssignmentService::autoAssign',
                'NotificationDispatchService::dispatch',
                'DomainEventLogger::log',
            ],

            'tables' => [
                'reservations',
                'reservation_status_history',
                'reservation_assignment_history',
                'notifications',
                'notification_deliveries',
                'domain_events',
                'jobs',
            ],

            'effects' => [
                'Le statut de la réservation est modifié.',
                'Un historique de changement de statut est créé.',
                'Une affectation automatique peut être tentée.',
                'Une notification peut être envoyée au demandeur.',
                'Un événement métier peut être journalisé.',
            ],
        ],

        // AUTO-ASSIGN
        'reservations.auto_assign' => [
            'title' => 'Affectation automatique d’une réservation',
            'category' => 'Réservations',
            'scenario' => 'admin',
            'goal' => 'Tenter d’assigner automatiquement un chauffeur et un véhicule disponibles à une réservation, en évitant les conflits d’occupation.',

            'route' => [
                'demo' => 'POST /demo/reservations/{reservation}/auto-assign',
                'api' => null,
            ],

            'demo_controller' => [
                'class' => 'DemoReservationController',
                'method' => 'autoAssign',
            ],

            'api_controller' => null,

            'request' => null,

            'service' => [
                'class' => 'ReservationAssignmentService',
                'method' => 'autoAssign',
                'internal_methods' => [
                    'ReservationOccupationService::windowForReservation',
                    'availableDriver($reservation, $window)',
                    'busyDriverIds($reservation, $window)',
                    'availableVehicle($reservation, $window)',
                    'busyVehicleIds($reservation, $window)',
                    'overlappingReservationsQuery($reservation, $window)',
                    'whereNotIn sur les chauffeurs/véhicules déjà occupés',
                    'Création de conflits si aucun chauffeur ou véhicule disponible',
                    'Mise à jour de l’affectation si une combinaison est trouvée',
                ],
            ],

            'related_services' => [
                'ReservationOccupationService',
                'ReservationAssignmentService',
                'NotificationDispatchService::dispatch',
                'DomainEventLogger::log',
            ],

            'tables' => [
                'reservations',
                'drivers',
                'vehicles',
                'unavailabilities',
                'reservation_conflicts',
                'reservation_assignment_history',
                'notification_deliveries',
                'notifications',
                'domain_events',
            ],

            'effects' => [
                'Cette action n’est pas exposée directement en API : elle déclenche une méthode métier interne du service de réservation.',
                'Le backend calcule la fenêtre réelle d’occupation de la réservation.',
                'Le backend récupère en SQL les chauffeurs indisponibles ou déjà occupés.',
                'Le backend récupère en SQL les véhicules indisponibles ou déjà occupés.',
                'Les chauffeurs et véhicules occupés sont exclus avec whereNotIn.',
                'La recherche évite de charger toute la flotte en mémoire.',
                'La réservation peut recevoir automatiquement un chauffeur et un véhicule disponibles.',
                'Des conflits peuvent être créés si aucune ressource n’est disponible.',
                'L’inspector conserve l’action POST malgré la redirection.',
            ],
        ],

        // ASSIGN
        'reservations.assign' => [
            'title' => 'Affectation manuelle d’une réservation',
            'category' => 'Réservations',
            'scenario' => 'admin',
            'goal' => 'Assigner manuellement un chauffeur et/ou un véhicule à une réservation.',

            'route' => [
                'demo' => 'POST /demo/reservations/{reservation}/assign',
                'api' => 'PATCH /api/reservations/{reservation}/assign',
            ],

            'demo_controller' => [
                'class' => 'DemoReservationController',
                'method' => 'assign',
            ],

            'api_controller' => [
                'class' => 'ReservationController',
                'method' => 'assign',
            ],

            'request' => [
                'class' => 'AssignReservationRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'ReservationAssignmentService',
                'method' => 'assign',
                'internal_methods' => [
                    'Validation du chauffeur demandé',
                    'Validation du véhicule demandé',
                    'Vérification des disponibilités',
                    'Mise à jour de la réservation',
                    'Création de l’historique d’affectation',
                ],
            ],

            'related_services' => [
                'ReservationOccupationService',
                'NotificationDispatchService::dispatch',
                'DomainEventLogger::log',
            ],

            'tables' => [
                'reservations',
                'drivers',
                'vehicles',
                'reservation_assignment_history',
                'notifications',
                'notification_deliveries',
                'domain_events',
            ],

            'effects' => [
                'Le chauffeur sélectionné peut être associé à la réservation.',
                'Le véhicule sélectionné peut être associé à la réservation.',
                'Le système vérifie les conflits avant ou pendant l’affectation.',
                'Un historique d’affectation peut être créé.',
                'Une notification peut être déclenchée.',
            ],
        ],

        // DECISION COLLÈGE
        'reservations.council_decision' => [
            'title' => 'Encodage d’une décision du collège',
            'category' => 'Réservations',
            'scenario' => 'admin',
            'goal' => 'Encoder ou mettre à jour la décision du collège communal liée à une réservation.',

            'route' => [
                'demo' => 'POST /demo/reservations/{reservation}/council-decision',
                'api' => 'POST /api/council-decisions ou PATCH /api/council-decisions/{councilDecision}',
            ],

            'demo_controller' => [
                'class' => 'DemoReservationController',
                'method' => 'councilDecision',
            ],

            'api_controller' => [
                'class' => 'CouncilDecisionController',
                'method' => 'store / update',
            ],

            'request' => [
                'decision_status',
                'decision_date',
                'decision_reference',
                'notes',
            ],

            'service' => [
                'class' => 'CouncilDecisionService',
                'method' => 'create / update',
                'internal_methods' => [
                    'Validation de la décision envoyée',
                    'Chargement de la réservation et de sa décision existante',
                    'Vérification des droits de création ou modification',
                    'Création ou mise à jour de la décision du collège',
                    'Génération éventuelle de la délibération',
                    'Notification éventuelle du demandeur',
                    'Redirection vers la liste des réservations',
                ],
            ],

            'related_services' => [
                'DemoActorResolver',
                'CouncilDecisionService',
                'DeliberationDocumentService',
                'NotificationDispatchService',
                'NotificationRecipientResolver',
                'ReservationService',
            ],

            'tables' => [
                'reservations',
                'council_decisions',
                'documents',
                'notifications',
                'notification_deliveries',
                'domain_events',
            ],

            'effects' => [
                'Une décision du collège est créée ou mise à jour.',
                'La décision peut autoriser ou refuser la réservation concernée.',
                'Une délibération peut être générée si la décision est finale.',
                'Le demandeur peut être notifié lorsqu’un document de délibération est disponible.',
                'L’inspector conserve l’action POST malgré la redirection.',
            ],
        ],

        // CONFIRM
        'reservations.confirm' => [
            'title' => 'Confirmation d’une réservation',
            'category' => 'Réservations',
            'scenario' => 'admin',
            'goal' => 'Faire passer une réservation vers le statut confirmé.',

            'route' => [
                'demo' => 'POST /demo/reservations/{reservation}/confirm',
                'api' => 'PATCH /api/reservations/{reservation}/confirm',
            ],

            'demo_controller' => [
                'class' => 'DemoReservationController',
                'method' => 'confirm',
            ],

            'api_controller' => [
                'class' => 'ReservationController',
                'method' => 'confirm',
            ],

            'request' => [
                'class' => 'ConfirmReservationRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'ReservationService',
                'method' => 'confirm',
                'internal_methods' => [
                    'Vérification du statut actuel',
                    'Vérification des affectations nécessaires',
                    'transition',
                    'recordStatus',
                    'notifyRequester',
                ],
            ],

            'related_services' => [
                'NotificationDispatchService::dispatch',
                'DomainEventLogger::log',
            ],

            'tables' => [
                'reservations',
                'reservation_status_history',
                'notifications',
                'notification_deliveries',
                'domain_events',
                'jobs',
            ],

            'effects' => [
                'Le statut de la réservation passe à confirmé.',
                'Un historique de statut est créé.',
                'Le demandeur peut être notifié.',
                'Un événement métier peut être journalisé.',
            ],
        ],

        // REJECT
        'reservations.reject' => [
            'title' => 'Refus d’une réservation',
            'category' => 'Réservations',
            'scenario' => 'admin',
            'goal' => 'Refuser une réservation avec un motif explicite.',

            'route' => [
                'demo' => 'POST /demo/reservations/{reservation}/reject',
                'api' => 'PATCH /api/reservations/{reservation}/reject',
            ],

            'demo_controller' => [
                'class' => 'DemoReservationController',
                'method' => 'reject',
            ],

            'api_controller' => [
                'class' => 'ReservationController',
                'method' => 'reject',
            ],

            'request' => [
                'reason',
            ],

            'service' => [
                'class' => 'ReservationService',
                'method' => 'reject',
                'internal_methods' => [
                    'Validation du motif de refus',
                    'Vérification des droits de refus',
                    'Vérification de la transition de statut',
                    'Passage de la réservation au statut refusé',
                    'Enregistrement de l’historique de statut',
                    'Notification éventuelle du demandeur',
                    'Redirection vers la liste des réservations',
                ],
            ],

            'related_services' => [
                'ReservationService',
                'NotificationDispatchService::dispatch',
                'NotificationRecipientResolver',
                'DomainEventLogger::log',
            ],

            'tables' => [
                'reservations',
                'reservation_status_history',
                'notification_deliveries',
                'notifications',
                'domain_events',
            ],

            'effects' => [
                'La réservation est passée au statut refusé.',
                'Le motif du refus est conservé dans le flux métier.',
                'Un historique de changement de statut peut être enregistré.',
                'Le demandeur peut être notifié du refus.',
                'L’inspector conserve l’action POST malgré la redirection.',
            ],
        ],

        // CANCEL
        'reservations.cancel' => [
            'title' => 'Annulation d’une réservation',
            'category' => 'Réservations',
            'scenario' => 'admin',
            'goal' => 'Annuler une réservation depuis la démo.',

            'route' => [
                'demo' => 'POST /demo/reservations/{reservation}/cancel',
                'api' => 'PATCH /api/reservations/{reservation}/cancel',
            ],

            'demo_controller' => [
                'class' => 'DemoReservationController',
                'method' => 'cancel',
            ],

            'api_controller' => [
                'class' => 'ReservationController',
                'method' => 'cancel',
            ],

            'request' => [
                'class' => 'CancelReservationRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'ReservationService',
                'method' => 'cancel',
                'internal_methods' => [
                    'Vérification du statut actuel',
                    'transition',
                    'recordStatus',
                    'notifyRequester',
                ],
            ],

            'related_services' => [
                'NotificationDispatchService::dispatch',
                'DomainEventLogger::log',
            ],

            'tables' => [
                'reservations',
                'reservation_status_history',
                'notifications',
                'notification_deliveries',
                'domain_events',
                'jobs',
            ],

            'effects' => [
                'Le statut de la réservation passe à annulé.',
                'Un historique de statut est créé.',
                'Le demandeur peut être notifié.',
                'L’annulation peut apparaître dans le suivi des notifications.',
            ],
        ],

        // _________________________________________________________________________________________NOTIFICATIONS
        'notifications.index' => [
            'title' => 'Consultation des notifications',
            'category' => 'Notifications',
            'scenario' => 'admin',
            'goal' => 'Afficher les notifications in-app de l’acteur et la santé du système de notifications.',

            'route' => [
                'demo' => 'GET /demo/notifications',
                'api' => 'GET /api/notifications',
            ],

            'demo_controller' => [
                'class' => 'DemoNotificationController',
                'method' => 'index',
            ],

            'api_controller' => [
                'class' => 'NotificationController',
                'method' => 'index',
            ],

            'request' => null,

            'service' => [
                'class' => 'NotificationHealthService',
                'method' => 'summary',
                'internal_methods' => [
                    'chargement des notifications de l’acteur',
                    'chargement des dernières livraisons',
                    'calcul des indicateurs de santé',
                ],
            ],

            'related_services' => [
                'NotificationHealthService',
                'NotificationDispatchService',
            ],

            'tables' => [
                'notifications',
                'notification_deliveries',
                'notification_digest_queues',
                'jobs',
            ],

            'effects' => [
                'Les notifications de l’acteur sont affichées.',
                'Les traces de livraison sont listées.',
                'Les indicateurs de santé des notifications sont calculés.',
            ],
        ],

        'notifications.read_all' => [
            'title' => 'Marquage global des notifications comme lues',
            'category' => 'Notifications',
            'scenario' => 'admin',
            'goal' => 'Marquer toutes les notifications non lues de l’acteur courant comme lues.',

            'route' => [
                'demo' => 'POST /demo/notifications/read-all',
                'api' => 'PATCH /api/notifications/read-all',
            ],

            'demo_controller' => [
                'class' => 'DemoNotificationController',
                'method' => 'markAllAsRead',
            ],

            'api_controller' => [
                'class' => 'NotificationController',
                'method' => 'markAllAsRead',
            ],

            'request' => null,

            'service' => [
                'class' => 'DatabaseNotification',
                'method' => 'update',
                'internal_methods' => [
                    'Récupération de l’acteur courant',
                    'Comptage des notifications non lues',
                    'Mise à jour du champ read_at',
                    'Redirection vers la page notifications',
                ],
            ],

            'related_services' => [
                'DemoActorResolver',
                'NotificationHealthService',
            ],

            'tables' => [
                'notifications',
            ],

            'effects' => [
                'Toutes les notifications non lues de l’acteur courant sont marquées comme lues.',
                'Le champ read_at est rempli avec la date actuelle.',
                'La page notifications est rechargée après l’action.',
                'L’inspector conserve l’action POST malgré la redirection.',
            ],
        ],

        'notifications.read' => [
            'title' => 'Marquage d’une notification comme lue',
            'category' => 'Notifications',
            'scenario' => 'admin',
            'goal' => 'Marquer une notification précise de l’acteur courant comme lue.',

            'route' => [
                'demo' => 'POST /demo/notifications/{notificationId}/read',
                'api' => 'PATCH /api/notifications/{notificationId}/read',
            ],

            'demo_controller' => [
                'class' => 'DemoNotificationController',
                'method' => 'markAsRead',
            ],

            'api_controller' => [
                'class' => 'NotificationController',
                'method' => 'markAsRead',
            ],

            'request' => null,

            'service' => [
                'class' => 'DatabaseNotification',
                'method' => 'markAsRead',
                'internal_methods' => [
                    'Récupération de l’acteur courant',
                    'Recherche de la notification par son identifiant',
                    'Vérification que la notification appartient bien à l’acteur',
                    'Mise à jour du champ read_at',
                    'Redirection vers la page notifications',
                ],
            ],

            'related_services' => [
                'DemoActorResolver',
                'NotificationHealthService',
            ],

            'tables' => [
                'notifications',
            ],

            'effects' => [
                'La notification ciblée est marquée comme lue.',
                'Le champ read_at est rempli si la notification était encore non lue.',
                'Une notification appartenant à un autre utilisateur ne peut pas être modifiée.',
                'L’inspector conserve l’action POST malgré la redirection.',
            ],
        ],

        // _________________________________________________________________________________________DEMANDES (REQUESTS)
        'requests.index' => [
            'title' => 'Consultation des demandes administratives',
            'category' => 'Demandes',
            'scenario' => 'admin',
            'goal' => 'Afficher les demandes de création d’organisation et de rattachement utilisateur.',

            'route' => [
                'demo' => 'GET /demo/requests',
                'api' => 'Composite : GET /api/org-registration-requests + GET /api/user-org-requests',
            ],

            'demo_controller' => [
                'class' => 'DemoRequestController',
                'method' => 'index',
            ],

            'api_controller' => [
                'class' => 'OrgRegistrationRequestController / UserOrgRequestController',
                'method' => 'index',
            ],
            'request' => null,

            'service' => [
                'class' => 'OrgRegistrationRequest / UserOrgRequest',
                'method' => 'query',
                'internal_methods' => [
                    'chargement des demandes de création d’organisation',
                    'chargement des demandes de rattachement utilisateur',
                    'préchargement des utilisateurs et organisations liés',
                ],
            ],

            'related_services' => [
                'OrgRegistrationRequestService',
                'UserOrgRequestService',
            ],

            'tables' => [
                'org_registration_requests',
                'user_org_requests',
                'users',
                'organizations',
                'locations',
            ],

            'effects' => [
                'Les demandes administratives sont affichées.',
                'Les demandes en attente peuvent être approuvées ou refusées.',
                'Les demandes déjà traitées sont affichées comme telles.',
            ],
        ],

        'requests.organizations.store' => [
            'title' => 'Création d’une demande d’organisation',
            'category' => 'Demandes',
            'scenario' => 'admin',
            'goal' => 'Créer une demande de création d’organisation depuis la démo.',

            'route' => [
                'demo' => 'POST /demo/requests/organizations',
                'api' => 'POST /api/org-registration-requests',
            ],

            'demo_controller' => [
                'class' => 'DemoRequestController',
                'method' => 'storeOrg',
            ],

            'api_controller' => [
                'class' => 'OrgRegistrationRequestController',
                'method' => 'store',
            ],

            'request' => [
                'organization_name',
                'organization_type',
                'enterprise_number',
                'official_email',
                'phone',
                'location_id',
                'contact_person_name',
                'contact_person_role',
                'proof_document_path',
                'comment',
            ],

            'service' => [
                'class' => 'OrgRegistrationRequestService',
                'method' => 'create',
                'internal_methods' => [
                    'Validation des données de demande',
                    'Vérification du droit de création',
                    'Création de la demande en attente',
                    'Calcul de la note de confiance',
                    'Notification des utilisateurs internes',
                    'Redirection vers les demandes administratives',
                ],
            ],

            'related_services' => [
                'OrgRegistrationRequestService',
                'OrgRegistrationConfidenceScoreService',
                'NotificationDispatchService',
                'NotificationRecipientResolver',
            ],

            'tables' => [
                'org_registration_requests',
                'users',
                'locations',
                'notifications',
                'notification_deliveries',
            ],

            'effects' => [
                'Une demande de création d’organisation est créée.',
                'La demande est placée en attente.',
                'Une note de confiance peut être calculée.',
                'Les agents internes peuvent être notifiés.',
                'L’inspector conserve l’action POST malgré la redirection.',
            ],
        ],

        'requests.organizations.approve' => [
            'title' => 'Approbation d’une demande d’organisation',
            'category' => 'Demandes',
            'scenario' => 'admin',
            'goal' => 'Approuver une demande de création d’organisation et créer l’organisation correspondante.',

            'route' => [
                'demo' => 'POST /demo/requests/organizations/{orgRegistrationRequest}/approve',
                'api' => 'PATCH /api/org-registration-requests/{orgRegistrationRequest}/approve',
            ],

            'demo_controller' => [
                'class' => 'DemoRequestController',
                'method' => 'approveOrg',
            ],

            'api_controller' => [
                'class' => 'OrgRegistrationRequestController',
                'method' => 'approve',
            ],

            'request' => null,

            'service' => [
                'class' => 'OrgRegistrationRequestService',
                'method' => 'approve',
                'internal_methods' => [
                    'Vérification du droit d’approbation',
                    'Vérification que la demande est encore en attente',
                    'Création de l’organisation',
                    'Rattachement du demandeur comme responsable',
                    'Passage de la demande au statut approuvé',
                    'Enregistrement de l’utilisateur qui a traité la demande',
                    'Redirection vers les demandes administratives',
                ],
            ],

            'related_services' => [
                'OrgRegistrationRequestService',
            ],

            'tables' => [
                'org_registration_requests',
                'organizations',
                'user_organization',
                'users',
            ],

            'effects' => [
                'Une nouvelle organisation est créée.',
                'Le demandeur est rattaché à cette organisation.',
                'La demande passe au statut approuvé.',
                'L’inspector conserve l’action POST malgré la redirection.',
            ],
        ],

        'requests.organizations.reject' => [
            'title' => 'Refus d’une demande d’organisation',
            'category' => 'Demandes',
            'scenario' => 'admin',
            'goal' => 'Refuser une demande de création d’organisation avec un motif explicite.',

            'route' => [
                'demo' => 'POST /demo/requests/organizations/{orgRegistrationRequest}/reject',
                'api' => 'PATCH /api/org-registration-requests/{orgRegistrationRequest}/reject',
            ],

            'demo_controller' => [
                'class' => 'DemoRequestController',
                'method' => 'rejectOrg',
            ],

            'api_controller' => [
                'class' => 'OrgRegistrationRequestController',
                'method' => 'reject',
            ],

            'request' => [
                'rejection_reason',
            ],

            'service' => [
                'class' => 'OrgRegistrationRequestService',
                'method' => 'reject',
                'internal_methods' => [
                    'Validation du motif de refus',
                    'Vérification du droit de refus',
                    'Vérification que la demande est encore traitable',
                    'Passage de la demande au statut refusé',
                    'Enregistrement du motif de refus',
                    'Enregistrement de l’utilisateur qui a traité la demande',
                    'Redirection vers les demandes administratives',
                ],
            ],

            'related_services' => [
                'OrgRegistrationRequestService',
                'NotificationDispatchService',
                'NotificationRecipientResolver',
            ],

            'tables' => [
                'org_registration_requests',
                'users',
                'notifications',
                'notification_deliveries',
            ],

            'effects' => [
                'La demande de création d’organisation est refusée.',
                'Le motif du refus est conservé.',
                'La demande ne crée pas d’organisation.',
                'Le demandeur peut être notifié.',
                'L’inspector conserve l’action POST malgré la redirection.',
            ],
        ],

        'requests.user_organizations.store' => [
            'title' => 'Création d’une demande de rattachement',
            'category' => 'Demandes',
            'scenario' => 'admin',
            'goal' => 'Créer une demande de rattachement d’un utilisateur à une organisation existante.',

            'route' => [
                'demo' => 'POST /demo/requests/user-organizations',
                'api' => 'POST /api/user-org-requests',
            ],

            'demo_controller' => [
                'class' => 'DemoRequestController',
                'method' => 'storeUserOrg',
            ],

            'api_controller' => [
                'class' => 'UserOrgRequestController',
                'method' => 'store',
            ],

            'request' => [
                'organization_id',
                'requested_role',
                'justification',
                'proof_document_path',
            ],

            'service' => [
                'class' => 'UserOrgRequestService',
                'method' => 'create',
                'internal_methods' => [
                    'Validation des données de rattachement',
                    'Vérification du droit de création',
                    'Vérification de l’organisation ciblée',
                    'Création de la demande de rattachement',
                    'Passage de la demande au statut en attente',
                    'Redirection vers les demandes administratives',
                ],
            ],

            'related_services' => [
                'UserOrgRequestService',
                'NotificationDispatchService',
                'NotificationRecipientResolver',
            ],

            'tables' => [
                'user_org_requests',
                'organizations',
                'users',
                'notifications',
                'notification_deliveries',
            ],

            'effects' => [
                'Une demande de rattachement est créée.',
                'L’utilisateur demande un rôle dans une organisation existante.',
                'La demande reste en attente jusqu’à validation ou refus.',
                'Les responsables internes peuvent être notifiés.',
                'L’inspector conserve l’action POST malgré la redirection.',
            ],
        ],

        'requests.user_organizations.approve' => [
            'title' => 'Approbation d’une demande de rattachement',
            'category' => 'Demandes',
            'scenario' => 'admin',
            'goal' => 'Approuver une demande de rattachement et associer l’utilisateur à l’organisation.',

            'route' => [
                'demo' => 'POST /demo/requests/user-organizations/{userOrgRequest}/approve',
                'api' => 'PATCH /api/user-org-requests/{userOrgRequest}/approve',
            ],

            'demo_controller' => [
                'class' => 'DemoRequestController',
                'method' => 'approveUserOrg',
            ],

            'api_controller' => [
                'class' => 'UserOrgRequestController',
                'method' => 'approve',
            ],

            'request' => null,

            'service' => [
                'class' => 'UserOrgRequestService',
                'method' => 'approve',
                'internal_methods' => [
                    'Vérification du droit d’approbation',
                    'Vérification que la demande est encore en attente',
                    'Rattachement de l’utilisateur à l’organisation',
                    'Attribution du rôle demandé',
                    'Passage de la demande au statut approuvé',
                    'Enregistrement de l’utilisateur qui a traité la demande',
                    'Redirection vers les demandes administratives',
                ],
            ],

            'related_services' => [
                'UserOrgRequestService',
            ],

            'tables' => [
                'user_org_requests',
                'user_organization',
                'organizations',
                'users',
                'roles',
            ],

            'effects' => [
                'L’utilisateur est rattaché à l’organisation.',
                'Le rôle demandé peut être attribué.',
                'La demande passe au statut approuvé.',
                'L’inspector conserve l’action POST malgré la redirection.',
            ],
        ],

        'requests.user_organizations.reject' => [
            'title' => 'Refus d’une demande de rattachement',
            'category' => 'Demandes',
            'scenario' => 'admin',
            'goal' => 'Refuser une demande de rattachement avec un motif explicite.',

            'route' => [
                'demo' => 'POST /demo/requests/user-organizations/{userOrgRequest}/reject',
                'api' => 'PATCH /api/user-org-requests/{userOrgRequest}/reject',
            ],

            'demo_controller' => [
                'class' => 'DemoRequestController',
                'method' => 'rejectUserOrg',
            ],

            'api_controller' => [
                'class' => 'UserOrgRequestController',
                'method' => 'reject',
            ],

            'request' => [
                'rejection_reason',
            ],

            'service' => [
                'class' => 'UserOrgRequestService',
                'method' => 'reject',
                'internal_methods' => [
                    'Validation du motif de refus',
                    'Vérification du droit de refus',
                    'Vérification que la demande est encore traitable',
                    'Passage de la demande au statut refusé',
                    'Enregistrement du motif de refus',
                    'Enregistrement de l’utilisateur qui a traité la demande',
                    'Redirection vers les demandes administratives',
                ],
            ],

            'related_services' => [
                'UserOrgRequestService',
                'NotificationDispatchService',
                'NotificationRecipientResolver',
            ],

            'tables' => [
                'user_org_requests',
                'organizations',
                'users',
                'notifications',
                'notification_deliveries',
            ],

            'effects' => [
                'La demande de rattachement est refusée.',
                'Le motif du refus est conservé.',
                'L’utilisateur n’est pas rattaché à l’organisation.',
                'Le demandeur peut être notifié.',
                'L’inspector conserve l’action POST malgré la redirection.',
            ],
        ],

        // _________________________________________________________________________________________DOCUMENTS

        'documents.index' => [
            'title' => 'Consultation des documents',
            'category' => 'Documents',
            'scenario' => 'admin',
            'goal' => 'Afficher les documents générés et les formulaires de génération de feuilles de route.',

            'route' => [
                'demo' => 'GET /demo/documents',
                'api' => 'GET /api/documents',
            ],

            'demo_controller' => [
                'class' => 'DemoDocumentController',
                'method' => 'index',
            ],

            'api_controller' => [
                'class' => 'DocumentController',
                'method' => 'index',
            ],

            'request' => null,

            'service' => [
                'class' => 'Document',
                'method' => 'query',
                'internal_methods' => [
                    'chargement des documents',
                    'chargement des véhicules actifs',
                    'pagination',
                ],
            ],

            'related_services' => [
                'RouteSheetDocumentService',
            ],

            'tables' => [
                'documents',
                'reservations',
                'organizations',
                'users',
                'vehicles',
            ],

            'effects' => [
                'Les documents générés sont listés.',
                'Les véhicules actifs sont proposés pour générer une feuille journalière.',
                'Les documents disponibles peuvent être téléchargés.',
            ],
        ],

        'documents.route_sheets.generate' => [
            'title' => 'Génération d’une feuille de route',
            'category' => 'Documents',
            'scenario' => 'admin',
            'goal' => 'Générer un document de feuille de route à partir des réservations planifiées.',

            'route' => [
                'demo' => 'POST /demo/documents/route-sheets',
                'api' => 'POST /api/documents/route-sheets/generate',
            ],

            'demo_controller' => [
                'class' => 'DemoDocumentController',
                'method' => 'generateRouteSheet',
            ],

            'api_controller' => [
                'class' => 'RouteSheetDocumentController',
                'method' => 'generate',
            ],

            'request' => [
                'class' => 'GenerateRouteSheetRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'RouteSheetDocumentService',
                'method' => 'generate',
                'internal_methods' => [
                    'Validation de la période ou du véhicule',
                    'Récupération des réservations concernées',
                    'Construction du document',
                    'Stockage du fichier généré',
                    'Création de l’enregistrement document',
                ],
            ],

            'related_services' => [
                'DocumentStorageService',
                'DomainEventLogger::log',
            ],

            'tables' => [
                'documents',
                'reservations',
                'vehicles',
                'drivers',
                'organizations',
                'locations',
            ],

            'effects' => [
                'Un fichier PDF de feuille de route est généré.',
                'Un document est enregistré en base de données.',
                'Le document devient disponible au téléchargement.',
            ],
        ],

        'documents.download' => [
            'title' => 'Téléchargement d’un document',
            'category' => 'Documents',
            'scenario' => 'admin',
            'goal' => 'Télécharger un document généré par le backend.',

            'route' => [
                'demo' => 'GET /demo/documents/{document}/download',
                'api' => 'GET /api/documents/{document}/download',
            ],

            'demo_controller' => [
                'class' => 'DemoDocumentController',
                'method' => 'download',
            ],

            'api_controller' => [
                'class' => 'DocumentController',
                'method' => 'download',
            ],

            'request' => null,

            'service' => [
                'class' => 'Document',
                'method' => 'download',
                'internal_methods' => [
                    'Vérification de l’existence du document',
                    'Vérification du fichier stocké',
                    'Retour du fichier en réponse HTTP',
                ],
            ],

            'related_services' => [
                'Storage',
            ],

            'tables' => [
                'documents',
            ],

            'effects' => [
                'Le fichier est récupéré depuis le stockage.',
                'Le navigateur déclenche le téléchargement.',
            ],
        ],

        // _________________________________________________________________________________________ORGANISATIONS
        'organizations.index' => [
            'title' => 'Consultation des organisations',
            'category' => 'Organisations',
            'scenario' => 'admin',
            'goal' => 'Afficher les organisations visibles par l’acteur courant.',

            'route' => [
                'demo' => 'GET /demo/organizations',
                'api' => 'GET /api/organizations',
            ],

            'demo_controller' => [
                'class' => 'DemoOrganizationController',
                'method' => 'index',
            ],

            'api_controller' => [
                'class' => 'OrganizationController',
                'method' => 'index',
            ],

            'request' => null,

            'service' => [
                'class' => 'DemoDataService',
                'method' => 'organizations',
                'internal_methods' => [
                    'Identification de l’acteur courant',
                    'Filtrage des organisations visibles',
                    'Préparation de la liste affichée dans la démo',
                ],
            ],

            'related_services' => [
                'DemoActorResolver',
                'DemoDataService',
            ],

            'tables' => [
                'organizations',
                'users',
                'user_organization',
                'roles',
                'permissions',
            ],

            'effects' => [
                'Les organisations visibles sont chargées.',
                'La liste est adaptée aux droits de l’acteur courant.',
                'Les informations sont préparées pour la page de démonstration.',
            ],
        ],

        // _________________________________________________________________________________________GESTION (ADMIN)
        'admin.index' => [
            'title' => 'Consultation de la gestion',
            'category' => 'Gestion',
            'scenario' => 'admin',
            'goal' => 'Afficher les données de gestion accessibles à l’acteur courant.',

            'route' => [
                'demo' => 'GET /demo/admin',
                'api' => null,
            ],

            'demo_controller' => [
                'class' => 'DemoAdminController',
                'method' => 'index',
            ],

            'api_controller' => null,

            'request' => null,

            'service' => [
                'class' => 'DemoDataService',
                'method' => 'unavailabilities',
                'internal_methods' => [
                    'Vérification des permissions de gestion',
                    'Chargement des utilisateurs visibles',
                    'Chargement des chauffeurs visibles',
                    'Chargement des véhicules visibles',
                    'Chargement des indisponibilités si autorisé',
                ],
            ],

            'related_services' => [
                'DemoActorResolver',
                'DemoDataService',
            ],

            'tables' => [
                'users',
                'drivers',
                'vehicles',
                'unavailabilities',
                'roles',
                'permissions',
            ],

            'effects' => [
                'Les données de gestion autorisées sont chargées.',
                'Les sections interdites restent masquées.',
                'La page de gestion reflète les permissions de l’acteur courant.',
            ],
        ],

        // _________________________________________________________________________________________AUTH
        'auth.register' => [
            'title' => 'Inscription d’un utilisateur',
            'category' => 'Authentification',
            'scenario' => null,
            'goal' => 'Créer un nouveau compte utilisateur avec envoi automatique d’un email de vérification.',

            'route' => [
                'demo' => null,
                'api' => 'POST /api/auth/register',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'AuthController',
                'method' => 'register',
            ],

            'request' => [
                'first_name',
                'last_name',
                'email',
                'phone',
                'password',
                'password_confirmation',
            ],

            'service' => [
                'class' => 'AuthService',
                'method' => 'register',
                'internal_methods' => [
                    'Validation des données d’inscription',
                    'Création de l’utilisateur avec mot de passe haché',
                    'Attribution du rôle par défaut',
                    'Envoi de l’email de vérification (MustVerifyEmail)',
                    'Régénération de la session',
                ],
            ],

            'related_services' => [
                'VerifyEmail (notification Laravel)',
                'Mailtrap SMTP',
            ],

            'tables' => [
                'users',
                'model_has_roles',
                'jobs',
            ],

            'effects' => [
                'Un compte utilisateur est créé avec email non vérifié.',
                'Un email de vérification est envoyé via Mailtrap.',
                'L’utilisateur ne peut pas accéder aux routes métier avant vérification.',
                'Le rôle user est attribué par défaut.',
            ],
        ],

        'auth.login' => [
            'title' => 'Connexion d’un utilisateur',
            'category' => 'Authentification',
            'scenario' => null,
            'goal' => 'Authentifier un utilisateur via Sanctum en mode cookie stateful.',

            'route' => [
                'demo' => null,
                'api' => 'POST /api/auth/login',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'AuthController',
                'method' => 'login',
            ],

            'request' => [
                'email',
                'password',
                'remember',
            ],

            'service' => [
                'class' => 'AuthService',
                'method' => 'login',
                'internal_methods' => [
                    'Vérification du rate limiting',
                    'Authentification via Auth guard (session)',
                    'Régénération de la session CSRF',
                    'Chargement des rôles et organisations',
                ],
            ],

            'related_services' => [
                'Sanctum (statefulApi)',
                'Session Laravel',
            ],

            'tables' => [
                'users',
                'roles',
                'organizations',
            ],

            'effects' => [
                'L’utilisateur est authentifié via cookie de session.',
                'Le cookie XSRF-TOKEN est émis pour les requêtes PATCH/POST suivantes.',
                'Les rôles et organisations sont chargés et retournés dans la réponse.',
                'La session est régénérée pour prévenir la fixation de session.',
            ],
        ],

        'auth.logout' => [
            'title' => 'Déconnexion',
            'category' => 'Authentification',
            'scenario' => null,
            'goal' => 'Terminer la session et invalider les cookies d’authentification.',

            'route' => [
                'demo' => null,
                'api' => 'POST /api/auth/logout',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'AuthController',
                'method' => 'logout',
            ],

            'request' => null,

            'service' => [
                'class' => 'AuthService',
                'method' => 'logout',
                'internal_methods' => [
                    'Invalidation de la session courante',
                    'Suppression des cookies Sanctum',
                ],
            ],

            'related_services' => [
                'Sanctum',
                'Session Laravel',
            ],

            'tables' => [
                'users',
            ],

            'effects' => [
                'La session est invalidée côté serveur.',
                'Les cookies d’authentification sont supprimés.',
                'L’utilisateur est redirigé vers la page de connexion.',
            ],
        ],

        // _________________________________________________________________________________________RÉSERVATIONS (NOUVELLES ACTIONS V2)
        'reservations.retry_assign' => [
            'title' => 'Tentative de réassignation automatique',
            'category' => 'Réservations',
            'scenario' => 'admin',
            'goal' => 'Retenter l’assignation automatique d’un chauffeur et d’un véhicule disponibles sur une réservation en conflit.',

            'route' => [
                'demo' => null,
                'api' => 'PATCH /api/reservations/{reservation}/retry-assign',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'ReservationController',
                'method' => 'retryAutoAssign',
            ],

            'request' => null,

            'service' => [
                'class' => 'ReservationService',
                'method' => 'retryAutoAssign',
                'internal_methods' => [
                    'autoAssign : calcul fenêtre d’occupation',
                    'Exclusion SQL des chauffeurs et véhicules occupés (whereNotIn)',
                    'Assignation si ressource disponible trouvée',
                    'checkAndAutoTransition : si plus aucun conflit → statut pré-conflit',
                ],
            ],

            'related_services' => [
                'ReservationAssignmentService::autoAssign',
                'ReservationOccupationService',
                'DomainEventLogger::log',
            ],

            'tables' => [
                'reservations',
                'drivers',
                'vehicles',
                'unavailabilities',
                'reservation_conflicts',
                'reservation_assignment_history',
                'reservation_status_history',
                'domain_events',
            ],

            'effects' => [
                'Si un chauffeur et un véhicule sont disponibles, ils sont assignés.',
                'Si aucune ressource n’est disponible, les conflits sont maintenus.',
                'Si plus aucun conflit actif après l’assignation, la réservation retrouve son statut pré-conflit.',
                'L’historique d’assignation et de statut est mis à jour.',
            ],
        ],

        'reservations.confirm_passengers' => [
            'title' => 'Confirmation du nombre de passagers',
            'category' => 'Réservations',
            'scenario' => null,
            'goal' => 'Mettre à jour le nombre de passagers et enregistrer l’horodatage de confirmation. Si le trajet est aujourd’hui, la feuille de route est régénérée.',

            'route' => [
                'demo' => null,
                'api' => 'PATCH /api/reservations/{reservation}/confirm-passengers',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'ReservationController',
                'method' => 'confirmPassengers',
            ],

            'request' => [
                'nb_adults',
                'nb_children',
                'trip_purpose',
            ],

            'service' => [
                'class' => 'ReservationService',
                'method' => 'confirmPassengers',
                'internal_methods' => [
                    'Vérification de la capacité : nb_adults + nb_children ≤ capacity - 1 (chauffeur inclus)',
                    'Mise à jour de nb_adults, nb_children, trip_purpose',
                    'Enregistrement de passengers_confirmed_at = now()',
                    'Si trip_date == today ET véhicule assigné → regénère la feuille de route',
                    'Notification au demandeur',
                ],
            ],

            'related_services' => [
                'RouteSheetDocumentService::generate',
                'NotificationDispatchService::dispatch',
            ],

            'tables' => [
                'reservations',
                'documents',
                'notifications',
                'notification_deliveries',
            ],

            'effects' => [
                'Le nombre de passagers est mis à jour.',
                'Le champ passengers_confirmed_at est renseigné.',
                'La feuille de route du jour est régénérée si le trajet a lieu aujourd’hui.',
                'Un dépassement de capacité lève une erreur de validation.',
            ],
        ],

        'reservations.conflicts.resolve' => [
            'title' => 'Résolution manuelle d’un conflit',
            'category' => 'Réservations',
            'scenario' => 'admin',
            'goal' => 'Marquer un conflit spécifique comme résolu et déclencher la transition automatique de statut si plus aucun conflit actif.',

            'route' => [
                'demo' => null,
                'api' => 'PATCH /api/reservations/{reservation}/conflicts/{conflict}/resolve',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'ReservationConflictController',
                'method' => 'resolve',
            ],

            'request' => null,

            'service' => [
                'class' => 'ReservationService',
                'method' => 'resolveConflict',
                'internal_methods' => [
                    'Marquage du conflit comme résolu (is_resolved = true)',
                    'Vérification qu’il n’existe plus de conflits actifs non résolus',
                    'checkAndAutoTransition : si plus aucun conflit → autoTransitionFromConflict',
                    'autoTransitionFromConflict : retrouve le statut pré-conflit via ReservationStatusHistory',
                ],
            ],

            'related_services' => [
                'ReservationAssignmentService',
                'DomainEventLogger::log',
            ],

            'tables' => [
                'reservation_conflicts',
                'reservations',
                'reservation_status_history',
                'domain_events',
            ],

            'effects' => [
                'Le conflit est marqué comme résolu.',
                'Si c’était le dernier conflit actif, la réservation reprend son statut d’avant le conflit.',
                'Le statut cible est déterminé par l’historique : CONFIRME, APPROUVE ou EN_ATTENTE.',
                'Une réassignation automatique peut être tentée si nécessaire.',
            ],
        ],

        // _________________________________________________________________________________________RÉCURRENCES
        'recurrences.store' => [
            'title' => 'Création d’une réservation récurrente',
            'category' => 'Réservations',
            'scenario' => 'admin',
            'goal' => 'Créer un ensemble de réservations périodiques pour une école communale sur une plage de dates avec une fréquence définie.',

            'route' => [
                'demo' => null,
                'api' => 'POST /api/recurrences',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'RecurrenceController',
                'method' => 'store',
            ],

            'request' => [
                'organization_id',
                'pickup_location_id',
                'destination_location_id',
                'departure_time',
                'return_time',
                'nb_children',
                'nb_adults',
                'activity_type',
                'trip_purpose',
                'frequency',
                'days_of_week',
                'start_date',
                'end_date',
            ],

            'service' => [
                'class' => 'RecurrenceService',
                'method' => 'create',
                'internal_methods' => [
                    'Calcul de toutes les occurrences selon fréquence et jours',
                    'Filtrage des dates non ouvrables',
                    'Création de la récurrence parente',
                    'Création de chaque réservation individuelle via ReservationService::create',
                    'Détection de conflits par occurrence',
                ],
            ],

            'related_services' => [
                'ReservationService::create',
                'ReservationPreviewService',
                'DomainEventLogger::log',
            ],

            'tables' => [
                'recurrences',
                'reservations',
                'reservation_conflicts',
                'locations',
                'domain_events',
            ],

            'effects' => [
                'Un objet récurrence parent est créé.',
                'Une réservation individuelle est créée pour chaque occurrence valide.',
                'Les dates non ouvrables ou en conflit sont ignorées ou signalées.',
                'Toutes les réservations sont liées à la récurrence via recurrence_id.',
            ],
        ],

        // _________________________________________________________________________________________DEMANDES — JUSTIFICATIFS
        'requests.organizations.proof_upload' => [
            'title' => 'Upload du justificatif d’organisation',
            'category' => 'Demandes',
            'scenario' => null,
            'goal' => 'Attacher un document justificatif (PDF, JPG, PNG) à une demande de création d’organisation.',

            'route' => [
                'demo' => null,
                'api' => 'POST /api/org-registration-requests/{orgRegistrationRequest}/proof',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'OrgRegistrationRequestController',
                'method' => 'uploadProof',
            ],

            'request' => [
                'proof_document (fichier — max 5 Mo, PDF/JPG/PNG)',
            ],

            'service' => [
                'class' => 'ProofDocumentService',
                'method' => 'store',
                'internal_methods' => [
                    'Validation du fichier (type, taille)',
                    'Stockage dans Storage::disk (private)',
                    'Création du document en base',
                    'Association proof_document_id à la demande',
                ],
            ],

            'related_services' => [
                'DocumentStorageService',
            ],

            'tables' => [
                'documents',
                'org_registration_requests',
            ],

            'effects' => [
                'Le fichier est stocké dans le dossier privé.',
                'Un document est créé en base et lié à la demande.',
                'Le champ proof_document_id de la demande est mis à jour.',
            ],
        ],

        'requests.organizations.proof_delete' => [
            'title' => 'Suppression du justificatif d’organisation',
            'category' => 'Demandes',
            'scenario' => null,
            'goal' => 'Supprimer le document justificatif d’une demande d’organisation en attente.',

            'route' => [
                'demo' => null,
                'api' => 'DELETE /api/org-registration-requests/{orgRegistrationRequest}/proof',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'OrgRegistrationRequestController',
                'method' => 'deleteProof',
            ],

            'request' => null,

            'service' => [
                'class' => 'ProofDocumentService',
                'method' => 'delete',
                'internal_methods' => [
                    'Suppression du fichier dans Storage',
                    'Suppression du document en base',
                    'Réinitialisation de proof_document_id sur la demande',
                ],
            ],

            'related_services' => [
                'DocumentStorageService',
            ],

            'tables' => [
                'documents',
                'org_registration_requests',
            ],

            'effects' => [
                'Le fichier est supprimé du stockage.',
                'L’enregistrement document est supprimé.',
                'La demande n’a plus de justificatif attaché.',
            ],
        ],

        'requests.user_organizations.proof_upload' => [
            'title' => 'Upload du justificatif d’adhésion',
            'category' => 'Demandes',
            'scenario' => null,
            'goal' => 'Attacher un document justificatif à une demande d’adhésion à une organisation.',

            'route' => [
                'demo' => null,
                'api' => 'POST /api/user-org-requests/{userOrgRequest}/proof',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'UserOrgRequestController',
                'method' => 'uploadProof',
            ],

            'request' => [
                'proof_document (fichier — max 5 Mo, PDF/JPG/PNG)',
            ],

            'service' => [
                'class' => 'ProofDocumentService',
                'method' => 'store',
                'internal_methods' => [
                    'Validation du fichier',
                    'Stockage privé',
                    'Création du document en base',
                    'Association proof_document_id à la demande d’adhésion',
                ],
            ],

            'related_services' => [
                'DocumentStorageService',
            ],

            'tables' => [
                'documents',
                'user_org_requests',
            ],

            'effects' => [
                'Le justificatif est stocké et lié à la demande d’adhésion.',
                'L’agent peut le consulter lors du traitement.',
            ],
        ],

        'requests.user_organizations.proof_delete' => [
            'title' => 'Suppression du justificatif d’adhésion',
            'category' => 'Demandes',
            'scenario' => null,
            'goal' => 'Supprimer le justificatif d’une demande d’adhésion en attente.',

            'route' => [
                'demo' => null,
                'api' => 'DELETE /api/user-org-requests/{userOrgRequest}/proof',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'UserOrgRequestController',
                'method' => 'deleteProof',
            ],

            'request' => null,

            'service' => [
                'class' => 'ProofDocumentService',
                'method' => 'delete',
                'internal_methods' => [
                    'Suppression du fichier dans Storage',
                    'Suppression du document en base',
                    'Réinitialisation de proof_document_id',
                ],
            ],

            'related_services' => [
                'DocumentStorageService',
            ],

            'tables' => [
                'documents',
                'user_org_requests',
            ],

            'effects' => [
                'Le fichier et l’enregistrement document sont supprimés.',
                'La demande d’adhésion n’a plus de justificatif.',
            ],
        ],

        // _________________________________________________________________________________________NOTIFICATIONS — PRÉFÉRENCES
        'notifications.preferences.update' => [
            'title' => 'Mise à jour des préférences de notification',
            'category' => 'Notifications',
            'scenario' => null,
            'goal' => 'Modifier les canaux actifs (database, mail) par catégorie et la fréquence des digests.',

            'route' => [
                'demo' => null,
                'api' => 'PATCH /api/notifications/preferences',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'NotificationPreferenceController',
                'method' => 'update',
            ],

            'request' => [
                'digest_frequency (none|daily|weekly)',
                'categories.{category}.database (bool)',
                'categories.{category}.mail (bool)',
            ],

            'service' => [
                'class' => 'NotificationPreferenceService',
                'method' => 'update',
                'internal_methods' => [
                    'Validation des préférences reçues',
                    'Mise à jour des canaux par catégorie',
                    'Mise à jour de digest_frequency',
                ],
            ],

            'related_services' => [
                'NotificationDispatchService',
            ],

            'tables' => [
                'notification_preferences',
            ],

            'effects' => [
                'Les canaux (database, mail) sont activés ou désactivés par catégorie.',
                'La fréquence des digests est mise à jour.',
                'Les prochaines notifications respecteront ces préférences.',
                'NotificationDispatchService consulte ces préférences avant chaque envoi.',
            ],
        ],

        // _________________________________________________________________________________________LIEUX
        'locations.store' => [
            'title' => 'Création ou déduplication d’un lieu',
            'category' => 'Lieux',
            'scenario' => null,
            'goal' => 'Enregistrer un lieu depuis une suggestion ORS, ou retourner le lieu existant si le place_id est déjà connu (déduplication).',

            'route' => [
                'demo' => null,
                'api' => 'POST /api/locations',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'LocationController',
                'method' => 'store',
            ],

            'request' => [
                'formatted_address',
                'street',
                'street_number',
                'postal_code',
                'city',
                'country',
                'latitude',
                'longitude',
                'place_id',
            ],

            'service' => [
                'class' => 'LocationService',
                'method' => 'createOrFind',
                'internal_methods' => [
                    'Si place_id fourni : recherche d’un lieu existant avec ce place_id',
                    'Si trouvé : retourne le lieu existant sans doublon',
                    'Si non trouvé : création du lieu avec coordonnées GPS',
                    'Le geocoding est ignoré car les coordonnées viennent directement d’ORS',
                ],
            ],

            'related_services' => [
                'OpenRouteService (suggestions)',
            ],

            'tables' => [
                'locations',
            ],

            'effects' => [
                'Aucun doublon n’est créé si le place_id est déjà en base.',
                'Les coordonnées GPS (latitude, longitude) sont stockées.',
                'Le lieu créé peut immédiatement être utilisé dans une réservation.',
                'Cette action est appelée en arrière-plan lors de la sélection d’une adresse dans le formulaire de réservation.',
            ],
        ],

        // _________________________________________________________________________________________INDISPONIBILITÉS
        'admin.unavailabilities.store' => [
            'title' => 'Création d’une indisponibilité',
            'category' => 'Administration',
            'scenario' => 'admin',
            'goal' => 'Enregistrer une période d’indisponibilité pour un chauffeur ou un véhicule et recalculer l’impact sur les réservations existantes.',

            'route' => [
                'demo' => null,
                'api' => 'POST /api/unavailabilities',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'UnavailabilityController',
                'method' => 'store',
            ],

            'request' => [
                'reason_type',
                'driver_id',
                'vehicle_id',
                'start_datetime',
                'end_datetime',
                'reason',
            ],

            'service' => [
                'class' => 'UnavailabilityService',
                'method' => 'create',
                'internal_methods' => [
                    'Validation : driver_id ou vehicle_id obligatoire (pas les deux)',
                    'Création de l’indisponibilité',
                    'applyUnavailabilityImpact : scan des réservations APPROUVE/CONFIRME dont la fenêtre chevauche',
                    'Désassignation chauffeur/véhicule sur les réservations impactées',
                    'Création de conflits NO_DRIVER_AVAILABLE / NO_VEHICLE_AVAILABLE',
                    'Transition EN_CONFLIT + notification interne',
                ],
            ],

            'related_services' => [
                'ReservationService::applyUnavailabilityImpact',
                'ReservationOccupationService',
                'NotificationDispatchService::dispatch',
                'DomainEventLogger::log',
            ],

            'tables' => [
                'unavailabilities',
                'reservations',
                'reservation_conflicts',
                'reservation_status_history',
                'notifications',
                'notification_deliveries',
                'domain_events',
            ],

            'effects' => [
                'L’indisponibilité est créée pour la ressource concernée.',
                'Les réservations qui chevauchent sont désassignées et mises en conflit.',
                'Les agents internes sont notifiés des réservations impactées.',
                'Les réservations passent au statut EN_CONFLIT si elles étaient APPROUVE ou CONFIRME.',
            ],
        ],

        'admin.unavailabilities.update' => [
            'title' => 'Modification d’une indisponibilité',
            'category' => 'Administration',
            'scenario' => 'admin',
            'goal' => 'Mettre à jour les dates ou le motif d’une indisponibilité et recalculer son impact.',

            'route' => [
                'demo' => null,
                'api' => 'PATCH /api/unavailabilities/{unavailability}',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'UnavailabilityController',
                'method' => 'update',
            ],

            'request' => [
                'start_datetime',
                'end_datetime',
                'reason',
                'change_reason',
            ],

            'service' => [
                'class' => 'UnavailabilityService',
                'method' => 'update',
                'internal_methods' => [
                    'Mise à jour des dates et du motif',
                    'Enregistrement de l’historique de modification',
                    'Recalcul de l’impact sur les réservations',
                ],
            ],

            'related_services' => [
                'ReservationService::applyUnavailabilityImpact',
                'UnavailabilityHistoryService',
            ],

            'tables' => [
                'unavailabilities',
                'unavailability_histories',
                'reservations',
                'reservation_conflicts',
            ],

            'effects' => [
                'Les dates de l’indisponibilité sont mises à jour.',
                'Un historique de modification est enregistré.',
                'L’impact sur les réservations est recalculé selon la nouvelle fenêtre.',
            ],
        ],

        'admin.unavailabilities.cancel' => [
            'title' => 'Annulation d’une indisponibilité',
            'category' => 'Administration',
            'scenario' => 'admin',
            'goal' => 'Annuler une indisponibilité et tenter de réassigner automatiquement les réservations précédemment impactées.',

            'route' => [
                'demo' => null,
                'api' => 'PATCH /api/unavailabilities/{unavailability}/cancel',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'UnavailabilityController',
                'method' => 'cancel',
            ],

            'request' => null,

            'service' => [
                'class' => 'UnavailabilityService',
                'method' => 'cancel',
                'internal_methods' => [
                    'Marquage de l’indisponibilité comme annulée (is_active = false)',
                    'Identification des réservations EN_CONFLIT liées à cette indispo',
                    'retryAutoAssign sur chaque réservation impactée',
                ],
            ],

            'related_services' => [
                'ReservationService::retryAutoAssign',
                'ReservationAssignmentService::autoAssign',
            ],

            'tables' => [
                'unavailabilities',
                'reservations',
                'reservation_conflicts',
                'reservation_assignment_history',
            ],

            'effects' => [
                'L’indisponibilité est annulée.',
                'La ressource redevient disponible pour les assignations.',
                'Une réassignation automatique est tentée sur les réservations qui avaient été impactées.',
            ],
        ],

        // _________________________________________________________________________________________CHAUFFEURS
        'admin.drivers.store' => [
            'title' => 'Création d’un chauffeur',
            'category' => 'Administration',
            'scenario' => 'admin',
            'goal' => 'Enregistrer un utilisateur existant en tant que chauffeur actif.',

            'route' => [
                'demo' => null,
                'api' => 'POST /api/drivers',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'DriverController',
                'method' => 'store',
            ],

            'request' => [
                'user_id',
                'licence_number',
                'licence_expiry_date',
            ],

            'service' => [
                'class' => 'Driver',
                'method' => 'create',
                'internal_methods' => [
                    'Vérification que l’utilisateur existe et a le rôle chauffeur',
                    'Création du profil chauffeur lié à l’utilisateur',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'drivers',
                'users',
            ],

            'effects' => [
                'Un profil chauffeur est créé et activé.',
                'Le chauffeur apparaît dans les listes d’assignation.',
            ],
        ],

        'admin.drivers.update' => [
            'title' => 'Modification d’un chauffeur',
            'category' => 'Administration',
            'scenario' => 'admin',
            'goal' => 'Mettre à jour le numéro de permis ou la date d’expiration d’un chauffeur.',

            'route' => [
                'demo' => null,
                'api' => 'PATCH /api/drivers/{driver}',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'DriverController',
                'method' => 'update',
            ],

            'request' => [
                'licence_number',
                'licence_expiry_date',
            ],

            'service' => [
                'class' => 'Driver',
                'method' => 'update',
                'internal_methods' => [
                    'Mise à jour des champs fournis',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'drivers',
            ],

            'effects' => [
                'Les informations du chauffeur sont mises à jour.',
            ],
        ],

        'admin.drivers.activate' => [
            'title' => 'Activation d’un chauffeur',
            'category' => 'Administration',
            'scenario' => 'admin',
            'goal' => 'Rendre un chauffeur inactif à nouveau disponible pour les assignations.',

            'route' => [
                'demo' => null,
                'api' => 'PATCH /api/drivers/{driver}/activate',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'DriverController',
                'method' => 'activate',
            ],

            'request' => null,

            'service' => [
                'class' => 'Driver',
                'method' => 'update',
                'internal_methods' => [
                    'is_active = true',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'drivers',
            ],

            'effects' => [
                'Le chauffeur est marqué actif.',
                'Il apparaît à nouveau dans les propositions d’assignation automatique.',
            ],
        ],

        'admin.drivers.deactivate' => [
            'title' => 'Désactivation d’un chauffeur',
            'category' => 'Administration',
            'scenario' => 'admin',
            'goal' => 'Rendre un chauffeur inactif pour qu’il ne soit plus proposé dans les assignations.',

            'route' => [
                'demo' => null,
                'api' => 'PATCH /api/drivers/{driver}/deactivate',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'DriverController',
                'method' => 'deactivate',
            ],

            'request' => null,

            'service' => [
                'class' => 'Driver',
                'method' => 'update',
                'internal_methods' => [
                    'is_active = false',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'drivers',
            ],

            'effects' => [
                'Le chauffeur est marqué inactif.',
                'Il n’est plus proposé dans les assignations automatiques ou manuelles.',
                'Les réservations déjà assignées ne sont pas affectées.',
            ],
        ],

        // _________________________________________________________________________________________VÉHICULES
        'admin.vehicles.store' => [
            'title' => 'Création d’un véhicule',
            'category' => 'Administration',
            'scenario' => 'admin',
            'goal' => 'Ajouter un véhicule à la flotte avec son immatriculation et sa capacité.',

            'route' => [
                'demo' => null,
                'api' => 'POST /api/vehicles',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'VehicleController',
                'method' => 'store',
            ],

            'request' => [
                'licence_plate',
                'capacity',
            ],

            'service' => [
                'class' => 'Vehicle',
                'method' => 'create',
                'internal_methods' => [
                    'Création du véhicule actif',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'vehicles',
            ],

            'effects' => [
                'Le véhicule est ajouté à la flotte.',
                'Il est disponible pour les assignations automatiques et manuelles.',
                'La capacité détermine le nombre maximum de passagers (chauffeur inclus).',
            ],
        ],

        'admin.vehicles.update' => [
            'title' => 'Modification d’un véhicule',
            'category' => 'Administration',
            'scenario' => 'admin',
            'goal' => 'Mettre à jour l’immatriculation ou la capacité d’un véhicule.',

            'route' => [
                'demo' => null,
                'api' => 'PATCH /api/vehicles/{vehicle}',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'VehicleController',
                'method' => 'update',
            ],

            'request' => [
                'licence_plate',
                'capacity',
            ],

            'service' => [
                'class' => 'Vehicle',
                'method' => 'update',
                'internal_methods' => [
                    'Mise à jour des champs fournis',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'vehicles',
            ],

            'effects' => [
                'Les informations du véhicule sont mises à jour.',
            ],
        ],

        'admin.vehicles.activate' => [
            'title' => 'Activation d’un véhicule',
            'category' => 'Administration',
            'scenario' => 'admin',
            'goal' => 'Rendre un véhicule inactif à nouveau disponible pour les assignations.',

            'route' => [
                'demo' => null,
                'api' => 'PATCH /api/vehicles/{vehicle}/activate',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'VehicleController',
                'method' => 'activate',
            ],

            'request' => null,

            'service' => [
                'class' => 'Vehicle',
                'method' => 'update',
                'internal_methods' => [
                    'is_active = true',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'vehicles',
            ],

            'effects' => [
                'Le véhicule est remis en service.',
                'Il peut à nouveau être assigné aux réservations.',
            ],
        ],

        'admin.vehicles.deactivate' => [
            'title' => 'Désactivation d’un véhicule',
            'category' => 'Administration',
            'scenario' => 'admin',
            'goal' => 'Retirer un véhicule du service actif.',

            'route' => [
                'demo' => null,
                'api' => 'PATCH /api/vehicles/{vehicle}/deactivate',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'VehicleController',
                'method' => 'deactivate',
            ],

            'request' => null,

            'service' => [
                'class' => 'Vehicle',
                'method' => 'update',
                'internal_methods' => [
                    'is_active = false',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'vehicles',
            ],

            'effects' => [
                'Le véhicule est retiré du service.',
                'Il ne sera plus proposé dans les assignations.',
                'Les réservations déjà assignées ne sont pas impactées.',
            ],
        ],

        // _________________________________________________________________________________________UTILISATEURS
        'admin.users.update_status' => [
            'title' => 'Blocage ou déblocage d’un utilisateur',
            'category' => 'Administration',
            'scenario' => 'admin',
            'goal' => 'Modifier le statut actif/bloqué d’un utilisateur sans supprimer ses tokens.',

            'route' => [
                'demo' => null,
                'api' => 'PATCH /api/users/{user}/status',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'UserController',
                'method' => 'updateStatus',
            ],

            'request' => [
                'active (bool)',
            ],

            'service' => [
                'class' => 'User',
                'method' => 'update',
                'internal_methods' => [
                    'Vérification de la permission users.update-status',
                    'Mise à jour du champ active',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'users',
            ],

            'effects' => [
                'L’utilisateur est bloqué (active = false) ou débloqué (active = true).',
                'Les tokens Sanctum existants ne sont pas révoqués automatiquement.',
                'L’utilisateur bloqué peut toujours avoir des tokens actifs jusqu’à leur expiration.',
            ],
        ],

        'admin.users.deactivate' => [
            'title' => 'Désactivation définitive d’un utilisateur',
            'category' => 'Administration',
            'scenario' => 'admin',
            'goal' => 'Désactiver définitivement un compte utilisateur et révoquer tous ses tokens d’accès.',

            'route' => [
                'demo' => null,
                'api' => 'PATCH /api/users/{user}/deactivate',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'UserController',
                'method' => 'deactivate',
            ],

            'request' => null,

            'service' => [
                'class' => 'UserService',
                'method' => 'deactivate',
                'internal_methods' => [
                    'Vérification de la permission users.deactivate',
                    'Désactivation du compte (active = false)',
                    'Révocation de tous les tokens Sanctum',
                ],
            ],

            'related_services' => [
                'HasApiTokens::tokens()->delete()',
            ],

            'tables' => [
                'users',
                'personal_access_tokens',
            ],

            'effects' => [
                'Le compte est définitivement désactivé.',
                'Tous les tokens Sanctum de l’utilisateur sont révoqués immédiatement.',
                'L’utilisateur ne peut plus se connecter.',
                'Cette action est irréversible.',
            ],
        ],

        // _________________________________________________________________________________________PREVIEWS
        'reservations.preview' => [
            'title' => 'Calcul du preview de réservation',
            'category' => 'Réservations',
            'scenario' => null,
            'goal' => 'Calculer en temps réel la faisabilit’d’une réservation sans rien écrire en base — distance ORS, fenêtre d’occupation, conflits, disponibilité.',

            'route' => [
                'demo' => null,
                'api' => 'POST /api/reservations/preview',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'ReservationController',
                'method' => 'preview',
            ],

            'request' => [
                'organization_id',
                'pickup_location_id',
                'destination_location_id',
                'trip_date',
                'departure_time',
                'return_time',
                'nb_adults',
                'nb_children',
                'activity_type',
            ],

            'service' => [
                'class' => 'ReservationPreviewService',
                'method' => 'preview',
                'internal_methods' => [
                    'Calcul de la distance via OpenRouteService (ou fallback haversine)',
                    'Calcul de la fenêtre d’occupation : départ - pickup_buffer → retour + post_return_buffer',
                    'detectDerogationWarnings() : DEPARTURE_BEFORE_ALLOWED_TIME, RETURN_AFTER_ALLOWED_TIME, DURATION_OVER_ALLOWED_LIMIT',
                    'detectConflicts() : SCHEDULE_OVERLAP, NO_DRIVER, NO_VEHICLE, QUOTA_EXCEEDED...',
                    'PlanningAvailabilityService : calcul du prochain créneau disponible',
                    'Détermination du statut prédit (en_attente / approuve / en_conflit)',
                ],
            ],

            'related_services' => [
                'OpenRouteService',
                'ReservationOccupationService',
                'PlanningAvailabilityService',
                'ReservationConflictService',
            ],

            'tables' => [
                'reservations (lecture)',
                'unavailabilities (lecture)',
                'drivers (lecture)',
                'vehicles (lecture)',
                'organizations (lecture)',
            ],

            'effects' => [
                'Aucune écriture en base — action 100 % lecture.',
                'La réponse contient distance_km, estimated_duration_min, estimated_occupation (fenêtre bus).',
                'conflicts[] liste les conflits bloquants détectés.',
                'warnings[] liste les dérogations horaires (non bloquantes, soumises au collège).',
                'availability indique si le bus est libre + le prochain créneau disponible si non.',
                'status prédit le statut que la réservation aurait si créée maintenant.',
                'Appelé à chaque changement de formulaire (debounced 300 ms) — sans jamais modifier la DB.',
            ],
        ],

        'recurrences.preview' => [
            'title' => 'Calcul du preview de récurrence',
            'category' => 'Réservations',
            'scenario' => null,
            'goal' => 'Calculer les occurrences valides d’une récurrence, les dates ignorées et les conflits potentiels sans créer aucune réservation.',

            'route' => [
                'demo' => null,
                'api' => 'POST /api/recurrences/preview',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'RecurrenceController',
                'method' => 'preview',
            ],

            'request' => [
                'organization_id',
                'pickup_location_id',
                'destination_location_id',
                'departure_time',
                'return_time',
                'frequency (hebdomadaire|bihebdomadaire|mensuel)',
                'days_of_week',
                'start_date',
                'end_date',
            ],

            'service' => [
                'class' => 'RecurrenceService',
                'method' => 'preview',
                'internal_methods' => [
                    'Génération de toutes les occurrences selon fréquence et jours de semaine',
                    'Filtrage des dates non ouvrables',
                    'Pour chaque date valide : appel ReservationPreviewService::preview()',
                    'Regroupement : valid_dates, skipped_dates (avec motif), conflicts_by_date',
                ],
            ],

            'related_services' => [
                'ReservationPreviewService',
                'ReservationOccupationService',
            ],

            'tables' => [
                'reservations (lecture)',
                'unavailabilities (lecture)',
            ],

            'effects' => [
                'Aucune écriture en base.',
                'valid_dates : liste des dates où la réservation est possible.',
                'skipped_dates : dates ignorées avec le motif (jour férié, hors période, etc.).',
                'conflicts_by_date : dates avec conflits détectés.',
                'Appelé en temps réel à chaque modification du formulaire de récurrence.',
            ],
        ],

        // _________________________________________________________________________________________ORGANISATIONS (MUTATIONS)
        'organizations.store' => [
            'title' => 'Création directe d’une organisation',
            'category' => 'Organisations',
            'scenario' => 'admin',
            'goal' => 'Créer une organisation directement sans passer par une demande d’enregistrement. Réservé aux agents internes.',

            'route' => [
                'demo' => null,
                'api' => 'POST /api/organizations',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'OrganizationController',
                'method' => 'store',
            ],

            'request' => [
                'name',
                'type',
                'official_email',
                'phone_contact',
                'name_contact',
            ],

            'service' => [
                'class' => 'Organization',
                'method' => 'create',
                'internal_methods' => [
                    'Validation des données',
                    'Création de l’organisation active immédiatement',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'organizations',
            ],

            'effects' => [
                'L’organisation est créée et active immédiatement.',
                'Aucun score de confiance calculé (pas de demande préalable).',
                'Différent de requests.organizations.approve : ici l’agent crée directement sans processus de validation.',
                'L’organisation peut immédiatement faire des réservations.',
            ],
        ],

        'organizations.ban' => [
            'title' => 'Bannissement d’une organisation',
            'category' => 'Organisations',
            'scenario' => 'admin',
            'goal' => 'Interdire à une organisation de soumettre de nouvelles réservations, avec un motif obligatoire.',

            'route' => [
                'demo' => null,
                'api' => 'POST /api/organizations/{organization}/ban',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'OrganizationController',
                'method' => 'ban',
            ],

            'request' => [
                'reason',
            ],

            'service' => [
                'class' => 'Organization',
                'method' => 'update',
                'internal_methods' => [
                    'Validation du motif',
                    'Vérification de la permission organizations.ban',
                    'is_banned = true, banned_reason enregistré',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'organizations',
            ],

            'effects' => [
                'L’organisation est marquée comme bannie.',
                'Le motif du ban est conservé.',
                'L’organisation ne peut plus créer de réservations.',
                'Les réservations existantes ne sont pas annulées automatiquement.',
            ],
        ],

        'organizations.unban' => [
            'title' => 'Levée du bannissement',
            'category' => 'Organisations',
            'scenario' => 'admin',
            'goal' => 'Réactiver une organisation précédemment bannie.',

            'route' => [
                'demo' => null,
                'api' => 'POST /api/organizations/{organization}/unban',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'OrganizationController',
                'method' => 'unban',
            ],

            'request' => null,

            'service' => [
                'class' => 'Organization',
                'method' => 'update',
                'internal_methods' => [
                    'Vérification de la permission organizations.ban',
                    'is_banned = false, banned_reason réinitialisé',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'organizations',
            ],

            'effects' => [
                'L’organisation est réactivée.',
                'Elle peut à nouveau soumettre des réservations.',
            ],
        ],

        // _________________________________________________________________________________________EMAIL VERIFICATION
        'auth.email_resend' => [
            'title' => 'Renvoi de l’email de vérification',
            'category' => 'Authentification',
            'scenario' => null,
            'goal' => 'Renvoyer l’email de vérification à un utilisateur dont le lien a expiré ou qui ne l’a pas reçu.',

            'route' => [
                'demo' => null,
                'api' => 'POST /api/email/verify/resend',
            ],

            'demo_controller' => null,

            'api_controller' => [
                'class' => 'EmailVerificationController',
                'method' => 'resend',
            ],

            'request' => null,

            'service' => [
                'class' => 'User',
                'method' => 'sendEmailVerificationNotification',
                'internal_methods' => [
                    'Vérification que l’email n’est pas déjà vérifié',
                    'Génération d’une URL signée temporaire',
                    'Envoi de l’email de vérification via Mailtrap',
                    'Throttle : 6 tentatives par minute',
                ],
            ],

            'related_services' => [
                'VerifyEmail (notification Laravel)',
                'Mailtrap SMTP',
            ],

            'tables' => [
                'users',
                'jobs',
            ],

            'effects' => [
                'Un nouvel email de vérification est envoyé.',
                'L’URL signée dans l’email est valable le temps configuré dans Laravel.',
                'Si l’email est déjà vérifié, la requête est ignorée.',
                'Throttlé à 6 requêtes par minute par IP.',
            ],
        ],

    ],

];

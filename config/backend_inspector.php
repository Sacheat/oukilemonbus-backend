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
                'reservation_assignments',
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
                'reservation_status_histories',
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
                'api' => 'POST /api/reservations/{reservation}/council-decision',
            ],

            'demo_controller' => [
                'class' => 'DemoReservationController',
                'method' => 'councilDecision',
            ],

            'api_controller' => [
                'class' => 'CouncilDecisionController',
                'method' => 'store',
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
                'reservation_status_histories',
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
                'api' => 'POST /api/notifications/read-all',
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
                'api' => null,
            ],

            'demo_controller' => [
                'class' => 'DemoRequestController',
                'method' => 'index',
            ],

            'api_controller' => null,
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
                'organization_user',
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
                'organization_user',
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
                'api' => 'POST /api/documents/route-sheets',
            ],

            'demo_controller' => [
                'class' => 'DemoDocumentController',
                'method' => 'generateRouteSheet',
            ],

            'api_controller' => [
                'class' => 'DocumentController',
                'method' => 'generateRouteSheet',
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
                'user_organizations',
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

    ],

];

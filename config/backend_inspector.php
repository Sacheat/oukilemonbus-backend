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

        // _________________________________________________________________________________________RÉSERVATIONS
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

        'reservations.auto_assign' => [
            'title' => 'Affectation automatique d’une réservation',
            'category' => 'Réservations',
            'scenario' => 'admin',
            'goal' => 'Tenter d’assigner automatiquement un chauffeur et un véhicule disponibles à une réservation.',

            'route' => [
                'demo' => 'POST /demo/reservations/{reservation}/auto-assign',
                'api' => null,
            ],

            'demo_controller' => [
                'class' => 'DemoReservationController',
                'method' => 'autoAssign',
            ],

            'api_controller' => [
                'class' => 'ReservationAssignmentController',
                'method' => 'autoAssign',
            ],

            'request' => null,

            'service' => [
                'class' => 'ReservationAssignmentService',
                'method' => 'autoAssign',
                'internal_methods' => [
                    'Recherche des chauffeurs disponibles',
                    'Recherche des véhicules disponibles',
                    'Vérification des conflits actifs',
                    'Création ou mise à jour de l’affectation',
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
                'notification_deliveries',
                'notifications',
                'domain_events',
            ],

            'effects' => [
                'Le backend cherche un chauffeur disponible.',
                'Le backend cherche un véhicule disponible.',
                'La réservation peut recevoir une affectation automatique.',
                'Un historique d’affectation peut être créé.',
                'Une notification peut être envoyée.',
            ],
        ],

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

        // _________________________________________________________________________________________DOCUMENTS

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

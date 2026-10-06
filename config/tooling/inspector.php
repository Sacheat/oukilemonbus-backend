<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backend Inspector (module Tooling)
    |--------------------------------------------------------------------------
    |
    | Outil d'administration : enregistre en base chaque requête API mutante
    | (POST/PUT/PATCH/DELETE) avec son acteur, son statut HTTP, un payload
    | expurgé et la clé de flow métier correspondante.
    | Consultable via /api/admin/tooling/inspector/* (permission tooling.access).
    |
    | Désactivé par défaut : BACKEND_INSPECTOR_ENABLED=true pour l'activer.
    |
    */

    'enabled' => (bool) env('BACKEND_INSPECTOR_ENABLED', false),

    // Nombre de jours de conservation des entrées (purge quotidienne planifiée).
    'retention_days' => (int) env('BACKEND_INSPECTOR_RETENTION_DAYS', 14),

    // Méthodes HTTP enregistrées.
    'methods' => ['POST', 'PUT', 'PATCH', 'DELETE'],

    // Noms de routes jamais enregistrés (wildcards acceptés).
    'ignored_routes' => [
        'api.admin.tooling.*',
        // Appelée en direct pendant la saisie (debounce) : ne pas saturer le journal.
        'api.reservations.check-trip',
    ],

    // Toute clé de payload contenant l'un de ces fragments est masquée (insensible à la casse).
    'redacted_keys' => [
        'password',
        'token',
        'secret',
        'signature',
        'api_key',
        'authorization',
    ],

    // Taille maximale (octets, JSON encodé) du payload stocké ; au-delà il est tronqué.
    'max_payload_bytes' => 16384,

    // Fuseau des filtres de date des outils (from, to, before) : une date seule désigne un jour à Bruxelles.
    'local_timezone' => 'Europe/Brussels',

    /*
    |--------------------------------------------------------------------------
    | Route map — nom de route API → clé de flow
    |--------------------------------------------------------------------------
    |
    | Les routes absentes de cette table sont tout de même enregistrées,
    | mais sans flow associé (flow_key = null).
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

        // Ajouts 2026-10 : chaque route mutante de l'API a son flow (test InspectorRouteMapTest)
        'api.auth.forgot-password'                => 'auth.forgot_password',
        'api.auth.reset-password'                 => 'auth.reset_password',
        'api.notifications.mark-many-read'        => 'notifications.read_many',
        'api.notifications.mark-by-filter-read'   => 'notifications.read_by_filter',
        'api.users.store'                         => 'admin.users.store',
        'api.users.update'                        => 'users.update',
        'api.users.update-password'               => 'users.update_password',
        'api.users.update-roles'                  => 'admin.users.update_roles',
        'api.users.send-password-reset-link'      => 'admin.users.send_password_reset_link',
        'api.locations.update'                    => 'locations.update',
        'api.municipalities.store'                => 'admin.municipalities.store',
        'api.municipalities.update'               => 'admin.municipalities.update',
        'api.municipalities.sync-localities'      => 'admin.municipalities.sync_localities',
        'api.municipality-localities.store'       => 'admin.municipality_localities.store',
        'api.municipality-localities.update'      => 'admin.municipality_localities.update',
        'api.municipality-localities.deactivate'  => 'admin.municipality_localities.deactivate',
        'api.organizations.update'                => 'organizations.update',
        'api.organizations.members.update'        => 'organizations.members.update',
        'api.organizations.members.destroy'       => 'organizations.members.destroy',
        'api.organizations.leave'                 => 'organizations.leave',
        'api.organizations.make-default'          => 'organizations.make_default',
        'api.planning.exports.request'            => 'planning.exports.request',
        'api.recurrences.cancel'                  => 'recurrences.cancel',
        'api.reservations.trip-report.update'     => 'reservations.trip_report',
        'api.reservations.trip-events.store'      => 'reservations.trip_events.record',
        'api.reservations.trip-events.destroy-last' => 'reservations.trip_events.cancel_last',
        'api.driver-alerts.store'                 => 'driver_alerts.create',
        'api.driver-alerts.position'              => 'driver_alerts.position',
        'api.driver-alerts.take'                  => 'driver_alerts.take',
        'api.driver-alerts.close'                 => 'driver_alerts.close',
        'api.council-decisions.generate-document' => 'documents.deliberations.generate',

    ],

    'flows' => [

        // _________________________________________________________________________________________PLANNING
        'planning.index' => [
            'title' => 'Consultation du planning',
            'category' => 'Planning',
            'goal' => 'Afficher les réservations planifiées sur une période donnée.',

            'route' => [
                'api' => 'GET /api/planning',
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
        // _________________________________________________________________________________________RÉSERVATIONS
        // INDEX
        'reservations.index' => [
            'title' => 'Consultation des réservations',
            'category' => 'Réservations',
            'goal' => 'Lister les réservations visibles par l’utilisateur avec leurs affectations et conflits.',

            'route' => [
                'api' => 'GET /api/reservations',
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
        // STORE
        'reservations.store' => [
            'title' => 'Création d’une réservation',
            'category' => 'Réservations',
            'goal' => 'Créer une nouvelle réservation à partir des données du formulaire.',

            'route' => [
                'api' => 'POST /api/reservations',
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
        // UPDATE
        'reservations.update' => [
            'title' => 'Modification d’une réservation',
            'category' => 'Réservations',
            'goal' => 'Mettre à jour les informations principales d’une réservation existante.',

            'route' => [
                'api' => 'PATCH /api/reservations/{reservation}',
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
            'goal' => 'Faire passer une réservation vers le statut approuvé.',

            'route' => [
                'api' => 'PATCH /api/reservations/{reservation}/approve',
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
            'goal' => 'Tenter d’assigner automatiquement un chauffeur et un véhicule disponibles à une réservation, en évitant les conflits d’occupation.',

            'route' => [
                'api' => null,
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
            'goal' => 'Assigner manuellement un chauffeur et/ou un véhicule à une réservation.',

            'route' => [
                'api' => 'PATCH /api/reservations/{reservation}/assign',
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
            'goal' => 'Encoder ou mettre à jour la décision du collège communal liée à une réservation.',

            'route' => [
                'api' => 'POST /api/council-decisions ou PATCH /api/council-decisions/{councilDecision}',
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
            'goal' => 'Faire passer une réservation vers le statut confirmé.',

            'route' => [
                'api' => 'PATCH /api/reservations/{reservation}/confirm',
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
            'goal' => 'Refuser une réservation avec un motif explicite.',

            'route' => [
                'api' => 'PATCH /api/reservations/{reservation}/reject',
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
            'goal' => 'Annuler une réservation.',

            'route' => [
                'api' => 'PATCH /api/reservations/{reservation}/cancel',
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
            'goal' => 'Afficher les notifications in-app de l’acteur et la santé du système de notifications.',

            'route' => [
                'api' => 'GET /api/notifications',
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
            'goal' => 'Marquer toutes les notifications non lues de l’acteur courant comme lues.',

            'route' => [
                'api' => 'PATCH /api/notifications/read-all',
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
            'goal' => 'Marquer une notification précise de l’acteur courant comme lue.',

            'route' => [
                'api' => 'PATCH /api/notifications/{notificationId}/read',
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
        'requests.organizations.store' => [
            'title' => 'Création d’une demande d’organisation',
            'category' => 'Demandes',
            'goal' => 'Créer une demande de création d’organisation.',

            'route' => [
                'api' => 'POST /api/org-registration-requests',
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
            'goal' => 'Approuver une demande de création d’organisation et créer l’organisation correspondante.',

            'route' => [
                'api' => 'PATCH /api/org-registration-requests/{orgRegistrationRequest}/approve',
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
                'La note de confiance est figée au score du moment (calculé avant la création de l’organisation).',
            ],
        ],

        'requests.organizations.reject' => [
            'title' => 'Refus d’une demande d’organisation',
            'category' => 'Demandes',
            'goal' => 'Refuser une demande de création d’organisation avec un motif explicite.',

            'route' => [
                'api' => 'PATCH /api/org-registration-requests/{orgRegistrationRequest}/reject',
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
                'La note de confiance est figée au score du moment, avant la suppression du justificatif.',
            ],
        ],

        'requests.user_organizations.store' => [
            'title' => 'Création d’une demande de rattachement',
            'category' => 'Demandes',
            'goal' => 'Créer une demande de rattachement d’un utilisateur à une organisation existante.',

            'route' => [
                'api' => 'POST /api/user-org-requests',
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
            'goal' => 'Approuver une demande de rattachement et associer l’utilisateur à l’organisation.',

            'route' => [
                'api' => 'PATCH /api/user-org-requests/{userOrgRequest}/approve',
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
                'La note de confiance est figée : celle de l’agent, sinon le score du moment.',
            ],
        ],

        'requests.user_organizations.reject' => [
            'title' => 'Refus d’une demande de rattachement',
            'category' => 'Demandes',
            'goal' => 'Refuser une demande de rattachement avec un motif explicite.',

            'route' => [
                'api' => 'PATCH /api/user-org-requests/{userOrgRequest}/reject',
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
                'La note de confiance est figée : celle de l’agent, sinon le score du moment, avant la suppression du justificatif.',
            ],
        ],

        // _________________________________________________________________________________________DOCUMENTS

        'documents.index' => [
            'title' => 'Consultation des documents',
            'category' => 'Documents',
            'goal' => 'Afficher les documents générés et les formulaires de génération de feuilles de route.',

            'route' => [
                'api' => 'GET /api/documents',
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
            'goal' => 'Générer un document de feuille de route à partir des réservations planifiées.',

            'route' => [
                'api' => 'POST /api/documents/route-sheets/generate',
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
            'goal' => 'Télécharger un document généré par le backend.',

            'route' => [
                'api' => 'GET /api/documents/{document}/download',
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
            'goal' => 'Afficher les organisations visibles par l’acteur courant.',

            'route' => [
                'api' => 'GET /api/organizations',
            ],


            'api_controller' => [
                'class' => 'OrganizationController',
                'method' => 'index',
            ],

            'request' => null,

            'service' => [
                'class' => 'OrganizationService',
                'method' => 'index',
                'internal_methods' => [
                    'Identification de l’acteur courant',
                    'Filtrage des organisations visibles',
                    'Préparation de la liste renvoyée au client',
                ],
            ],

            'related_services' => [
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
                'Les informations sont renvoyées au client via une API Resource.',
            ],
        ],

        // _________________________________________________________________________________________AUTH
        'auth.register' => [
            'title' => 'Inscription d’un utilisateur',
            'category' => 'Authentification',
            'goal' => 'Créer un nouveau compte utilisateur avec envoi automatique d’un email de vérification.',

            'route' => [
                'api' => 'POST /api/auth/register',
            ],


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
                'Serveur SMTP (Mailpit en dev)',
            ],

            'tables' => [
                'users',
                'model_has_roles',
                'jobs',
            ],

            'effects' => [
                'Un compte utilisateur est créé avec email non vérifié.',
                'Un email de vérification est envoyé (en dev, visible dans Mailpit).',
                'L’utilisateur ne peut pas accéder aux routes métier avant vérification.',
                'Le rôle user est attribué par défaut.',
            ],
        ],

        'auth.login' => [
            'title' => 'Connexion d’un utilisateur',
            'category' => 'Authentification',
            'goal' => 'Authentifier un utilisateur via Sanctum en mode cookie stateful.',

            'route' => [
                'api' => 'POST /api/auth/login',
            ],


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
            'goal' => 'Terminer la session et invalider les cookies d’authentification.',

            'route' => [
                'api' => 'POST /api/auth/logout',
            ],


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

        // _________________________________________________________________________________________RÉSERVATIONS (ACTIONS COMPLÉMENTAIRES)
        'reservations.retry_assign' => [
            'title' => 'Tentative de réassignation automatique',
            'category' => 'Réservations',
            'goal' => 'Retenter l’assignation automatique d’un chauffeur et d’un véhicule disponibles sur une réservation en conflit.',

            'route' => [
                'api' => 'PATCH /api/reservations/{reservation}/retry-assign',
            ],


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
            'goal' => 'Mettre à jour le nombre de passagers et enregistrer l’horodatage de confirmation. Si le trajet est aujourd’hui, la feuille de route est régénérée.',

            'route' => [
                'api' => 'PATCH /api/reservations/{reservation}/confirm-passengers',
            ],


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
            'goal' => 'Marquer un conflit spécifique comme résolu et déclencher la transition automatique de statut si plus aucun conflit actif.',

            'route' => [
                'api' => 'PATCH /api/reservations/{reservation}/conflicts/{conflict}/resolve',
            ],


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
            'goal' => 'Créer un ensemble de réservations périodiques pour une école communale sur une plage de dates avec une fréquence définie.',

            'route' => [
                'api' => 'POST /api/recurrences',
            ],


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
            'goal' => 'Attacher un document justificatif (PDF, JPG, PNG) à une demande de création d’organisation.',

            'route' => [
                'api' => 'POST /api/org-registration-requests/{orgRegistrationRequest}/proof',
            ],


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
                'La note de confiance de la demande est recalculée et enregistrée.',
            ],
        ],

        'requests.organizations.proof_delete' => [
            'title' => 'Suppression du justificatif d’organisation',
            'category' => 'Demandes',
            'goal' => 'Supprimer le document justificatif d’une demande d’organisation en attente.',

            'route' => [
                'api' => 'DELETE /api/org-registration-requests/{orgRegistrationRequest}/proof',
            ],


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
                'La note de confiance de la demande est recalculée et enregistrée.',
            ],
        ],

        'requests.user_organizations.proof_upload' => [
            'title' => 'Upload du justificatif d’adhésion',
            'category' => 'Demandes',
            'goal' => 'Attacher un document justificatif à une demande d’adhésion à une organisation.',

            'route' => [
                'api' => 'POST /api/user-org-requests/{userOrgRequest}/proof',
            ],


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
                'La note de confiance de la demande est recalculée et enregistrée.',
            ],
        ],

        'requests.user_organizations.proof_delete' => [
            'title' => 'Suppression du justificatif d’adhésion',
            'category' => 'Demandes',
            'goal' => 'Supprimer le justificatif d’une demande d’adhésion en attente.',

            'route' => [
                'api' => 'DELETE /api/user-org-requests/{userOrgRequest}/proof',
            ],


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
                'La note de confiance de la demande est recalculée et enregistrée.',
            ],
        ],

        // _________________________________________________________________________________________NOTIFICATIONS — PRÉFÉRENCES
        'notifications.preferences.update' => [
            'title' => 'Mise à jour des préférences de notification',
            'category' => 'Notifications',
            'goal' => 'Modifier les canaux actifs (database, mail) par catégorie et la fréquence des digests.',

            'route' => [
                'api' => 'PATCH /api/notifications/preferences',
            ],


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
            'goal' => 'Enregistrer un lieu depuis une suggestion ORS, ou retourner le lieu existant si le place_id est déjà connu (déduplication).',

            'route' => [
                'api' => 'POST /api/locations',
            ],


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
            'goal' => 'Enregistrer une période d’indisponibilité pour un chauffeur ou un véhicule et recalculer l’impact sur les réservations existantes.',

            'route' => [
                'api' => 'POST /api/unavailabilities',
            ],


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
            'goal' => 'Mettre à jour les dates ou le motif d’une indisponibilité et recalculer son impact.',

            'route' => [
                'api' => 'PATCH /api/unavailabilities/{unavailability}',
            ],


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
            'goal' => 'Annuler une indisponibilité et tenter de réassigner automatiquement les réservations précédemment impactées.',

            'route' => [
                'api' => 'PATCH /api/unavailabilities/{unavailability}/cancel',
            ],


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
            'goal' => 'Enregistrer un utilisateur existant en tant que chauffeur actif.',

            'route' => [
                'api' => 'POST /api/drivers',
            ],


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
            'goal' => 'Mettre à jour le numéro de permis ou la date d’expiration d’un chauffeur.',

            'route' => [
                'api' => 'PATCH /api/drivers/{driver}',
            ],


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
            'goal' => 'Rendre un chauffeur inactif à nouveau disponible pour les assignations.',

            'route' => [
                'api' => 'PATCH /api/drivers/{driver}/activate',
            ],


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
            'goal' => 'Rendre un chauffeur inactif pour qu’il ne soit plus proposé dans les assignations.',

            'route' => [
                'api' => 'PATCH /api/drivers/{driver}/deactivate',
            ],


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
            'goal' => 'Ajouter un véhicule à la flotte avec son immatriculation et sa capacité.',

            'route' => [
                'api' => 'POST /api/vehicles',
            ],


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
            'goal' => 'Mettre à jour l’immatriculation ou la capacité d’un véhicule.',

            'route' => [
                'api' => 'PATCH /api/vehicles/{vehicle}',
            ],


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
            'goal' => 'Rendre un véhicule inactif à nouveau disponible pour les assignations.',

            'route' => [
                'api' => 'PATCH /api/vehicles/{vehicle}/activate',
            ],


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
            'goal' => 'Retirer un véhicule du service actif.',

            'route' => [
                'api' => 'PATCH /api/vehicles/{vehicle}/deactivate',
            ],


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
            'goal' => 'Modifier le statut actif/bloqué d’un utilisateur sans supprimer ses tokens.',

            'route' => [
                'api' => 'PATCH /api/users/{user}/status',
            ],


            'api_controller' => [
                'class' => 'UserController',
                'method' => 'updateStatus',
            ],

            'request' => [
                'active (bool)',
            ],

            'service' => [
                'class' => 'UserService',
                'method' => 'updateStatus',
                'internal_methods' => [
                    'Vérification de la permission users.update-status',
                    'Refus de désactiver le dernier administrateur actif (422 sur active)',
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
            'goal' => 'Désactiver définitivement un compte utilisateur et révoquer tous ses tokens d’accès.',

            'route' => [
                'api' => 'PATCH /api/users/{user}/deactivate',
            ],


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
                    'Refus de désactiver le dernier administrateur actif (422)',
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
            'goal' => 'Calculer en temps réel la faisabilit’d’une réservation sans rien écrire en base — distance ORS, fenêtre d’occupation, conflits, disponibilité.',

            'route' => [
                'api' => 'POST /api/reservations/preview',
            ],


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
            'goal' => 'Calculer les occurrences valides d’une récurrence, les dates ignorées et les conflits potentiels sans créer aucune réservation.',

            'route' => [
                'api' => 'POST /api/recurrences/preview',
            ],


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
            'goal' => 'Créer une organisation directement sans passer par une demande d’enregistrement. Réservé aux agents internes.',

            'route' => [
                'api' => 'POST /api/organizations',
            ],


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
            'goal' => 'Interdire à une organisation de soumettre de nouvelles réservations, avec un motif obligatoire.',

            'route' => [
                'api' => 'POST /api/organizations/{organization}/ban',
            ],


            'api_controller' => [
                'class' => 'OrganizationController',
                'method' => 'ban',
            ],

            'request' => [
                'reason',
                'cancel_upcoming',
            ],

            'service' => [
                'class' => 'OrganizationService',
                'method' => 'ban',
                'internal_methods' => [
                    'Validation du motif',
                    'Vérification de la permission organizations.ban',
                    'is_banned = true, banned_reason enregistré (422 si déjà bannie)',
                    'DomainEventLogger : organization.banned',
                    'cancel_upcoming : ReservationService::cancel pour chaque réservation à venir',
                    'Notification des membres (organization.banned)',
                ],
            ],

            'related_services' => [
                'ReservationService',
                'NotificationDispatchService',
            ],

            'tables' => [
                'organizations',
                'reservations',
                'domain_events',
                'notifications',
            ],

            'effects' => [
                'L’organisation est marquée comme bannie, avec le motif.',
                'L’organisation ne peut plus créer de réservations.',
                'Au choix de l’admin, ses réservations à venir sont annulées (motif, demandeur et chauffeur prévenus) ; celles qui ne peuvent pas l’être sont listées.',
                'Les membres sont prévenus.',
            ],
        ],

        'organizations.unban' => [
            'title' => 'Levée du bannissement',
            'category' => 'Organisations',
            'goal' => 'Réactiver une organisation précédemment bannie.',

            'route' => [
                'api' => 'POST /api/organizations/{organization}/unban',
            ],


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
            'goal' => 'Renvoyer l’email de vérification à un utilisateur dont le lien a expiré ou qui ne l’a pas reçu.',

            'route' => [
                'api' => 'POST /api/email/verify/resend',
            ],


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
                    'Envoi de l’email de vérification (file d’attente)',
                    'Throttle : 6 tentatives par minute',
                ],
            ],

            'related_services' => [
                'VerifyEmail (notification Laravel)',
                'Serveur SMTP (Mailpit en dev)',
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


        // _________________________________________________________________________________________ROUTES MUTANTES AJOUTÉES (2026-10)
        'auth.forgot_password' => [
            'title' => 'Demande de lien « Mot de passe oublié »',
            'category' => 'Authentification',
            'goal' => 'Envoyer un lien de réinitialisation du mot de passe à l’adresse indiquée.',

            'route' => [
                'api' => 'POST /api/auth/forgot-password',
            ],

            'api_controller' => [
                'class' => 'PasswordResetController',
                'method' => 'forgot',
            ],

            'request' => [
                'class' => 'ForgotPasswordRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'PasswordResetService',
                'method' => 'sendResetLink',
                'internal_methods' => [
                    'Password::broker()->sendResetLink()',
                    'Statut ignoré (adresse inconnue, envoi trop rapproché)',
                    'Throttle : 6 requêtes par minute',
                ],
            ],

            'related_services' => [
                'QueuedResetPassword (notification en file)',
            ],

            'tables' => [
                'password_reset_tokens',
                'jobs',
            ],

            'effects' => [
                'Un jeton de réinitialisation valable 60 minutes est enregistré.',
                'Le lien {FRONTEND_URL}/reset-password?token=…&email=… part en file d’attente.',
                'La réponse est identique que l’adresse existe ou non.',
            ],
        ],

        'auth.reset_password' => [
            'title' => 'Réinitialisation du mot de passe',
            'category' => 'Authentification',
            'goal' => 'Choisir un nouveau mot de passe avec le jeton reçu par email.',

            'route' => [
                'api' => 'POST /api/auth/reset-password',
            ],

            'api_controller' => [
                'class' => 'PasswordResetController',
                'method' => 'reset',
            ],

            'request' => [
                'class' => 'ResetPasswordRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'PasswordResetService',
                'method' => 'reset',
                'internal_methods' => [
                    'Password::broker()->reset()',
                    'Nouveau mot de passe et remember_token régénéré',
                    'Suppression des tokens API et des sessions',
                    'Événement PasswordReset',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'users',
                'password_reset_tokens',
                'personal_access_tokens',
                'sessions',
            ],

            'effects' => [
                'Le mot de passe est remplacé.',
                'Toutes les sessions et tous les tokens API de l’utilisateur sont fermés.',
                'Jeton invalide ou expiré : 422 sur email.',
            ],
        ],

        'notifications.read_many' => [
            'title' => 'Lecture de plusieurs notifications',
            'category' => 'Notifications',
            'goal' => 'Marquer comme lues les notifications sélectionnées.',

            'route' => [
                'api' => 'PATCH /api/notifications/read',
            ],

            'api_controller' => [
                'class' => 'NotificationController',
                'method' => 'markManyAsRead',
            ],

            'request' => [
                'ids (string[], requis)',
            ],

            'service' => [
                'class' => 'DatabaseNotification',
                'method' => 'update',
                'internal_methods' => [
                    'Restriction aux notifications non lues de l’utilisateur connecté',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'notifications',
            ],

            'effects' => [
                'read_at est renseigné sur les notifications indiquées.',
                'Les identifiants d’un autre utilisateur sont ignorés.',
            ],
        ],

        'notifications.read_by_filter' => [
            'title' => 'Lecture des notifications filtrées',
            'category' => 'Notifications',
            'goal' => 'Marquer comme lues toutes les notifications qui correspondent aux filtres de la liste.',

            'route' => [
                'api' => 'PATCH /api/notifications/read-by-filter',
            ],

            'api_controller' => [
                'class' => 'NotificationController',
                'method' => 'markByFilterAsRead',
            ],

            'request' => [
                'Mêmes filtres que GET /api/notifications',
            ],

            'service' => [
                'class' => 'DatabaseNotification',
                'method' => 'update',
                'internal_methods' => [
                    'validateFilters()',
                    'filteredQuery()',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'notifications',
            ],

            'effects' => [
                'read_at est renseigné sur les notifications non lues filtrées.',
                'La réponse renvoie le nombre marqué (marked_as_read).',
            ],
        ],

        'admin.users.store' => [
            'title' => 'Création d’un compte par un admin',
            'category' => 'Administration',
            'goal' => 'Créer un compte hors inscription et lui envoyer l’email de vérification.',

            'route' => [
                'api' => 'POST /api/users',
            ],

            'api_controller' => [
                'class' => 'UserController',
                'method' => 'store',
            ],

            'request' => [
                'class' => 'StoreUserRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'UserService',
                'method' => 'create',
                'internal_methods' => [
                    'Vérification de la permission users.create',
                    'Création du compte et rôle user',
                    'Email de vérification en file d’attente (une panne n’empêche pas la création)',
                ],
            ],

            'related_services' => [
                'QueuedVerifyEmail (notification en file)',
            ],

            'tables' => [
                'users',
                'model_has_roles',
                'jobs',
            ],

            'effects' => [
                'Le compte est créé avec le rôle user.',
                'L’email de vérification part vers {FRONTEND_URL}/verify-email.',
            ],
        ],

        'users.update' => [
            'title' => 'Modification d’un profil',
            'category' => 'Utilisateurs',
            'goal' => 'Modifier le nom, l’email ou le téléphone d’un compte.',

            'route' => [
                'api' => 'PATCH /api/users/{user}',
            ],

            'api_controller' => [
                'class' => 'UserController',
                'method' => 'update',
            ],

            'request' => [
                'class' => 'UpdateUserRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'UserService',
                'method' => 'update',
                'internal_methods' => [
                    'Policy : son propre profil, ou rôle interne',
                    'Email normalisé en minuscules et unique',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'users',
            ],

            'effects' => [
                'Les champs envoyés sont mis à jour.',
            ],
        ],

        'users.update_password' => [
            'title' => 'Changement de mot de passe',
            'category' => 'Utilisateurs',
            'goal' => 'Changer son propre mot de passe en gardant la session courante.',

            'route' => [
                'api' => 'PATCH /api/users/{user}/password',
            ],

            'api_controller' => [
                'class' => 'UserController',
                'method' => 'updatePassword',
            ],

            'request' => [
                'class' => 'UpdatePasswordRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'UserService',
                'method' => 'updatePassword',
                'internal_methods' => [
                    'Policy : soi uniquement',
                    'Vérification du mot de passe actuel',
                    'closeOtherSessions()',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'users',
                'personal_access_tokens',
                'sessions',
            ],

            'effects' => [
                'Le mot de passe est remplacé.',
                'La session courante reste ouverte ; les autres sessions et les tokens API sont fermés.',
            ],
        ],

        'admin.users.update_roles' => [
            'title' => 'Attribution des rôles globaux',
            'category' => 'Administration',
            'goal' => 'Remplacer les rôles globaux d’un compte.',

            'route' => [
                'api' => 'PATCH /api/users/{user}/roles',
            ],

            'api_controller' => [
                'class' => 'UserController',
                'method' => 'updateRoles',
            ],

            'request' => [
                'class' => 'UpdateUserRolesRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'UserService',
                'method' => 'updateRoles',
                'internal_methods' => [
                    'Vérification de la permission users.manage-roles (admin)',
                    'Refus de retirer son propre rôle admin',
                    'Refus de retirer admin au dernier administrateur actif (verrou sur les admins actifs)',
                    'syncRoles()',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'users',
                'model_has_roles',
            ],

            'effects' => [
                'Les rôles du compte sont remplacés par ceux envoyés.',
                'La plateforme garde toujours au moins un administrateur actif.',
            ],
        ],

        'admin.users.send_password_reset_link' => [
            'title' => 'Envoi d’un lien de réinitialisation par un admin',
            'category' => 'Administration',
            'goal' => 'Envoyer à un compte le lien de « Mot de passe oublié », sans fixer de mot de passe.',

            'route' => [
                'api' => 'POST /api/users/{user}/password-reset-link',
            ],

            'api_controller' => [
                'class' => 'UserController',
                'method' => 'sendPasswordResetLink',
            ],

            'request' => null,

            'service' => [
                'class' => 'UserService',
                'method' => 'sendPasswordResetLink',
                'internal_methods' => [
                    'Policy sendPasswordReset (permission users.send-password-reset, admin)',
                    'Refus si le compte est désactivé (422)',
                    'Password::broker()->sendResetLink()',
                    'Refus si un lien a été envoyé il y a moins de 60 s (422)',
                ],
            ],

            'related_services' => [
                'QueuedResetPassword (notification en file)',
            ],

            'tables' => [
                'password_reset_tokens',
                'jobs',
            ],

            'effects' => [
                'Un jeton valable 60 minutes est enregistré et le lien part en file d’attente.',
                'Aucun jeton ni mot de passe n’apparaît dans la réponse (202).',
            ],
        ],

        'locations.update' => [
            'title' => 'Modification d’un lieu',
            'category' => 'Lieux',
            'goal' => 'Corriger l’adresse d’un lieu enregistré.',

            'route' => [
                'api' => 'PATCH /api/locations/{location}',
            ],

            'api_controller' => [
                'class' => 'LocationController',
                'method' => 'update',
            ],

            'request' => [
                'class' => 'UpdateLocationRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'LocationService',
                'method' => 'update',
                'internal_methods' => [
                    'Nouveau géocodage si formatted_address change',
                ],
            ],

            'related_services' => [
                'OpenRouteService (géocodage)',
            ],

            'tables' => [
                'locations',
            ],

            'effects' => [
                'Le lieu est mis à jour ; ses coordonnées sont recalculées si l’adresse change.',
            ],
        ],

        'admin.municipalities.store' => [
            'title' => 'Création d’une commune',
            'category' => 'Administration',
            'goal' => 'Créer une commune et, au besoin, ses localités.',

            'route' => [
                'api' => 'POST /api/municipalities',
            ],

            'api_controller' => [
                'class' => 'MunicipalityController',
                'method' => 'store',
            ],

            'request' => [
                'class' => 'StoreMunicipalityRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'MunicipalitySettingsService',
                'method' => 'create',
                'internal_methods' => [
                    'Transaction',
                    'activate() si is_active : une seule commune active',
                    'addLocality() pour chaque localité',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'municipalities',
                'municipality_localities',
            ],

            'effects' => [
                'La commune est créée.',
                'Si elle est active, toutes les autres communes sont désactivées.',
            ],
        ],

        'admin.municipalities.update' => [
            'title' => 'Modification d’une commune',
            'category' => 'Administration',
            'goal' => 'Modifier une commune, son dépôt et ses distances maximales.',

            'route' => [
                'api' => 'PATCH /api/municipalities/{municipality}',
            ],

            'api_controller' => [
                'class' => 'MunicipalityController',
                'method' => 'update',
            ],

            'request' => [
                'class' => 'UpdateMunicipalityRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'MunicipalitySettingsService',
                'method' => 'update',
                'internal_methods' => [
                    'Transaction',
                    'activate() si is_active passe à true',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'municipalities',
            ],

            'effects' => [
                'Les champs envoyés sont mis à jour.',
                'Le dépôt et les limites de distance s’appliquent aux réservations suivantes.',
            ],
        ],

        'admin.municipalities.sync_localities' => [
            'title' => 'Synchronisation des localités',
            'category' => 'Administration',
            'goal' => 'Récupérer la commune et ses localités depuis GeoNames.',

            'route' => [
                'api' => 'POST /api/municipalities/{municipality}/sync-localities',
            ],

            'api_controller' => [
                'class' => 'MunicipalityController',
                'method' => 'syncLocalities',
            ],

            'request' => [
                'class' => 'SyncMunicipalityLocalitiesRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'MunicipalitySettingsService',
                'method' => 'syncFromExternalProvider',
                'internal_methods' => [
                    'Recherche de la commune chez GeoNames',
                    'Mise à jour du centre (latitude, longitude) et de synced_at',
                    'addLocality() pour chaque localité reçue',
                ],
            ],

            'related_services' => [
                'GeoNamesMunicipalityProvider',
                'GeoNamesClient',
            ],

            'tables' => [
                'municipalities',
                'municipality_localities',
            ],

            'effects' => [
                'Les localités GeoNames sont créées ou mises à jour.',
                'Le centre de la commune sert de repli quand aucun dépôt n’est défini.',
            ],
        ],

        'admin.municipality_localities.store' => [
            'title' => 'Ajout d’une localité',
            'category' => 'Administration',
            'goal' => 'Ajouter une localité à une commune.',

            'route' => [
                'api' => 'POST /api/municipalities/{municipality}/localities',
            ],

            'api_controller' => [
                'class' => 'MunicipalityLocalityController',
                'method' => 'store',
            ],

            'request' => [
                'class' => 'StoreMunicipalityLocalityRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'MunicipalitySettingsService',
                'method' => 'addLocality',
                'internal_methods' => [
                    'updateOrCreate sur le nom normalisé',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'municipality_localities',
            ],

            'effects' => [
                'La localité est créée, ou mise à jour si le même nom existe déjà.',
            ],
        ],

        'admin.municipality_localities.update' => [
            'title' => 'Modification d’une localité',
            'category' => 'Administration',
            'goal' => 'Renommer ou modifier une localité.',

            'route' => [
                'api' => 'PATCH /api/municipality-localities/{municipalityLocality}',
            ],

            'api_controller' => [
                'class' => 'MunicipalityLocalityController',
                'method' => 'update',
            ],

            'request' => [
                'class' => 'UpdateMunicipalityLocalityRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'MunicipalitySettingsService',
                'method' => 'updateLocality',
                'internal_methods' => [
                    'Refus d’un nom déjà utilisé dans la commune (422)',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'municipality_localities',
            ],

            'effects' => [
                'La localité est mise à jour.',
            ],
        ],

        'admin.municipality_localities.deactivate' => [
            'title' => 'Désactivation d’une localité',
            'category' => 'Administration',
            'goal' => 'Retirer une localité des choix proposés.',

            'route' => [
                'api' => 'PATCH /api/municipality-localities/{municipalityLocality}/deactivate',
            ],

            'api_controller' => [
                'class' => 'MunicipalityLocalityController',
                'method' => 'deactivate',
            ],

            'request' => null,

            'service' => [
                'class' => 'MunicipalitySettingsService',
                'method' => 'deactivateLocality',
                'internal_methods' => [],
            ],

            'related_services' => [],

            'tables' => [
                'municipality_localities',
            ],

            'effects' => [
                'is_active passe à false.',
            ],
        ],

        'organizations.update' => [
            'title' => 'Modification d’une organisation',
            'category' => 'Organisations',
            'goal' => 'Modifier les informations d’une organisation.',

            'route' => [
                'api' => 'PATCH /api/organizations/{organization}',
            ],

            'api_controller' => [
                'class' => 'OrganizationController',
                'method' => 'update',
            ],

            'request' => [
                'class' => 'UpdateOrganizationRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'OrganizationService',
                'method' => 'update',
                'internal_methods' => [
                    'prepareData() : is_priority déduit du type s’il est omis',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'organizations',
            ],

            'effects' => [
                'Les champs envoyés sont mis à jour.',
            ],
        ],

        'organizations.members.update' => [
            'title' => 'Changement de relation d’un membre',
            'category' => 'Organisations',
            'goal' => 'Changer la relation d’un membre avec l’organisation (ex. le nommer responsable).',

            'route' => [
                'api' => 'PATCH /api/organizations/{organization}/members/{user}',
            ],

            'api_controller' => [
                'class' => 'OrganizationMemberController',
                'method' => 'update',
            ],

            'request' => [
                'class' => 'UpdateOrganizationMemberRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'OrganizationMembershipService',
                'method' => 'updateMember',
                'internal_methods' => [
                    'Refus de rétrograder le dernier responsable (422)',
                    'DomainEventLogger : organization.member_updated',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'user_organization',
                'domain_events',
            ],

            'effects' => [
                'relationship_type du membre est mis à jour.',
            ],
        ],

        'organizations.members.destroy' => [
            'title' => 'Retrait d’un membre',
            'category' => 'Organisations',
            'goal' => 'Retirer un membre d’une organisation.',

            'route' => [
                'api' => 'DELETE /api/organizations/{organization}/members/{user}',
            ],

            'api_controller' => [
                'class' => 'OrganizationMemberController',
                'method' => 'destroy',
            ],

            'request' => null,

            'service' => [
                'class' => 'OrganizationMembershipService',
                'method' => 'removeMember',
                'internal_methods' => [
                    'Policy manageMembers (responsable ou agent)',
                    'Refus de retirer le dernier responsable (422)',
                    'Nouvelle organisation par défaut si besoin',
                    'DomainEventLogger : organization.member_removed',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'user_organization',
                'domain_events',
            ],

            'effects' => [
                'Le membre est détaché de l’organisation.',
            ],
        ],

        'organizations.leave' => [
            'title' => 'Départ d’une organisation',
            'category' => 'Organisations',
            'goal' => 'Quitter une organisation dont on est membre.',

            'route' => [
                'api' => 'POST /api/organizations/{organization}/leave',
            ],

            'api_controller' => [
                'class' => 'OrganizationMemberController',
                'method' => 'leave',
            ],

            'request' => null,

            'service' => [
                'class' => 'OrganizationMembershipService',
                'method' => 'leave',
                'internal_methods' => [
                    'Refus pour le dernier responsable (422)',
                    'Nouvelle organisation par défaut si besoin',
                    'DomainEventLogger : organization.member_left',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'user_organization',
                'domain_events',
            ],

            'effects' => [
                'L’utilisateur n’est plus membre ; la plus ancienne organisation restante devient celle par défaut.',
            ],
        ],

        'organizations.make_default' => [
            'title' => 'Choix de l’organisation par défaut',
            'category' => 'Organisations',
            'goal' => 'Faire d’une organisation son organisation par défaut.',

            'route' => [
                'api' => 'PATCH /api/organizations/{organization}/default',
            ],

            'api_controller' => [
                'class' => 'OrganizationMemberController',
                'method' => 'makeDefault',
            ],

            'request' => null,

            'service' => [
                'class' => 'OrganizationMembershipService',
                'method' => 'setDefault',
                'internal_methods' => [
                    'Réservé aux membres',
                    'Transaction : une seule organisation par défaut',
                ],
            ],

            'related_services' => [],

            'tables' => [
                'user_organization',
            ],

            'effects' => [
                'is_default passe à true pour cette organisation et à false pour les autres.',
                'La réponse a la forme de /auth/me.',
            ],
        ],

        'planning.exports.request' => [
            'title' => 'Demande d’export du planning',
            'category' => 'Planning',
            'goal' => 'Générer l’export Excel du planning en tâche de fond.',

            'route' => [
                'api' => 'POST /api/planning/exports',
            ],

            'api_controller' => [
                'class' => 'PlanningController',
                'method' => 'requestExport',
            ],

            'request' => [
                'class' => 'PlanningIndexRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'PlanningAsyncExportService',
                'method' => 'requestExport',
                'internal_methods' => [
                    'Enregistrement de la demande (pending)',
                    'GeneratePlanningExportJob::dispatch()',
                ],
            ],

            'related_services' => [
                'GeneratePlanningExportJob',
            ],

            'tables' => [
                'planning_export_requests',
                'jobs',
                'documents',
            ],

            'effects' => [
                'La demande est créée (202) ; le job produit ensuite un document planning.',
                'Suivi avec GET /api/planning/exports/{id}.',
            ],
        ],

        'recurrences.cancel' => [
            'title' => 'Annulation d’une récurrence',
            'category' => 'Réservations',
            'goal' => 'Annuler toutes les occurrences encore actives d’une série.',

            'route' => [
                'api' => 'PATCH /api/recurrences/{recurrence}/cancel',
            ],

            'api_controller' => [
                'class' => 'RecurrenceController',
                'method' => 'cancel',
            ],

            'request' => [
                'class' => 'CancelRecurrenceRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'RecurrenceService',
                'method' => 'cancelRemaining',
                'internal_methods' => [
                    'Externe : membre de l’organisation de la série (403 sinon)',
                    'ReservationService::cancel() pour chaque occurrence active',
                    'DomainEventLogger : recurrence.cancelled',
                ],
            ],

            'related_services' => [
                'ReservationService',
            ],

            'tables' => [
                'reservations',
                'reservation_status_history',
                'domain_events',
            ],

            'effects' => [
                'Les occurrences en_attente, approuve et confirme passent en annule.',
                'Une occurrence qui ne peut pas être annulée est journalisée et ignorée.',
            ],
        ],

        'reservations.trip_report' => [
            'title' => 'Compte rendu d’un trajet',
            'category' => 'Réservations',
            'goal' => 'Déclarer ou corriger le compte rendu d’un trajet (réalisé ou non).',

            'route' => [
                'api' => 'PUT /api/reservations/{reservation}/trip-report',
            ],

            'api_controller' => [
                'class' => 'TripReportController',
                'method' => 'update',
            ],

            'request' => [
                'class' => 'SaveTripReportRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'TripReportService',
                'method' => 'save',
                'internal_methods' => [
                    'Réservation confirme requise (422)',
                    'Chauffeur affecté : de l’heure de départ au lendemain 12:00 ; agent ou admin : sans limite',
                    'DomainEventLogger : trip_report.created / trip_report.updated',
                    'Notification des agents si non réalisé',
                ],
            ],

            'related_services' => [
                'NotificationDispatchService',
            ],

            'tables' => [
                'trip_reports',
                'domain_events',
                'notifications',
            ],

            'effects' => [
                'Le compte rendu est créé (201) ou mis à jour (200).',
                'La réservation est ensuite figée : plus de modification, réaffectation ni confirmation des passagers.',
            ],
        ],

        'reservations.trip_events.record' => [
            'title' => 'Étape d’un trajet (Ma journée)',
            'category' => 'Réservations',
            'goal' => 'Enregistrer le départ, l’arrivée, le départ du retour ou l’arrivée au lieu de départ d’un trajet.',

            'route' => [
                'api' => 'POST /api/reservations/{reservation}/trip-events',
            ],

            'api_controller' => [
                'class' => 'TripProgressController',
                'method' => 'store',
            ],

            'request' => [
                'class' => 'RecordTripEventRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'TripProgressService',
                'method' => 'record',
                'internal_methods' => [
                    'Réservation confirme et pas déclarée non réalisée (422)',
                    'Chauffeur affecté ou trip-progress.manage (403)',
                    'Étapes dans l’ordre (422) ; étape déjà enregistrée : 200 sans changement',
                    'Chauffeur : premier départ dès N minutes avant l’heure prévue (config driver_journey) jusqu’à la fin du jour ; autres étapes jusqu’au lendemain 12:00',
                    'DomainEventLogger : trip_event.recorded',
                    'Dernière arrivée : compte rendu réalisé via TripReportService::save',
                ],
            ],

            'related_services' => [
                'TripReportService',
                'DomainEventLogger',
            ],

            'tables' => [
                'trip_events',
                'trip_reports',
                'domain_events',
            ],

            'effects' => [
                'L’étape est enregistrée avec sa source (chauffeur ou agent) et la position du téléphone.',
                'La dernière arrivée crée le compte rendu « réalisé » s’il n’existe pas encore.',
            ],
        ],

        'reservations.trip_events.cancel_last' => [
            'title' => 'Annuler la dernière étape d’un trajet',
            'category' => 'Réservations',
            'goal' => 'Effacer une étape appuyée par erreur (par exemple un « Départ » trop tôt) pour que le chauffeur réappuie.',

            'route' => [
                'api' => 'DELETE /api/reservations/{reservation}/trip-events/last',
            ],

            'api_controller' => [
                'class' => 'TripProgressController',
                'method' => 'destroyLast',
            ],

            'service' => [
                'class' => 'TripProgressService',
                'method' => 'cancelLastStep',
                'internal_methods' => [
                    'Agent / admin, ou chauffeur affecté (403 sinon)',
                    'Aucune étape (422) ; compte rendu déjà créé (422 : corriger le compte rendu)',
                    'Chauffeur : son propre appui seulement, dans les 2 min (config driver_journey.driver_undo_seconds) ; 422 sinon',
                    'Verrou sur la réservation, seule la dernière étape est supprimée',
                    'DomainEventLogger : trip_event.cancelled (étape annulée dans old_values)',
                ],
            ],

            'related_services' => [
                'DomainEventLogger',
            ],

            'tables' => [
                'trip_events',
                'domain_events',
            ],

            'effects' => [
                'La dernière étape est supprimée ; l’avancement revient à l’étape précédente.',
                'Le chauffeur peut appuyer à nouveau sur le bouton.',
            ],
        ],

        'driver_alerts.create' => [
            'title' => 'Demande d’aide d’un chauffeur',
            'category' => 'Chauffeurs',
            'goal' => 'Prévenir les agents d’une panne ou d’un problème, avec la position du téléphone.',

            'route' => [
                'api' => 'POST /api/driver-alerts',
            ],

            'api_controller' => [
                'class' => 'DriverAlertController',
                'method' => 'store',
            ],

            'request' => [
                'class' => 'StoreDriverAlertRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'DriverAlertService',
                'method' => 'create',
                'internal_methods' => [
                    'Profil chauffeur et driver-alerts.create (403)',
                    'Trajet facultatif, mais une mission du chauffeur (422)',
                    'Sans position : dernier lieu connu d’après l’avancement du trajet',
                    'Idempotency-Key : un double appui ne crée pas deux alertes',
                    'DomainEventLogger : driver_alert.created',
                ],
            ],

            'related_services' => [
                'NotificationDispatchService',
                'NotificationRecipientResolver',
                'DomainEventLogger',
            ],

            'tables' => [
                'driver_alerts',
                'notification_deliveries',
                'notifications',
                'domain_events',
            ],

            'effects' => [
                'Agents et admins prévenus (cloche et email urgents, lien vers la carte).',
                'Le contact de garde de la commune active reçoit un email s’il est renseigné.',
            ],
        ],

        'driver_alerts.position' => [
            'title' => 'Position d’une demande d’aide',
            'category' => 'Chauffeurs',
            'goal' => 'Renvoyer sa position sur une demande d’aide encore ouverte.',

            'route' => [
                'api' => 'PATCH /api/driver-alerts/{driverAlert}/position',
            ],

            'api_controller' => [
                'class' => 'DriverAlertController',
                'method' => 'updatePosition',
            ],

            'request' => [
                'class' => 'UpdateDriverAlertPositionRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'DriverAlertService',
                'method' => 'updatePosition',
                'internal_methods' => [
                    'Seul le chauffeur de l’alerte (403)',
                    'Alerte non clôturée (422)',
                    'DomainEventLogger : driver_alert.position_updated',
                ],
            ],

            'related_services' => [
                'DomainEventLogger',
            ],

            'tables' => [
                'driver_alerts',
                'domain_events',
            ],

            'effects' => [
                'La position remplace la précédente ; le compteur de renvois augmente.',
            ],
        ],

        'driver_alerts.take' => [
            'title' => 'Prise en charge d’une demande d’aide',
            'category' => 'Chauffeurs',
            'goal' => 'Un agent indique qu’il s’occupe d’une demande d’aide.',

            'route' => [
                'api' => 'POST /api/driver-alerts/{driverAlert}/take',
            ],

            'api_controller' => [
                'class' => 'DriverAlertController',
                'method' => 'take',
            ],

            'request' => [
                'class' => 'Request',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'DriverAlertService',
                'method' => 'take',
                'internal_methods' => [
                    'driver-alerts.manage',
                    'Alerte ouverte seulement (422)',
                    'DomainEventLogger : driver_alert.taken',
                ],
            ],

            'related_services' => [
                'NotificationDispatchService',
                'DomainEventLogger',
            ],

            'tables' => [
                'driver_alerts',
                'notification_deliveries',
                'notifications',
                'domain_events',
            ],

            'effects' => [
                'L’alerte passe en prise_en_charge ; le chauffeur est prévenu.',
            ],
        ],

        'driver_alerts.close' => [
            'title' => 'Clôture d’une demande d’aide',
            'category' => 'Chauffeurs',
            'goal' => 'Clôturer une demande d’aide avec ce qui a été fait.',

            'route' => [
                'api' => 'POST /api/driver-alerts/{driverAlert}/close',
            ],

            'api_controller' => [
                'class' => 'DriverAlertController',
                'method' => 'close',
            ],

            'request' => [
                'class' => 'CloseDriverAlertRequest',
                'methods' => [
                    'authorize',
                    'rules',
                ],
            ],

            'service' => [
                'class' => 'DriverAlertService',
                'method' => 'close',
                'internal_methods' => [
                    'driver-alerts.manage',
                    'Note obligatoire',
                    'Alerte non clôturée (422)',
                    'DomainEventLogger : driver_alert.closed',
                ],
            ],

            'related_services' => [
                'DomainEventLogger',
            ],

            'tables' => [
                'driver_alerts',
                'domain_events',
            ],

            'effects' => [
                'L’alerte passe en cloturee avec la note et l’auteur.',
            ],
        ],

        'documents.deliberations.generate' => [
            'title' => 'Génération de la délibération',
            'category' => 'Documents',
            'goal' => '(Re)générer le PDF de délibération d’une décision du Collège.',

            'route' => [
                'api' => 'POST /api/council-decisions/{councilDecision}/generate-document',
            ],

            'api_controller' => [
                'class' => 'CouncilDecisionController',
                'method' => 'generateDocument',
            ],

            'request' => null,

            'service' => [
                'class' => 'DeliberationDocumentService',
                'method' => 'generateFor',
                'internal_methods' => [
                    'Policy generate (documents.generate)',
                    'Décision approuve ou refuse requise (422)',
                    'Génération du PDF et nouvelle version',
                ],
            ],

            'related_services' => [
                'DocumentService',
            ],

            'tables' => [
                'documents',
            ],

            'effects' => [
                'Un nouveau document deliberation (version suivante) est enregistré et stocké.',
            ],
        ],

    ],

];

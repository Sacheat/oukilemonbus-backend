<?php

/*
|--------------------------------------------------------------------------
| RGPD : durées de conservation
|--------------------------------------------------------------------------
|
| Appliquées chaque nuit par `privacy:prune`. Les valeurs sont à valider avec le DPO de la commune.
| 0 désactive la purge correspondante.
|
*/

return [
    'retention' => [
        // Positions GPS des chauffeurs (étapes de trajet, demandes d'aide clôturées) : coordonnées effacées,
        // l'état de la position (envoyée, refusée, indisponible) reste.
        'driver_positions_days' => (int) env('PRIVACY_RETENTION_DRIVER_POSITIONS_DAYS', 90),

        // Réponses gardées pour rejouer une requête (en-tête Idempotency-Key) : un client réessaie dans la minute.
        'idempotency_keys_days' => (int) env('PRIVACY_RETENTION_IDEMPOTENCY_KEYS_DAYS', 7),

        // Journal des accès aux documents (IP, navigateur) : durée habituelle d'un journal de sécurité.
        'document_access_logs_days' => (int) env('PRIVACY_RETENTION_DOCUMENT_ACCESS_LOGS_DAYS', 365),

        // Journal d'audit (domain_events) : aligné sur la conservation légale des feuilles de route (3 ans).
        'domain_events_days' => (int) env('PRIVACY_RETENTION_DOMAIN_EVENTS_DAYS', 1095),

        // Comptes sans activité (dernière activité, sinon création) : anonymisés après ce délai…
        'inactive_accounts_days' => (int) env('PRIVACY_RETENTION_INACTIVE_ACCOUNTS_DAYS', 1095),
        // … et prévenus par email ce nombre de jours avant. L'anonymisation n'a lieu qu'après l'avertissement.
        'inactive_accounts_warning_days' => (int) env('PRIVACY_INACTIVE_ACCOUNTS_WARNING_DAYS', 30),
    ],
];

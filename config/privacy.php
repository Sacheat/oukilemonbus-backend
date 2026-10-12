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
    ],
];

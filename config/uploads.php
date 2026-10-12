<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Pièces jointes justificatives (ProofDocumentService)
    |--------------------------------------------------------------------------
    */
    'proof_documents' => [
        // Taille maximale en octets (défaut : 5 Mo)
        'max_size_bytes' => env('PROOF_DOCUMENT_MAX_SIZE_BYTES', 5 * 1024 * 1024),

        // Extensions autorisées (minuscules)
        'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png'],

        // Types MIME autorisés (vérifiés via finfo sur le contenu réel)
        'allowed_mimes' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
        ],

        'formats_label' => 'PDF, JPG et PNG',
    ],

    /*
    |--------------------------------------------------------------------------
    | Liste des passagers d'un trajet (PassengerListService, carnet de bord)
    |--------------------------------------------------------------------------
    | Même pipeline de sécurité que les justificatifs ; une école la tient souvent dans un tableur.
    */
    'passenger_lists' => [
        'max_size_bytes' => env('PASSENGER_LIST_MAX_SIZE_BYTES', 5 * 1024 * 1024),

        'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'xlsx', 'csv'],

        'allowed_mimes' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'text/plain',
            'text/csv',
        ],

        'formats_label' => 'PDF, Excel (.xlsx), CSV, JPG et PNG',

        // Conservation : jusqu'au 31 décembre de la Ne année qui suit celle du trajet (arrêté royal du 22 mai 2014,
        // art. 2 § 4, pour les feuilles de route : 3 ans), puis suppression par documents:purge-passenger-lists
        'retention_years' => (int) env('PASSENGER_LIST_RETENTION_YEARS', 3),
    ],
];
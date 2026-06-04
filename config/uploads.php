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
    ],
];
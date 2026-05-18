<?php

return [
    'transporter' => [
        'name' => env('ROUTE_SHEET_TRANSPORTER_NAME', 'Commune de Dinant - Service mobilite'),
        'identifier' => env('ROUTE_SHEET_TRANSPORTER_IDENTIFIER', 'Commune de Dinant'),
        'signature_label' => env('ROUTE_SHEET_TRANSPORTER_SIGNATURE_LABEL', 'Signature du transporteur'),
        'license_number' => env('ROUTE_SHEET_LICENSE_NUMBER', 'A completer'),
        'emergency_contact' => env('ROUTE_SHEET_EMERGENCY_CONTACT', null),
    ],

    'legal_retention_years' => 3,
];

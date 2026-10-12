<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The React frontend is a first-party SPA. Sanctum therefore needs
    | credentialed requests from explicit frontend origins.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:3000,http://127.0.0.1:3000'
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // Retry-After : délai d'une réponse 429, lu par la SPA pour rejouer une lecture au bon moment
    'exposed_headers' => ['Retry-After'],

    'max_age' => 0,

    'supports_credentials' => true,

];

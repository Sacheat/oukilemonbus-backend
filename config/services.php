<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openrouteservice' => [
        'key' => env('ORS_API_KEY'),
        'base_url' => env('ORS_BASE_URL', 'https://api.openrouteservice.org'),
    ],

    'best_address' => [
        'base_url' => env('BEST_ADDRESS_BASE_URL', 'https://public.fedservices.be/api/BeSt/v1/belgianAddress/v2'),
        'token_url' => env('BEST_ADDRESS_TOKEN_URL', 'https://public.fedservices.be/api/oauth2/token'),
        'client_id' => env('BEST_ADDRESS_CLIENT_ID'),
        'client_secret' => env('BEST_ADDRESS_CLIENT_SECRET'),
        'max_pages' => env('BEST_ADDRESS_MAX_PAGES', 25),
    ],

];

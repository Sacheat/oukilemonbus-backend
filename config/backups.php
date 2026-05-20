<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backup Storage
    |--------------------------------------------------------------------------
    |
    | Backups are stored outside the public directory by default. The path is
    | absolute so it can later point to a mounted disk or secured directory.
    |
    */

    'path' => env('BACKUP_PATH') ?: storage_path('app/private/backups'),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Backups older than this number of days are deleted after a successful run.
    |
    */

    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Included Paths
    |--------------------------------------------------------------------------
    */

    'paths' => [
        'private' => storage_path('app/private'),
        'public' => storage_path('app/public'),
        'logs' => storage_path('logs'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Exclusions
    |--------------------------------------------------------------------------
    |
    | Prevent recursive backups and skip framework cache/session noise.
    |
    */

    'exclude' => [
        storage_path('app/private/backups'),
        storage_path('framework/cache'),
        storage_path('framework/sessions'),
        storage_path('framework/testing'),
        storage_path('framework/views'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Future Encryption
    |--------------------------------------------------------------------------
    |
    | Encryption is intentionally disabled in V1. These keys reserve the shape
    | of the future configuration so the backup module can evolve cleanly.
    |
    */

    'encryption' => [
        'enabled' => (bool) env('BACKUP_ENCRYPTION_ENABLED', false),
        'key' => env('BACKUP_ENCRYPTION_KEY'),
    ],

];

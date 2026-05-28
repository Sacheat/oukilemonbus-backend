<?php

return [
    'last_minute_cancellation_hours' => (int) env('NOTIFICATIONS_LAST_MINUTE_CANCELLATION_HOURS', 24),

    'anti_spam' => [
        'cooldown_minutes' => (int) env('NOTIFICATIONS_COOLDOWN_MINUTES', 15),
    ],

    'mail' => [
        'max_attachment_bytes' => (int) env('NOTIFICATIONS_MAIL_MAX_ATTACHMENT_BYTES', 5 * 1024 * 1024),
        'signed_link_expiration_minutes' => (int) env('NOTIFICATIONS_SIGNED_LINK_EXPIRATION_MINUTES', 60 * 24),
    ],

    'retention' => [
        'in_app_days' => (int) env('NOTIFICATIONS_RETENTION_IN_APP_DAYS', 90),
        'delivery_logs_days' => (int) env('NOTIFICATIONS_RETENTION_DELIVERY_LOGS_DAYS', 180),
        'failed_delivery_logs_days' => (int) env('NOTIFICATIONS_RETENTION_FAILED_LOGS_DAYS', 365),
    ],
];


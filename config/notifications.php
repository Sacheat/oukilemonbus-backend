<?php

return [
    'last_minute_cancellation_hours' => (int) env('NOTIFICATIONS_LAST_MINUTE_CANCELLATION_HOURS', 24),

    'anti_spam' => [
        'cooldown_minutes' => (int) env('NOTIFICATIONS_COOLDOWN_MINUTES', 15),
    ],

    'digest' => [
        'hourly_minute' => (int) env('NOTIFICATIONS_DIGEST_HOURLY_MINUTE', 5),
        'daily_hour' => (int) env('NOTIFICATIONS_DIGEST_DAILY_HOUR', 8),
        'daily_minute' => (int) env('NOTIFICATIONS_DIGEST_DAILY_MINUTE', 0),
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

    'observability' => [
        'max_failed_deliveries_24h' => (int) env('NOTIFICATIONS_OBS_MAX_FAILED_DELIVERIES_24H', 20),
        'max_high_pending_over_5m' => (int) env('NOTIFICATIONS_OBS_MAX_HIGH_PENDING_OVER_5M', 0),
        'max_late_digests_over_30m' => (int) env('NOTIFICATIONS_OBS_MAX_LATE_DIGESTS_OVER_30M', 10),
    ],
];

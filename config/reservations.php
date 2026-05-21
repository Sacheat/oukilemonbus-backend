<?php

return [
    'max_distance_km' => 50,
    'duration_margin_min' => 15,
    'pickup_buffer_min' => 0,
    'dropoff_buffer_min' => 10,
    'safety_buffer_min' => 5,
    'non_school_min_departure_time' => '07:30',
    'non_school_max_return_time' => '16:00',
    'non_school_max_total_duration_min' => 300,
    'min_notice_days' => 15,
    'allowed_countries' => ['BE', 'Belgique', 'Belgium'],
    'annual_quota' => 5,
    'max_active_requests' => 2,
    'max_confirmed_reservations' => 2,
    'closed_dates' => [
        '2026-01-01',
        '2026-04-06',
        '2026-05-01',
        '2026-05-14',
        '2026-05-25',
        '2026-07-21',
        '2026-08-15',
        '2026-11-01',
        '2026-11-11',
        '2026-12-25',
    ],
];

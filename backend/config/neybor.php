<?php

return [
    'identity_disk' => env('NEYBOR_IDENTITY_DISK', 'local'),
    'matching' => [
        'offer_ttl_seconds' => (int) env('NEYBOR_MATCH_OFFER_TTL_SECONDS', 90),
        'max_distance_km' => (float) env('NEYBOR_MATCH_MAX_DISTANCE_KM', 50),
        'max_attempts' => (int) env('NEYBOR_MATCH_MAX_ATTEMPTS', 3),
        'service_compatibility' => env('NEYBOR_MATCH_SERVICE_COMPATIBILITY', 'category'),
        'offer_policy' => [
            'mode' => env('NEYBOR_MATCH_OFFER_MODE', 'sequential'),
            'batch_size' => (int) env('NEYBOR_MATCH_OFFER_BATCH_SIZE', 3),
        ],
        'ranking' => [
            'distance_weight' => (float) env('NEYBOR_MATCH_DISTANCE_WEIGHT', 0.35),
            'relevance_weight' => (float) env('NEYBOR_MATCH_RELEVANCE_WEIGHT', 0.30),
            'rating_weight' => (float) env('NEYBOR_MATCH_RATING_WEIGHT', 0.20),
            'availability_weight' => (float) env('NEYBOR_MATCH_AVAILABILITY_WEIGHT', 0.15),
            'availability_freshness_minutes' => (int) env('NEYBOR_MATCH_AVAILABILITY_FRESHNESS_MINUTES', 120),
        ],
    ],
];

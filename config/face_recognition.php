<?php

return [

    'service_url' => rtrim((string) env('FACE_AI_SERVICE_URL', 'http://127.0.0.1:8001'), '/'),

    'api_key' => (string) env('FACE_AI_API_KEY', ''),

    'similarity_threshold' => (float) env('FACE_SIMILARITY_THRESHOLD', 0.80),

    // Block same face on another account — defaults to login threshold if not set.
    'duplicate_threshold' => (float) env('FACE_DUPLICATE_THRESHOLD', env('FACE_SIMILARITY_THRESHOLD', 0.80)),

    'login_rate_limit' => (int) env('FACE_LOGIN_RATE_LIMIT', 5),

    'login_rate_decay_minutes' => (int) env('FACE_LOGIN_RATE_DECAY_MINUTES', 1),

];

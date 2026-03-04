<?php

return [
    '3q' => [
        'driver' => '3q',
        'api_token' => env('VIDIQ_API_TOKEN', ''),
        'project_id' => env('VIDIQ_PROJECT_ID', ''),
        'endpoint' => env('VIDIQ_API_ENDPOINT', 'https://sdn.3qsdn.com/api'),
        'timeout' => env('VIDIQ_API_TIMEOUT', 30),
    ],
];

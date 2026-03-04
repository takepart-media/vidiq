<?php

return [

    'embed_fallback_method' => env('VIDIQ_FALLBACK_METHOD', 'JavaScript'),
    /**
     * Cache configuration.
     */
    'cache' => [
        'ttl' => env('VIDIQ_CACHE_TTL', 3600), // 1 hour in seconds
        'prefix' => 'vidiq',
    ],

];

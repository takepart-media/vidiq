<?php

return [

    'embed_fallback_method' => env('VIDIQ_FALLBACK_METHOD', 'JavaScript'),

    /*
     * Cache configuration.
     *
     * The 3q.video catalogue is near-static, so vidiq caches the file listing
     * and embed codes aggressively and refreshes them out-of-band (nightly
     * schedule, CP utility, and stale-while-revalidate on read).
     */
    'cache' => [

        /*
         * The cache store vidiq uses. It is intentionally isolated from the
         * application's default store so that a global `cache:clear` (which many
         * apps run on every content save) does not wipe the video listing and
         * force a slow, blocking re-fetch from the 3q API on the next request.
         *
         * When set to "vidiq" (the default) and no such store is configured,
         * vidiq registers a dedicated file store for you. Point this at any
         * configured store (e.g. a separate redis database) to override.
         */
        'store' => env('VIDIQ_CACHE_STORE', 'vidiq'),

        /*
         * Cache the listing/embed data permanently (recommended). The data is
         * kept fresh by the nightly refresh, the CP utility, and the
         * stale-while-revalidate check below — not by expiry. Set to false to
         * fall back to TTL-based expiry.
         */
        'permanent' => env('VIDIQ_CACHE_PERMANENT', true),

        /*
         * TTL in seconds, used only when `permanent` is false.
         */
        'ttl' => env('VIDIQ_CACHE_TTL', 3600), // 1 hour

        /*
         * Stale-while-revalidate window in seconds. When cached data is older
         * than this, it is still served immediately while a background refresh
         * is dispatched (after the response, so the request never blocks). Set
         * to 0 to disable on-read revalidation and rely solely on the schedule.
         */
        'refresh_after' => env('VIDIQ_CACHE_REFRESH_AFTER', 21600), // 6 hours

        'prefix' => 'vidiq',

        /*
         * Number of 3q listing pages fetched concurrently when (re)building the
         * cache. The listing is paginated; fetching pages in parallel turns a
         * large catalogue from tens of seconds (serial) into a few seconds.
         */
        'fetch_concurrency' => env('VIDIQ_FETCH_CONCURRENCY', 6),
    ],

    /*
     * Background refresh schedule.
     */
    'schedule' => [
        /*
         * Time of day (24h "H:i") to refresh the cache nightly. Set to null or
         * false to disable the self-registered schedule (e.g. if you schedule
         * `vidiq:warm-cache` yourself).
         */
        'refresh_at' => env('VIDIQ_REFRESH_AT', '03:33'),
    ],

];

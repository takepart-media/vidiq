@props([
    'method' => null,
    'asset' => null
])

@php
    $method = $method ?? config('vidiq.embed_fallback_method', 'JavaScript');
    $embedCodes = $asset
        ? \Illuminate\Support\Facades\Storage::disk($asset->container()->diskHandle())->url($asset->path())
        : null;

    if (! $embedCodes) {
        return;
    }
@endphp

@if ($method === 'JavaScript')
    {{--
        3Q's JavaScript embed first loads the legacy `sdnplayer.js`, which defines an
        outdated global `js3q`. The playout script that follows clears it, injects
        `js3q.latest.js` and polls until `js3q` exists. With several videos on a page,
        the next embed's `sdnplayer.js` redefines the legacy `js3q` while earlier embeds
        are still polling, so they build their player with the wrong library and stay
        empty (grey box). This only shows while `js3q.latest.js` is not cached (it is
        large and cached for 10 minutes), hence "works after reload". The playout script
        loads everything it needs itself, so the legacy include is dropped.
    --}}
    {!! preg_replace(
        '#<script\b[^>]*\bsrc="[^"]*/player/js/sdnplayer\.js"[^>]*>\s*</script>#i',
        '',
        $embedCodes['JavaScript'] ?? ''
    ) !!}
@elseif ($method === 'iFrame')
    <iframe
        class="video-embed"
        src="{{ $embedCodes['PlayerURL'] }}"
        title="3Q Video Player"
        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
        allowfullscreen
    ></iframe>
@elseif ($method === 'PlayerURL')
    {{ $embedCodes['PlayerURL'] ?? '' }}
@endif



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
    {!! $embedCodes['JavaScript'] ?? '' !!}
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



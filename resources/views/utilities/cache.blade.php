@php use function Statamic\trans as __; @endphp

{{-- Statamic 6 renders utility views as a fragment: the HTML is compiled client-side
     as a Vue template, so globally registered `ui-*` components are available here. --}}
<div class="max-w-5xl 3xl:max-w-6xl mx-auto" data-max-width-wrapper>
    <ui-header title="{{ __('vidiQ Cache') }}" icon="movie-video-clip"></ui-header>

    <ui-card-panel heading="{{ __('Video Cache') }}">
        <div class="flex items-start justify-between gap-4">
            <div>
                <ui-description text="{{ __('Cached video listings and embed codes from the 3Q.video API.') }}"></ui-description>
                <div class="flex flex-wrap gap-2 mt-3">
                    <ui-badge prepend="{{ __('Videos') }}" text="{{ $videoCount }}"></ui-badge>
                    <ui-badge prepend="{{ __('Cache mode') }}" text="{{ $cacheMode }}"></ui-badge>
                    @if ($cacheTtl)
                        <ui-badge prepend="{{ __('TTL') }}" text="{{ $cacheTtl }}"></ui-badge>
                    @endif
                </div>
            </div>
            <div class="flex gap-2">
                <form method="POST" action="{{ cp_route('utilities.vidiq-cache.warm') }}">
                    @csrf
                    <ui-button type="submit" text="{{ __('Warm') }}"></ui-button>
                </form>
                <form method="POST" action="{{ cp_route('utilities.vidiq-cache.clear') }}">
                    @csrf
                    <ui-button type="submit" variant="danger" text="{{ __('Clear') }}"></ui-button>
                </form>
            </div>
        </div>
    </ui-card-panel>
</div>

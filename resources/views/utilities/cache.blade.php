@php use function Statamic\trans as __; @endphp

@extends('statamic::layout')
@section('title', __('vidiQ Cache'))

@section('content')

    <header class="mb-6">
        @include('statamic::partials.breadcrumb', [
            'url' => cp_route('utilities.index'),
            'title' => __('Utilities')
        ])
        <h1>{{ __('vidiQ Cache') }}</h1>
    </header>

    <div class="card p-0">
        <div class="p-4">
            <div class="flex justify-between items-center">
                <div class="rtl:pl-8 ltr:pr-8">
                    <h2 class="font-bold">{{ __('Video Cache') }}</h2>
                    <p class="text-gray dark:text-dark-150 text-sm my-2">
                        {{ __('Cached video listings and embed codes from the 3Q.video API.') }}
                    </p>
                </div>
                <div class="flex">
                    <form method="POST" action="{{ cp_route('utilities.vidiq-cache.warm') }}" class="rtl:ml-2 ltr:mr-2">
                        @csrf
                        <button class="btn">{{ __('Warm') }}</button>
                    </form>
                    <form method="POST" action="{{ cp_route('utilities.vidiq-cache.clear') }}">
                        @csrf
                        <button class="btn">{{ __('Clear') }}</button>
                    </form>
                </div>
            </div>
            <div class="text-sm text-gray dark:text-dark-150 flex flex-wrap">
                <div class="rtl:ml-4 ltr:mr-4 badge-pill-sm">
                    <span class="text-gray-800 dark:text-dark-150 font-medium">{{ __('Videos') }}:</span> {{ $videoCount }}
                </div>
                <div class="rtl:ml-4 ltr:mr-4 badge-pill-sm">
                    <span class="text-gray-800 dark:text-dark-150 font-medium">{{ __('Cache mode') }}:</span> {{ $cacheMode }}
                </div>
                @if ($cacheTtl)
                    <div class="rtl:ml-4 ltr:mr-4 badge-pill-sm">
                        <span class="text-gray-800 dark:text-dark-150 font-medium">{{ __('TTL') }}:</span> {{ $cacheTtl }}
                    </div>
                @endif
            </div>
        </div>
    </div>

@stop
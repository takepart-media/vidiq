<?php

namespace TakepartMedia\Vidiq\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\AssetContainer;

class AssetsController extends Controller
{
    /**
     * Return thumbnail URLs and release statuses for all assets in 3Q-backed containers.
     *
     * Response shape: `{ containerHandle: { path: { thumbnail_url, release_status } } }`
     *
     * @return JsonResponse<array<string, array<string, array{thumbnail_url: string, release_status: string|null}>>>
     */
    public function thumbnails(): JsonResponse
    {
        $result = [];

        AssetContainer::all()->each(function ($container) use (&$result) {
            $diskName = $container->diskHandle();

            if (config("filesystems.disks.{$diskName}.driver") !== '3q') {
                return;
            }

            $projectId = config("filesystems.disks.{$diskName}.project_id", '');
            $cacheKey = "vidiq.{$projectId}.listing";

            if (Cache::get($cacheKey) === null) {
                Storage::disk($diskName)->files('/');
            }

            $listing = Cache::get($cacheKey) ?? [];

            $result[$container->handle()] = collect($listing)
                ->map(fn (array $data) => [
                    'thumbnail_url' => $data['thumbnail_url'] ?? null,
                    'release_status' => $data['release_status'] ?? null,
                ])
                ->filter(fn (array $data) => $data['thumbnail_url'] !== null)
                ->all();
        });

        return response()->json($result);
    }

    /**
     * Return the 3Q player embed URL for a single asset path.
     * Called by the CP JS when the asset editor modal opens.
     *
     * @param  Request  $request  Must include query params: `path` and `container`.
     * @return JsonResponse<array{player_url: string|null}>
     */
    public function playerUrl(Request $request): JsonResponse
    {
        $path = $request->query('path', '');
        $containerHandle = $request->query('container', '');

        if (! $path || ! $containerHandle) {
            return response()->json(['player_url' => null]);
        }

        $container = AssetContainer::find($containerHandle);

        if (! $container) {
            return response()->json(['player_url' => null]);
        }

        try {
            $embedCodes = Storage::disk($container->diskHandle())->url($path) ?: null;
            $url = $embedCodes['PlayerURL'] ?? null;
        } catch (\Exception) {
            $url = null;
        }

        return response()->json(['player_url' => $url]);
    }
}

<?php

namespace TakepartMedia\Vidiq;

use Illuminate\Support\Facades\Storage;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Assets\AssetContainer;

class Vidiq
{
    /**
     * Determine whether the given asset lives on a 3q-backed disk.
     */
    public static function isVidiqAsset(Asset $asset): bool
    {
        return self::isVidiqContainer($asset->container());
    }

    /**
     * Determine whether the given container is backed by a 3q disk.
     */
    public static function isVidiqContainer(?AssetContainer $container): bool
    {
        $disk = $container?->diskHandle();

        return $disk !== null && config("filesystems.disks.{$disk}.driver") === '3q';
    }

    /**
     * Resolve the 3Q player URL for the given asset, or null when unavailable.
     */
    public static function playerUrl(Asset $asset): ?string
    {
        if (! self::isVidiqAsset($asset)) {
            return null;
        }

        try {
            $embedCodes = Storage::disk($asset->container()->diskHandle())->url($asset->path());
        } catch (\Exception) {
            return null;
        }

        return is_array($embedCodes) ? ($embedCodes['PlayerURL'] ?? null) : null;
    }
}

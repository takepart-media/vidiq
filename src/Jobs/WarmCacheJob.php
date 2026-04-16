<?php

namespace TakepartMedia\Vidiq\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use TakepartMedia\Vidiq\Adapters\ThreeQAdapter;

/**
 * Warm the vidiq video listing and embed-code caches in the background.
 */
class WarmCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $adapter = $this->resolveAdapter();

        if (! $adapter) {
            return;
        }

        $adapter->flushCache();
        $listing = $adapter->fetchListing(force: true);

        foreach (array_keys($listing) as $path) {
            $adapter->getUrl($path);
        }
    }

    /**
     * Resolve the ThreeQAdapter instance from the registered "3q" Storage disk.
     */
    private function resolveAdapter(): ?ThreeQAdapter
    {
        try {
            $disk = Storage::disk('3q');
        } catch (\Exception) {
            return null;
        }

        if (! $disk instanceof FilesystemAdapter) {
            return null;
        }

        $adapter = $disk->getAdapter();

        return $adapter instanceof ThreeQAdapter ? $adapter : null;
    }
}

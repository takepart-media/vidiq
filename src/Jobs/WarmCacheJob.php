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

        // Refresh in place (overwrite), without flushing first, so concurrent
        // requests keep serving the existing cache with no cold gap.
        $adapter->fetchListing(force: true);

        // Embed codes are stable per file, so only missing ones (new videos)
        // are fetched — refetching all of them every run is wasted work.
        $adapter->warmEmbedCodes();
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

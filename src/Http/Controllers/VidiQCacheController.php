<?php

namespace TakepartMedia\Vidiq\Http\Controllers;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use TakepartMedia\Vidiq\Adapters\ThreeQAdapter;
use TakepartMedia\Vidiq\Jobs\WarmCacheJob;

class VidiQCacheController extends Controller
{
    /**
     * Dispatch a background job to warm the vidiq caches.
     */
    public function warm(): RedirectResponse
    {
        WarmCacheJob::dispatch();

        session()->flash('success', __('vidiq cache warming started in the background.'));

        return redirect()->back();
    }

    /**
     * Flush all vidiq cache entries.
     */
    public function clear(): RedirectResponse
    {
        $adapter = $this->resolveAdapter();

        if (! $adapter) {
            session()->flash('error', __('Could not resolve the vidiq adapter.'));

            return redirect()->back();
        }

        $flushed = $adapter->flushCache();

        session()->flash('success', __("Flushed {$flushed} vidiq cache key(s)."));

        return redirect()->back();
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

<?php

namespace TakepartMedia\Vidiq\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use TakepartMedia\Vidiq\Adapters\ThreeQAdapter;

/**
 * Warm the 3q video listing and embed-code caches.
 *
 * Intended to run once during deployment so the first frontend request
 * does not trigger a full API round-trip to 3q.video.
 */
class WarmCacheCommand extends Command
{
    /** @var string */
    protected $signature = 'vidiq:warm-cache
                            {--embed : Also pre-fetch embed codes for every video}';

    /** @var string */
    protected $description = 'Warm the 3q video listing cache (and optionally embed codes)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $adapter = $this->resolveAdapter();

        if (! $adapter) {
            $this->error('Could not resolve the ThreeQAdapter from the "3q" disk.');

            return self::FAILURE;
        }

        $this->info('Flushing vidiq cache...');
        $flushed = $adapter->flushCache();
        $this->info("Flushed {$flushed} cache key(s).");

        $this->info('Fetching video listing from 3q API...');
        $listing = $adapter->fetchListing(force: true);
        $count = count($listing);
        $this->info("Cached {$count} video(s) in listing cache.");

        if ($this->option('embed') && $count > 0) {
            $this->info('Pre-fetching embed codes...');
            $bar = $this->output->createProgressBar($count);
            $bar->start();

            $errors = 0;
            foreach (array_keys($listing) as $path) {
                $result = $adapter->getUrl($path);
                if ($result === []) {
                    $errors++;
                }
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();

            if ($errors > 0) {
                $this->warn("{$errors} video(s) returned empty embed codes.");
            }

            $this->info('Embed codes cached.');
        }

        $this->info('Done.');

        return self::SUCCESS;
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

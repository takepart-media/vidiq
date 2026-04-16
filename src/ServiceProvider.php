<?php

namespace TakepartMedia\Vidiq;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use Statamic\Facades\Utility;
use Statamic\Providers\AddonServiceProvider;
use TakepartMedia\Vidiq\Adapters\ThreeQAdapter;
use TakepartMedia\Vidiq\Console\Commands\WarmCacheCommand;
use TakepartMedia\Vidiq\Http\Controllers\VidiQCacheController;

class ServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    protected $vite = [
        'input' => ['resources/js/addon_cp.js'],
        'publicDirectory' => 'resources/dist',
    ];

    protected $commands = [
        WarmCacheCommand::class,
    ];

    /**
     * Merge addon config files into the application's configuration.
     *
     * @throws BindingResolutionException
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vidiq.php', 'vidiq');
        $this->mergeConfigFrom(__DIR__.'/../config/vidiq-disk.php', 'vidiq-disk');

        $this->app->make('config')->set(
            'filesystems.disks.3q',
            $this->app->make('config')->get('vidiq-disk.3q', [])
        );
    }

    /**
     * Boot addon features: register the 3Q disk driver, views, and CP utility.
     */
    public function bootAddon(): void
    {
        $this->bootDiskDriver();
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'vidiq');
        $this->bootCacheUtility();
    }

    /**
     * Register the vidiq cache utility in the Statamic CP.
     */
    protected function bootCacheUtility(): void
    {
        Utility::extend(function () {
            Utility::register('vidiq-cache')
                ->title('vidiq Cache')
                ->navTitle('vidiq Cache')
                ->description(__('Manage the vidiq video listing and embed-code cache.'))
                ->icon('video')
                ->view('vidiq::utilities.cache', function () {
                    $adapter = $this->resolveAdapter();
                    $listing = $adapter ? Cache::get($adapter->cacheKey('listing'), []) : [];
                    $permanent = config('vidiq.cache.permanent', false);
                    $ttl = config('vidiq.cache.ttl', 3600);

                    return [
                        'videoCount' => count($listing),
                        'cacheMode' => $permanent ? __('Permanent') : __('TTL'),
                        'cacheTtl' => $permanent ? null : gmdate('H:i:s', $ttl),
                    ];
                })
                ->routes(function ($router) {
                    $router->post('warm', [VidiQCacheController::class, 'warm'])->name('warm');
                    $router->post('clear', [VidiQCacheController::class, 'clear'])->name('clear');
                });
        });
    }

    /**
     * Resolve the ThreeQAdapter instance from the registered "3q" Storage disk.
     */
    protected function resolveAdapter(): ?ThreeQAdapter
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

    /**
     * Register the custom '3q' Flysystem disk driver with Laravel's Storage facade.
     */
    protected function bootDiskDriver(): self
    {
        Storage::extend('3q', function (Application $app, array $config) {
            $adapter = new ThreeQAdapter(
                apiToken: $config['api_token'],
                projectId: $config['project_id'],
                apiEndpoint: $config['endpoint'] ?? 'https://sdn.3qsdn.com/api',
                timeout: $config['timeout'] ?? 30,
            );

            return new FilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config,
            );
        });

        return $this;
    }
}

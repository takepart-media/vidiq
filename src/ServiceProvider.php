<?php

namespace TakepartMedia\Vidiq;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use Statamic\Providers\AddonServiceProvider;
use TakepartMedia\Vidiq\Adapters\ThreeQAdapter;
use TakepartMedia\Vidiq\Console\Commands\WarmCacheCommand;

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
     * Boot addon features: register the 3Q disk driver and load Blade views.
     */
    public function bootAddon(): void
    {
        $this->bootDiskDriver();
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'vidiq');
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

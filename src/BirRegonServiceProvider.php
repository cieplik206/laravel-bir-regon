<?php

declare(strict_types=1);

namespace cieplik206\BirRegon;

use Illuminate\Support\ServiceProvider;

class BirRegonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/bir-regon.php',
            'bir-regon',
        );

        $this->app->singleton(GusApiFactoryInterface::class, GusApiFactory::class);
        $this->app->singleton(BirClientInterface::class, BirClient::class);
        $this->app->singleton(BirRegonService::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/bir-regon.php' => config_path('bir-regon.php'),
        ], 'bir-regon-config');
    }
}

<?php

namespace NoPanic\Laravel;

use Illuminate\Support\ServiceProvider;
use NoPanic\Laravel\Support\Activation;

class NoPanicServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nopanic.php', 'nopanic');

        $this->app->singleton(Activation::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/nopanic.php' => config_path('nopanic.php'),
            ], 'nopanic-config');
        }
    }
}

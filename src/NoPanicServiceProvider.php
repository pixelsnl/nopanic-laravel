<?php

namespace NoPanic\Laravel;

use Illuminate\Support\ServiceProvider;
use NoPanic\Laravel\Events\EventTrail;
use NoPanic\Laravel\Events\RegisterRecorders;
use NoPanic\Laravel\Support\Activation;
use NoPanic\Laravel\Transport\ReportThrottle;
use NoPanic\Laravel\Transport\Transport;

class NoPanicServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nopanic.php', 'nopanic');

        $this->app->singleton(Activation::class);
        $this->app->singleton(EventTrail::class);
        $this->app->singleton(ReportThrottle::class);
        $this->app->singleton(Transport::class);
    }

    public function boot(): void
    {
        if ($this->app->make(Activation::class)->isEnabled()) {
            $this->app->make(RegisterRecorders::class)->register($this->app['events']);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/nopanic.php' => config_path('nopanic.php'),
            ], 'nopanic-config');
        }
    }
}

<?php

namespace NoPanic\Laravel;

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;
use NoPanic\Laravel\Commands\ScheduleSyncCommand;
use NoPanic\Laravel\Commands\TestCommand;
use NoPanic\Laravel\Events\EventTrail;
use NoPanic\Laravel\Events\RegisterRecorders;
use NoPanic\Laravel\Schedule\ScheduleMonitor;
use NoPanic\Laravel\Support\Activation;
use NoPanic\Laravel\Transport\ReportThrottle;
use NoPanic\Laravel\Transport\Transport;
use Throwable;

class NoPanicServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nopanic.php', 'nopanic');

        $this->app->singleton(Activation::class);
        $this->app->singleton(EventTrail::class);
        $this->app->singleton(ReportThrottle::class);
        $this->app->singleton(Transport::class);
        $this->app->singleton(Reporter::class);
        $this->app->singleton(ScheduleMonitor::class);
    }

    public function boot(): void
    {
        if ($this->app->make(Activation::class)->isEnabled()) {
            $this->app->make(RegisterRecorders::class)->register($this->app['events']);

            $handler = $this->app->make(ExceptionHandler::class);

            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (Throwable $exception): void {
                    app(Reporter::class)->report($exception);
                });
            }
        }

        if ($this->app->runningInConsole()) {
            $this->registerScheduleMonitoring();

            $this->commands([TestCommand::class, ScheduleSyncCommand::class]);

            $this->publishes([
                __DIR__.'/../config/nopanic.php' => config_path('nopanic.php'),
            ], 'nopanic-config');
        }
    }

    protected function registerScheduleMonitoring(): void
    {
        $events = $this->app['events'];

        $events->listen(ScheduledTaskStarting::class, [ScheduleMonitor::class, 'handleStarting']);
        $events->listen(ScheduledTaskFinished::class, [ScheduleMonitor::class, 'handleFinished']);
        $events->listen(ScheduledTaskFailed::class, [ScheduleMonitor::class, 'handleFailed']);
        $events->listen(ScheduledTaskSkipped::class, [ScheduleMonitor::class, 'handleSkipped']);
        $events->listen(ScheduledBackgroundTaskFinished::class, [ScheduleMonitor::class, 'handleBackgroundFinished']);
    }
}

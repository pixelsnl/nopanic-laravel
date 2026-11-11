<?php

namespace NoPanic\Laravel\Events;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Jobs\SyncJob;
use Laravel\Octane\Events\RequestReceived;
use NoPanic\Laravel\Context\EntryPoint;
use Throwable;

class RegisterRecorders
{
    public function __construct(protected EventTrail $trail) {}

    public function register(Dispatcher $events): void
    {
        $events->listen(QueryExecuted::class, $this->guard($this->recordQuery(...)));
        $events->listen(MessageLogged::class, $this->guard($this->recordLog(...)));
        $events->listen(JobProcessing::class, $this->guard($this->startJob(...)));
        $events->listen(JobProcessed::class, $this->guard($this->finishJob(...)));
        $events->listen(Looping::class, $this->guard($this->finishJob(...)));
        $events->listen(CommandStarting::class, $this->guard($this->startCommand(...)));
        $events->listen(RequestHandled::class, $this->guard($this->startFresh(...)));

        if (class_exists(RequestReceived::class)) {
            $events->listen(RequestReceived::class, $this->guard($this->startFresh(...)));
        }
    }

    protected function guard(callable $listener): callable
    {
        return function (object $event) use ($listener): void {
            try {
                $listener($event);
            } catch (Throwable) {
            }
        };
    }

    protected function recordQuery(QueryExecuted $event): void
    {
        $this->trail->record('query', [
            'sql' => $event->sql,
            'time_ms' => $event->time,
            'connection' => $event->connectionName,
        ]);
    }

    protected function recordLog(MessageLogged $event): void
    {
        $this->trail->record('log', [
            'level' => $event->level,
            'message' => mb_substr((string) $event->message, 0, 1000),
        ]);
    }

    protected function startJob(JobProcessing $event): void
    {
        if ($this->isSyncJob($event)) {
            return;
        }

        $jobClass = $event->job->resolveName();

        $this->trail->flush();
        EntryPoint::markJob($jobClass);

        $this->trail->record('job', ['class' => $jobClass]);
    }

    protected function finishJob(JobProcessed|Looping $event): void
    {
        if ($event instanceof JobProcessed && $this->isSyncJob($event)) {
            return;
        }

        EntryPoint::finishJob();
    }

    protected function isSyncJob(JobProcessing|JobProcessed $event): bool
    {
        if ($event->connectionName === 'sync') {
            return true;
        }

        return $event->job instanceof SyncJob;
    }

    protected function startCommand(CommandStarting $event): void
    {
        if ($this->isHandlingHttpRequest()) {
            return;
        }

        if (EntryPoint::isMarked()) {
            return;
        }

        $this->trail->flush();
        EntryPoint::markCommand((string) $event->command);
    }

    protected function isHandlingHttpRequest(): bool
    {
        $application = app();

        if (! $application->runningInConsole()) {
            return true;
        }

        if (! $application->bound('router')) {
            return false;
        }

        return $application->make('router')->current() !== null;
    }

    protected function startFresh(object $event): void
    {
        $this->trail->flush();
        EntryPoint::reset();
    }
}

<?php

namespace NoPanic\Laravel\Events;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobProcessing;
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
        $jobClass = $event->job->resolveName();

        $this->trail->flush();
        EntryPoint::reset();
        EntryPoint::markJob($jobClass);

        $this->trail->record('job', ['class' => $jobClass]);
    }

    protected function startCommand(CommandStarting $event): void
    {
        $this->trail->flush();
        EntryPoint::reset();
        EntryPoint::markCommand((string) $event->command);
    }

    protected function startFresh(object $event): void
    {
        $this->trail->flush();
        EntryPoint::reset();
    }
}

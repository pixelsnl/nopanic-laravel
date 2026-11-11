<?php

namespace NoPanic\Laravel\Schedule;

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use NoPanic\Laravel\Support\Activation;
use SplObjectStorage;
use Throwable;

class ScheduleMonitor
{
    /** @var SplObjectStorage<Event, true> */
    protected SplObjectStorage $failed;

    public function __construct(
        protected Activation $activation,
        protected TaskPinger $pinger,
        protected Schedule $schedule,
    ) {
        $this->failed = new SplObjectStorage;
    }

    public function handleStarting(ScheduledTaskStarting $event): void
    {
        $this->guarded(function () use ($event): void {
            $this->failed->detach($event->task);

            $this->send($event->task, 'started');
        });
    }

    public function handleFinished(ScheduledTaskFinished $event): void
    {
        $this->guarded(function () use ($event): void {
            if ($event->task->runInBackground) {
                return;
            }

            $this->sendOutcome($event->task);
        });
    }

    public function handleBackgroundFinished(ScheduledBackgroundTaskFinished $event): void
    {
        $this->guarded(fn () => $this->sendOutcome($event->task));
    }

    public function handleFailed(ScheduledTaskFailed $event): void
    {
        $this->guarded(function () use ($event): void {
            if ($this->failed->contains($event->task)) {
                return;
            }

            $this->failed->attach($event->task);

            $this->send($event->task, 'failed', $event->exception->getMessage());
        });
    }

    protected function sendOutcome(Event $task): void
    {
        $exitCode = (int) $task->exitCode;

        if ($exitCode === 0) {
            $this->send($task, 'finished');

            return;
        }

        $this->failed->attach($task);

        $this->send($task, 'failed', "Exited with code {$exitCode}.");
    }

    protected function send(Event $task, string $event, ?string $output = null): void
    {
        if (! config('nopanic.schedule_monitoring', true) || ! $this->activation->isEnabled()) {
            return;
        }

        $names = ScheduledTaskName::forSchedule($this->schedule);

        $name = $names->contains($task) ? $names[$task] : ScheduledTaskName::for($task);

        $this->pinger->ping($name, $event, $output);
    }

    protected function guarded(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            return;
        }
    }
}

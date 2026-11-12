<?php

namespace NoPanic\Laravel\Commands;

use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use NoPanic\Laravel\Schedule\ScheduledTaskName;
use NoPanic\Laravel\Schedule\TaskPinger;
use NoPanic\Laravel\Support\Activation;
use Throwable;

class ScheduleSyncCommand extends Command
{
    protected $signature = 'nopanic:schedule-sync';

    protected $description = 'Sync the scheduled tasks of this application with NoPanic';

    public function handle(Schedule $schedule, Activation $activation): int
    {
        if (! $activation->isEnabled()) {
            $this->components->warn('NoPanic is not active (no key, or this environment is not in enabled_environments). Nothing was synced.');

            return self::SUCCESS;
        }

        if (! config('nopanic.schedule_monitoring', true)) {
            $this->components->warn('Schedule monitoring is disabled (nopanic.schedule_monitoring). Nothing was synced.');

            return self::SUCCESS;
        }

        $tasks = $this->tasks($schedule);

        if (count($tasks) > 200) {
            $this->components->error('NoPanic supports at most 200 scheduled tasks per project, found '.count($tasks).'.');

            return self::FAILURE;
        }

        try {
            $response = Http::withHeaders(['X-NoPanic-Key' => (string) config('nopanic.key')])
                ->acceptJson()
                ->asJson()
                ->timeout(10)
                ->connectTimeout(5)
                ->post(TaskPinger::endpoint().'/sync', ['tasks' => $tasks]);
        } catch (Throwable $exception) {
            $this->components->error("Could not reach NoPanic: {$exception->getMessage()}");

            return self::FAILURE;
        }

        if ($response->status() === 401) {
            $this->components->error('NoPanic rejected the key. Check NOPANIC_KEY.');

            return self::FAILURE;
        }

        if ($response->status() === 422) {
            $this->components->error('NoPanic rejected the scheduled tasks: '.$response->body());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->components->error("NoPanic responded with HTTP status {$response->status()}.");

            return self::FAILURE;
        }

        $synced = $response->json('tasks');

        $this->components->info('Synced '.(is_array($synced) ? count($synced) : count($tasks)).' scheduled tasks with NoPanic.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function tasks(Schedule $schedule): array
    {
        $names = ScheduledTaskName::forSchedule($schedule);
        $tasks = [];

        foreach ($schedule->events() as $event) {
            if (! $event->runsInEnvironment($this->laravel->environment())) {
                continue;
            }

            $tasks[] = [
                'name' => $names[$event],
                'command' => ScheduledTaskName::commandFor($event),
                'cron' => $event->expression,
                'timezone' => $this->timezoneFor($event),
                'grace_minutes' => 5,
            ];
        }

        return $tasks;
    }

    protected function timezoneFor(Event $event): string
    {
        $timezone = $event->timezone;

        if ($timezone instanceof DateTimeZone) {
            return $timezone->getName();
        }

        if (is_string($timezone) && $timezone !== '') {
            return $timezone;
        }

        return (string) config('app.timezone', 'UTC');
    }
}

<?php

namespace NoPanic\Laravel\Schedule;

use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use SplObjectStorage;

class ScheduledTaskName
{
    public static function for(Event $event): string
    {
        $description = $event->description;

        if (is_string($description) && trim($description) !== '') {
            return trim($description);
        }

        $command = static::commandFor($event);

        if ($command !== null && $command !== '') {
            return $command;
        }

        return static::classFor($event).'-'.substr(md5((string) $event->expression), 0, 8);
    }

    public static function commandFor(Event $event): ?string
    {
        if ($event instanceof CallbackEvent || ! is_string($event->command)) {
            return null;
        }

        $prefix = Application::formatCommandString('');
        $command = $event->command;

        if (str_starts_with($command, $prefix)) {
            $command = substr($command, strlen($prefix));
        }

        return trim($command);
    }

    /**
     * @return SplObjectStorage<Event, string>
     */
    public static function forSchedule(Schedule $schedule): SplObjectStorage
    {
        $names = new SplObjectStorage;
        $used = [];

        foreach ($schedule->events() as $event) {
            $name = static::for($event);
            $unique = $name;
            $counter = 2;

            while (isset($used[$unique])) {
                $unique = $name.'-'.$counter++;
            }

            $used[$unique] = true;
            $names[$event] = $unique;
        }

        return $names;
    }

    protected static function classFor(Event $event): string
    {
        if (! $event instanceof CallbackEvent) {
            return 'Task';
        }

        $callback = (fn () => $this->callback)->call($event);

        if (is_object($callback)) {
            return $callback::class;
        }

        if (is_array($callback) && isset($callback[0])) {
            return is_object($callback[0]) ? $callback[0]::class : (string) $callback[0];
        }

        return is_string($callback) ? $callback : 'Closure';
    }
}

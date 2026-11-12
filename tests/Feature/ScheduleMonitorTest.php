<?php

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'nopanic.key' => 'np_test',
        'nopanic.endpoint' => 'https://ingest.nopanic.test/api/v1/reports',
    ]);
});

it('pings started and finished around a scheduled task', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    $event = app(Schedule::class)->command('backup:run')->dailyAt('02:00');

    event(new ScheduledTaskStarting($event));
    event(new ScheduledTaskFinished($event, 1.5));

    Http::assertSentInOrder([
        fn (Request $request) => $request->url() === 'https://ingest.nopanic.test/api/v1/tasks/ping'
            && $request->header('X-NoPanic-Key') === ['np_test']
            && $request['event'] === 'started'
            && $request['name'] === 'backup:run',
        fn (Request $request) => $request['event'] === 'finished',
    ]);
});

it('pings failed when a finished task has a non zero exit code, once', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    $event = app(Schedule::class)->command('backup:run')->daily();
    $event->exitCode = 2;

    event(new ScheduledTaskStarting($event));
    event(new ScheduledTaskFinished($event, 1.5));
    event(new ScheduledTaskFailed($event, new Exception('failed with exit code 2'), 1.5));

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request['event'] === 'failed' && $request['output'] === 'Exited with code 2.');
});

it('pings failed with the exception message when a task throws', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    $event = app(Schedule::class)->call(fn () => null)->description('Report job')->daily();

    event(new ScheduledTaskStarting($event));
    event(new ScheduledTaskFailed($event, new RuntimeException('boom'), 0.1));

    Http::assertSent(fn (Request $request) => $request['event'] === 'failed'
        && $request['name'] === 'Report job'
        && $request['output'] === 'boom');
});

it('waits for the background finish event before pinging finished', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    $event = app(Schedule::class)->command('backup:run')->daily()->runInBackground();
    $event->exitCode = 0;

    event(new ScheduledTaskStarting($event));
    event(new ScheduledTaskFinished($event, 0.1));

    Http::assertSentCount(1);

    event(new ScheduledBackgroundTaskFinished($event));

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request['event'] === 'finished');
});

it('pings failed when a background task exits with an error', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    $event = app(Schedule::class)->command('backup:run')->daily()->runInBackground();
    $event->exitCode = 1;

    event(new ScheduledBackgroundTaskFinished($event));

    Http::assertSent(fn (Request $request) => $request['event'] === 'failed');
});

it('uses the deduplicated name for duplicate tasks', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    $schedule = app(Schedule::class);
    $schedule->command('backup:run')->daily();
    $second = $schedule->command('backup:run')->daily();

    event(new ScheduledTaskStarting($second));

    Http::assertSent(fn (Request $request) => $request['name'] === 'backup:run-2');
});

it('does not ping when inactive or disabled', function () {
    Http::fake();
    $event = app(Schedule::class)->command('backup:run')->daily();

    config(['nopanic.key' => null]);
    event(new ScheduledTaskStarting($event));

    config(['nopanic.key' => 'np_test', 'nopanic.schedule_monitoring' => false]);
    event(new ScheduledTaskStarting($event));

    Http::assertNothingSent();
});

it('never lets a failing ping break the scheduler', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));
    $event = app(Schedule::class)->command('backup:run')->daily();

    event(new ScheduledTaskStarting($event));
    event(new ScheduledTaskFinished($event, 1.0));

    expect(true)->toBeTrue();
});

it('pings skipped when the scheduler skips a task', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    $event = app(Schedule::class)->command('backup:run')->hourly()->withoutOverlapping();

    event(new ScheduledTaskSkipped($event));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://ingest.nopanic.test/api/v1/tasks/ping'
        && $request['event'] === 'skipped'
        && $request['name'] === 'backup:run');
});

it('never lets a failing skipped ping break the scheduler', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));
    $event = app(Schedule::class)->command('backup:run')->hourly();

    event(new ScheduledTaskSkipped($event));

    expect(true)->toBeTrue();
});

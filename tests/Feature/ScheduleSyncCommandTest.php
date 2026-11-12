<?php

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

it('syncs scheduled tasks with names, cron and timezone', function () {
    Http::fake(['*' => Http::response(['tasks' => []])]);
    app(Schedule::class)->command('backup:run')->dailyAt('02:00')->timezone('Europe/Amsterdam');

    $this->artisan('nopanic:schedule-sync')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://ingest.nopanic.test/api/v1/tasks/sync'
        && $request->header('X-NoPanic-Key') === ['np_test']
        && $request->header('Accept') === ['application/json']
        && $request['tasks'][0]['name'] === 'backup:run'
        && $request['tasks'][0]['command'] === 'backup:run'
        && $request['tasks'][0]['cron'] === '0 2 * * *'
        && $request['tasks'][0]['timezone'] === 'Europe/Amsterdam'
        && $request['tasks'][0]['grace_minutes'] === 5);
});

it('uses the tasks endpoint override', function () {
    Http::fake(['*' => Http::response(['tasks' => []])]);
    config(['nopanic.tasks_endpoint' => 'https://tasks.nopanic.test/custom/']);

    $this->artisan('nopanic:schedule-sync')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://tasks.nopanic.test/custom/sync');
});

it('names tasks by description, command, or class with a cron hash and keeps names unique', function () {
    Http::fake(['*' => Http::response(['tasks' => []])]);
    $schedule = app(Schedule::class);
    $schedule->command('backup:run')->daily()->description('Nightly backup');
    $schedule->command('emails:send --force')->hourly();
    $schedule->exec('ls -la')->daily();
    $schedule->call(fn () => null)->daily();
    $schedule->call(fn () => null)->daily();
    $schedule->command('emails:send --force')->hourly();

    $this->artisan('nopanic:schedule-sync')->assertSuccessful();

    Http::assertSent(function (Request $request) {
        $names = array_column($request['tasks'], 'name');
        $closure = 'Closure-'.substr(md5('0 0 * * *'), 0, 8);

        return $names === [
            'Nightly backup',
            'emails:send --force',
            'ls -la',
            $closure,
            $closure.'-2',
            'emails:send --force-2',
        ] && $request['tasks'][3]['command'] === null;
    });
});

it('fails the sync command without throwing when nopanic is unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    $this->artisan('nopanic:schedule-sync')->assertFailed();
});

it('fails the sync command when the key is rejected', function () {
    Http::fake(['*' => Http::response(null, 401)]);

    $this->artisan('nopanic:schedule-sync')->assertFailed();
});

it('fails the sync command when the tasks are invalid', function () {
    Http::fake(['*' => Http::response(['message' => 'invalid'], 422)]);

    $this->artisan('nopanic:schedule-sync')->assertFailed();
});

it('explains and succeeds when nopanic is not active', function () {
    Http::fake();
    config(['nopanic.key' => null]);

    $this->artisan('nopanic:schedule-sync')
        ->expectsOutputToContain('not active')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('does not sync tasks that do not run in this environment and keeps suffixes consistent', function () {
    Http::fake(['*' => Http::response(['tasks' => []])]);
    app()->detectEnvironment(fn () => 'staging');
    $schedule = app(Schedule::class);
    $schedule->command('backup:run')->daily()->environments(['production']);
    $schedule->command('backup:run')->daily();
    $schedule->command('emails:send')->hourly();

    $this->artisan('nopanic:schedule-sync')->assertSuccessful();

    Http::assertSent(fn (Request $request) => array_column($request['tasks'], 'name') === ['backup:run-2', 'emails:send']);
});

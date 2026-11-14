<?php

use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();

    $this->basePath = sys_get_temp_dir().'/nopanic-security-'.uniqid();
    mkdir($this->basePath);
    copy(__DIR__.'/../Fixtures/composer.lock', $this->basePath.'/composer.lock');
    app()->setBasePath($this->basePath);

    config([
        'nopanic.key' => 'np_test',
        'nopanic.stage' => 'staging',
        'nopanic.endpoint' => 'https://ingest.nopanic.test/api/v1/reports',
    ]);
});

afterEach(function () {
    array_map('unlink', glob($this->basePath.'/*'));
    rmdir($this->basePath);
});

it('sends the payload and skips an unchanged second run', function () {
    Http::fake(['*' => Http::response(null, 202)]);

    $this->artisan('nopanic:security-sync')->assertSuccessful();
    $this->artisan('nopanic:security-sync')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://ingest.nopanic.test/api/v1/security/sync'
        && $request->header('X-NoPanic-Key') === ['np_test']
        && $request['stage'] === 'staging'
        && $request['composer'][0] === ['name' => 'laravel/framework', 'version' => 'v11.9.0']
        && $request['npm'] === null
        && $request['environment']['app_key'] === 'missing');

    $this->artisan('nopanic:security-sync', ['--force' => true])->assertSuccessful();

    Http::assertSentCount(2);
});

it('falls back to the application environment when no stage is configured', function () {
    Http::fake(['*' => Http::response(null, 202)]);
    config(['nopanic.stage' => '']);

    $this->artisan('nopanic:security-sync')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request['stage'] === 'production');
});

it('sends again when the payload changed', function () {
    Http::fake(['*' => Http::response(null, 202)]);

    $this->artisan('nopanic:security-sync')->assertSuccessful();
    config(['app.debug' => ! config('app.debug')]);
    $this->artisan('nopanic:security-sync')->assertSuccessful();

    Http::assertSentCount(2);
});

it('sends again after 24 hours', function () {
    Http::fake(['*' => Http::response(null, 202)]);

    $this->artisan('nopanic:security-sync')->assertSuccessful();
    $this->travel(25)->hours();
    $this->artisan('nopanic:security-sync')->assertSuccessful();

    Http::assertSentCount(2);
});

it('fails with exit code 1 on a rejected key and does not remember it', function () {
    Http::fake(['*' => Http::sequence()->push(null, 401)->push(null, 202)]);

    $this->artisan('nopanic:security-sync')
        ->expectsOutputToContain('NOPANIC_KEY')
        ->assertExitCode(1);

    $this->artisan('nopanic:security-sync')->assertSuccessful();

    Http::assertSentCount(2);
});

it('shows the body when the payload is too large or invalid', function (int $status) {
    Http::fake(['*' => Http::response(['message' => 'nope'], $status)]);

    $this->artisan('nopanic:security-sync')
        ->expectsOutputToContain('nope')
        ->assertExitCode(1);
})->with([413, 422]);

it('shows other statuses', function () {
    Http::fake(['*' => Http::response(null, 500)]);

    $this->artisan('nopanic:security-sync')
        ->expectsOutputToContain('500')
        ->assertExitCode(1);
});

it('fails with exit code 1 without throwing when nopanic is unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    $this->artisan('nopanic:security-sync')->assertExitCode(1);
});

it('does nothing when security is disabled', function () {
    Http::fake();
    config(['nopanic.security' => false]);

    $this->artisan('nopanic:security-sync')->assertSuccessful();

    Http::assertNothingSent();
});

it('explains and succeeds when nopanic is not active', function () {
    Http::fake();
    config(['nopanic.key' => null]);

    $this->artisan('nopanic:security-sync')
        ->expectsOutputToContain('not active')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('schedules the security sync hourly and keeps it out of task monitoring', function () {
    Http::fake(['*' => Http::response(['tasks' => []])]);
    $schedule = app(Schedule::class);
    $schedule->command('emails:send')->daily();

    $events = collect($schedule->events())->filter(fn ($event) => str_contains((string) $event->command, 'nopanic:security-sync'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *')
        ->and($events->first()->runInBackground)->toBeTrue()
        ->and($events->first()->withoutOverlapping)->toBeTrue();

    $this->artisan('nopanic:schedule-sync')->assertSuccessful();

    Http::assertSent(fn (Request $request) => array_column($request['tasks'], 'name') === ['emails:send']);
});

it('does not ping for the security sync', function () {
    Http::fake();
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'nopanic:security-sync'));

    app('events')->dispatch(new ScheduledTaskStarting($event));

    Http::assertNothingSent();
});

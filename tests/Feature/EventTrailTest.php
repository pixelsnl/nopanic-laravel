<?php

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use NoPanic\Laravel\Context\EntryPoint;
use NoPanic\Laravel\Events\EventTrail;
use NoPanic\Laravel\Tests\Support\FakeQueueJob;
use NoPanic\Laravel\Tests\Support\FakeSyncJob;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

it('records queries and logs in order without bindings', function () {
    DB::select('select ? as value', [123]);
    Log::warning('Something odd');

    $events = app(EventTrail::class)->all();

    expect(collect($events)->pluck('type')->all())->toBe(['query', 'log']);
    expect($events[0]['data']['sql'])->toBe('select ? as value');
    expect($events[0]['data'])->not->toHaveKey('bindings');
    expect($events[0]['at'])->toBeInt();
});

it('keeps only the most recent events', function () {
    config(['nopanic.max_events' => 2]);

    collect(['one', 'two', 'three'])->each(fn (string $message) => Log::info($message));

    expect(collect(app(EventTrail::class)->all())->pluck('data.message')->all())->toBe(['two', 'three']);
});

it('truncates long log messages and leaves out context', function () {
    Log::info(str_repeat('a', 2000), ['secret' => 'value']);

    $event = app(EventTrail::class)->all()[0];

    expect(mb_strlen($event['data']['message']))->toBe(1000);
    expect($event['data'])->not->toHaveKey('context');
});

it('starts a fresh trail for every job', function () {
    Log::info('from the previous job');

    event(new JobProcessing('redis', new FakeQueueJob('App\\Jobs\\SendInvoice')));

    expect(collect(app(EventTrail::class)->all())->pluck('type')->all())->toBe(['job']);
    expect(EntryPoint::current()['identifier'])->toBe('App\\Jobs\\SendInvoice');
});

it('starts a fresh trail for every command', function () {
    Log::info('before the command');

    event(new CommandStarting('orders:sync', new ArrayInput([]), new NullOutput));

    expect(app(EventTrail::class)->all())->toBe([]);
    expect(EntryPoint::current()['type'])->toBe('command');
});

it('clears the trail and entry point when a request is handled', function () {
    EntryPoint::markJob('App\\Jobs\\Leftover');
    Log::info('during the request');

    event(new RequestHandled(request(), response('')));

    expect(app(EventTrail::class)->all())->toBe([]);
    expect(EntryPoint::current())->toBeNull();
});

it('caps long string fields of events', function () {
    app(EventTrail::class)->record('query', ['sql' => str_repeat('a', 3000), 'time_ms' => 1.5]);

    $event = app(EventTrail::class)->all()[0];

    expect(mb_strlen($event['data']['sql']))->toBe(2000);
    expect($event['data']['time_ms'])->toBe(1.5);
});

it('ignores sync jobs as entry points', function () {
    Log::info('before the sync job');

    event(new JobProcessing('sync', new FakeQueueJob('App\\Jobs\\SendInvoice')));

    expect(EntryPoint::current())->toBeNull();
    expect(collect(app(EventTrail::class)->all())->pluck('data.message')->all())->toBe(['before the sync job']);
});

it('ends the job entry point when the job is processed or the worker loops', function () {
    event(new CommandStarting('queue:work', new ArrayInput([]), new NullOutput));

    event(new JobProcessing('redis', new FakeQueueJob('App\\Jobs\\SendInvoice')));
    expect(EntryPoint::current()['identifier'])->toBe('App\\Jobs\\SendInvoice');

    event(new JobProcessed('redis', new FakeQueueJob('App\\Jobs\\SendInvoice')));
    expect(EntryPoint::current())->toMatchArray(['type' => 'command', 'identifier' => 'queue:work']);

    event(new JobProcessing('redis', new FakeQueueJob('App\\Jobs\\FailingJob')));
    event(new JobFailed('redis', new FakeQueueJob('App\\Jobs\\FailingJob'), new RuntimeException('failed')));
    expect(EntryPoint::current()['identifier'])->toBe('App\\Jobs\\FailingJob');

    event(new Looping('redis', 'default'));
    expect(EntryPoint::current())->toMatchArray(['type' => 'command', 'identifier' => 'queue:work']);
});

it('keeps the outer command when a command calls another command', function () {
    event(new CommandStarting('orders:sync', new ArrayInput([]), new NullOutput));
    Log::info('during orders sync');

    event(new CommandStarting('cache:clear', new ArrayInput([]), new NullOutput));

    expect(EntryPoint::current()['identifier'])->toBe('orders:sync');
    expect(collect(app(EventTrail::class)->all())->pluck('data.message')->all())->toBe(['during orders sync']);
});

it('keeps the web entry point and trail after a sync job inside a request', function () {
    Http::fake(['*' => Http::response(['id' => 'x'], 202)]);
    config(['queue.default' => 'sync']);

    Route::get('/checkout', function () {
        Log::info('before the job');
        dispatch(new FakeSyncJob);

        throw new RuntimeException('after the job');
    });

    $this->get('/checkout?step=2')->assertServerError();

    Http::assertSent(fn (Request $request) => $request['entry_point']['type'] === 'web'
        && $request['entry_point']['identifier'] === 'GET checkout'
        && $request['attributes']['request']['query'] === ['step' => '2']
        && collect($request['events'])->pluck('data.message')->filter()->values()->all() === ['before the job', 'inside the sync job']);
});

it('keeps the web entry point and trail after artisan call inside a request', function () {
    Http::fake(['*' => Http::response(['id' => 'x'], 202)]);
    Artisan::command('fake:noop', fn () => Log::info('inside the command'));

    Route::get('/checkout', function () {
        Log::info('before the command');
        Artisan::call('fake:noop');

        throw new RuntimeException('after the command');
    });

    $this->get('/checkout?step=3')->assertServerError();

    Http::assertSent(fn (Request $request) => $request['entry_point']['type'] === 'web'
        && $request['entry_point']['identifier'] === 'GET checkout'
        && $request['attributes']['request']['query'] === ['step' => '3']
        && collect($request['events'])->pluck('data.message')->filter()->values()->all() === ['before the command', 'inside the command']);
});

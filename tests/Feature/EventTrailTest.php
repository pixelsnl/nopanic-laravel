<?php

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use NoPanic\Laravel\Context\EntryPoint;
use NoPanic\Laravel\Events\EventTrail;
use NoPanic\Laravel\Tests\Support\FakeQueueJob;
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

    event(new JobProcessing('sync', new FakeQueueJob('App\\Jobs\\SendInvoice')));

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

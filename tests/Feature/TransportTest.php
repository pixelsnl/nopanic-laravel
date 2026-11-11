<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use NoPanic\Laravel\Transport\Transport;

it('posts the payload with the key header', function () {
    Http::fake(['*' => Http::response(['id' => 'x'], 202)]);
    config(['nopanic.key' => 'np_test', 'nopanic.endpoint' => 'https://ingest.example.test/api/v1/reports']);

    app(Transport::class)->send(['exception' => ['class' => 'RuntimeException']]);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://ingest.example.test/api/v1/reports'
        && $request->method() === 'POST'
        && $request->header('X-NoPanic-Key') === ['np_test']
        && $request->header('Accept') === ['application/json']
        && $request->header('Content-Type') === ['application/json']
        && $request['exception']['class'] === 'RuntimeException');
});

it('pauses sending after a 429 until retry after has passed', function () {
    Http::fake(['*' => Http::response(null, 429, ['Retry-After' => '120'])]);

    app(Transport::class)->send(['exception' => ['class' => 'RuntimeException']]);
    app(Transport::class)->send(['exception' => ['class' => 'RuntimeException']]);

    Http::assertSentCount(1);

    $this->travel(121)->seconds();
    app(Transport::class)->send(['exception' => ['class' => 'RuntimeException']]);

    Http::assertSentCount(2);
});

it('respects a pause stored in the cache by another process', function () {
    Http::fake(['*' => Http::response(['id' => 'x'], 202)]);
    Cache::put('nopanic:paused-until', now()->addMinute()->getTimestamp(), 120);

    app(Transport::class)->send(['exception' => ['class' => 'RuntimeException']]);

    Http::assertNothingSent();
});

it('never throws when the ingest is unreachable', function () {
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('Could not resolve host');
    });
    Log::spy();

    $threw = false;

    try {
        app(Transport::class)->send(['exception' => ['class' => 'RuntimeException']]);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
    expect($attempts)->toBe(1);
    Log::shouldNotHaveReceived('error');
});

it('drops the events when the payload is too large', function () {
    Http::fake(['*' => Http::response(null, 202)]);

    app(Transport::class)->send([
        'exception' => ['class' => 'RuntimeException'],
        'events' => [['type' => 'log', 'data' => ['message' => str_repeat('a', 950000)]]],
        'attributes' => ['request' => ['body' => ['small' => 'value']]],
    ]);

    Http::assertSent(fn (Request $request) => $request['events'] === []
        && $request['attributes']['request']['body'] === ['small' => 'value']);
});

it('drops the request body when the payload is still too large', function () {
    Http::fake(['*' => Http::response(null, 202)]);

    app(Transport::class)->send([
        'exception' => ['class' => 'RuntimeException'],
        'events' => [],
        'attributes' => ['request' => ['method' => 'POST', 'body' => ['big' => str_repeat('a', 950000)]]],
    ]);

    Http::assertSent(fn (Request $request) => $request['events'] === []
        && $request['attributes']['request'] === ['method' => 'POST']);
});

it('does not send a payload that stays too large', function () {
    Http::fake(['*' => Http::response(null, 202)]);

    app(Transport::class)->send([
        'exception' => ['class' => 'RuntimeException', 'message' => str_repeat('a', 950000)],
        'events' => [],
    ]);

    Http::assertNothingSent();
});

it('substitutes invalid utf8 instead of failing', function () {
    Http::fake(['*' => Http::response(null, 202)]);

    app(Transport::class)->send(['exception' => ['class' => 'RuntimeException', 'message' => "bad \xB1 byte"]]);

    Http::assertSentCount(1);
});

it('applies the configured timeout with fractions of a second', function () {
    $options = null;
    Http::fake(function (Request $request, array $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Http::response(null, 202);
    });
    config(['nopanic.timeout_seconds' => 0.5]);

    app(Transport::class)->send(['exception' => ['class' => 'RuntimeException']]);

    expect($options['timeout'])->toBe(0.5);
    expect($options['connect_timeout'])->toBe(1);
});

it('never uses a timeout below a tenth of a second', function () {
    $options = null;
    Http::fake(function (Request $request, array $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Http::response(null, 202);
    });
    config(['nopanic.timeout_seconds' => 0]);

    app(Transport::class)->send(['exception' => ['class' => 'RuntimeException']]);

    expect($options['timeout'])->toBe(0.1);
});

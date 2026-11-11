<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use NoPanic\Laravel\Exceptions\NoPanicTestException;
use NoPanic\Laravel\Report\ReportFactory;

beforeEach(function () {
    config(['nopanic.key' => 'np_test']);
});

it('sends a test exception from the command', function () {
    Http::fake(['*' => Http::response(['id' => 'x'], 202)]);

    $this->artisan('nopanic:test')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request['exception']['class'] === NoPanicTestException::class);
});

it('fails the test command without a key', function () {
    config(['nopanic.key' => null]);

    $this->artisan('nopanic:test')->assertFailed();
});

it('fails the test command when the ingest rejects the key', function () {
    Http::fake(['*' => Http::response(null, 401)]);

    $this->artisan('nopanic:test')->assertFailed();
});

it('fails the test command with a clear error when building the report throws', function () {
    Http::fake(['*' => Http::response(['id' => 'x'], 202)]);
    app()->bind(ReportFactory::class, fn () => throw new LogicException('broken factory'));

    $this->artisan('nopanic:test')
        ->expectsOutputToContain('Could not send the test exception: broken factory')
        ->assertFailed();

    Http::assertNothingSent();
});

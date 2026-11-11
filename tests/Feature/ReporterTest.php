<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use NoPanic\Laravel\Report\ReportFactory;
use NoPanic\Laravel\Reporter;
use NoPanic\Laravel\Tests\Support\FakeOwnException;
use NoPanic\Laravel\Transport\ReportThrottle;
use Symfony\Component\ErrorHandler\Error\FatalError;

beforeEach(function () {
    Http::fake(['*' => Http::response(['id' => 'x'], 202)]);
    config(['nopanic.key' => 'np_test']);
});

it('reports an unhandled exception from a route after the response', function () {
    Route::get('/boom', fn () => throw new RuntimeException('boom'));

    $this->get('/boom')->assertServerError();
    app()->terminate();

    Http::assertSent(fn (Request $request) => $request['exception']['class'] === RuntimeException::class
        && $request['entry_point']['identifier'] === 'GET boom');
});

it('does not report exceptions laravel does not report', function () {
    Route::get('/missing', fn () => abort(404));

    $this->get('/missing')->assertNotFound();
    app()->terminate();

    Http::assertNothingSent();
});

it('does not break the app when building the report fails', function () {
    app()->bind(ReportFactory::class, fn () => throw new LogicException('broken factory'));
    Route::get('/boom', fn () => throw new RuntimeException('boom'));

    $this->get('/boom')->assertServerError();
    app()->terminate();

    Http::assertNothingSent();
});

it('sends immediately in console without a route', function () {
    app(Reporter::class)->report(new RuntimeException('cli'));

    Http::assertSent(fn (Request $request) => $request['exception']['message'] === 'cli');
});

it('ignores exceptions that come from the package itself', function () {
    app(Reporter::class)->report(new FakeOwnException('own'));

    Http::assertNothingSent();
});

it('does not report when the package is disabled', function () {
    config(['nopanic.key' => null]);

    app(Reporter::class)->report(new RuntimeException('cli'));

    Http::assertNothingSent();
});

it('defers sending until the application terminates', function () {
    $sentDuringRequest = null;
    Route::get('/boom', function () use (&$sentDuringRequest) {
        app(Reporter::class)->report(new RuntimeException('deferred'));
        $sentDuringRequest = Http::recorded()->count();

        return 'ok';
    });

    $this->get('/boom')->assertOk();

    expect($sentDuringRequest)->toBe(0);
    Http::assertSent(fn (Request $request) => $request['exception']['message'] === 'deferred');
});

it('sends fatal errors immediately without waiting for terminate', function () {
    $sentDuringRequest = null;
    Route::get('/fatal', function () use (&$sentDuringRequest) {
        app(Reporter::class)->report(new FatalError('out of memory', 0, ['file' => __FILE__, 'line' => 1]));
        $sentDuringRequest = Http::recorded()->count();

        return 'ok';
    });

    $this->get('/fatal')->assertOk();

    expect($sentDuringRequest)->toBe(1);
});

it('sends pending payloads from the shutdown flush exactly once', function () {
    $sentAfterFlush = null;
    Route::get('/pending', function () use (&$sentAfterFlush) {
        $reporter = app(Reporter::class);
        $reporter->report(new RuntimeException('pending'));
        $reporter->flushPending();
        $sentAfterFlush = Http::recorded()->count();

        return 'ok';
    });

    $this->get('/pending')->assertOk();

    expect($sentAfterFlush)->toBe(1);
    Http::assertSentCount(1);
});

it('keeps the suppressed count when building the report fails', function () {
    config(['nopanic.reports_per_error_per_minute' => 1]);
    $exception = new RuntimeException('cli');
    $signature = $exception::class.'|'.$exception->getFile().'|'.$exception->getLine();
    $throttle = app(ReportThrottle::class);
    $throttle->attempt($signature);
    $throttle->attempt($signature);

    app()->bind(ReportFactory::class, fn () => throw new LogicException('broken factory'));
    app(Reporter::class)->report($exception);
    app()->forgetInstance(ReportFactory::class);
    app()->offsetUnset(ReportFactory::class);

    $this->travel(2)->minutes();

    expect($throttle->attempt($signature))->toBe(1);
});

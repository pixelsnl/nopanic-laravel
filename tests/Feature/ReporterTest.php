<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use NoPanic\Laravel\Report\ReportFactory;
use NoPanic\Laravel\Reporter;
use NoPanic\Laravel\Tests\Support\FakeOwnException;

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

<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use NoPanic\Laravel\Context\ContextCollector;
use NoPanic\Laravel\Context\EntryPoint;
use NoPanic\Laravel\Tests\Support\FakeOrdersController;
use NoPanic\Laravel\Tests\Support\FakeUser;

it('censors sensitive fields and headers', function () {
    $request = Request::create('/orders?token=abc', 'POST', ['email' => 'jan@example.test', 'password' => 'secret'], server: ['HTTP_AUTHORIZATION' => 'Bearer x', 'HTTP_ACCEPT' => 'application/json']);
    app()->instance('request', $request);

    $attributes = app(ContextCollector::class)->collect()['attributes']['request'];

    expect($attributes['body'])->toBe(['email' => 'jan@example.test', 'password' => '[censored]']);
    expect($attributes['query'])->toBe(['token' => '[censored]']);
    expect($attributes['headers']['authorization'])->toBe(['[censored]']);
    expect($attributes['headers']['accept'])->toBe(['application/json']);
});

it('describes a web entry point with route and controller', function () {
    Route::get('/orders/{order}', [FakeOrdersController::class, 'show']);

    $this->get('/orders/5?x=1');

    $context = FakeOrdersController::$context;

    expect($context['entry_point'])->toMatchArray([
        'type' => 'web',
        'identifier' => 'GET orders/{order}',
        'handler' => FakeOrdersController::class.'@show',
        'url' => 'http://localhost/orders/5',
    ]);
    expect($context['attributes']['request']['query'])->toBe(['x' => '1']);
    expect($context['attributes']['environment'])->toHaveKeys(['php_version', 'laravel_version', 'app_env', 'app_debug', 'locale']);
});

it('omits the body for oversized or non form requests', function () {
    $large = Request::create('/orders', 'POST', ['note' => str_repeat('a', 11000)]);
    app()->instance('request', $large);
    expect(app(ContextCollector::class)->collect()['attributes']['request'])->not->toHaveKey('body');

    $xml = Request::create('/orders', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/xml'], '<a/>');
    app()->instance('request', $xml);
    expect(app(ContextCollector::class)->collect()['attributes']['request'])->not->toHaveKey('body');
});

it('reads a json body', function () {
    $request = Request::create('/orders', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"password":"x","a":1}');
    app()->instance('request', $request);

    expect(app(ContextCollector::class)->collect()['attributes']['request']['body'])->toBe(['password' => '[censored]', 'a' => 1]);
});

it('describes a livewire update', function () {
    $payload = ['components' => [['snapshot' => json_encode(['memo' => ['name' => 'orders.table']]), 'updates' => [], 'calls' => []]]];
    $request = Request::create('/livewire/update', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
    app()->instance('request', $request);

    expect(app(ContextCollector::class)->collect()['entry_point'])->toMatchArray([
        'type' => 'livewire',
        'handler' => 'orders.table',
    ]);
});

it('includes the user with id and email', function () {
    $this->actingAs(new FakeUser(['id' => 42, 'email' => 'jan@example.test']));

    expect(app(ContextCollector::class)->collect()['user'])->toBe(['id' => '42', 'email' => 'jan@example.test']);
});

it('omits the user when send_user is off', function () {
    config(['nopanic.send_user' => false]);
    $this->actingAs(new FakeUser(['id' => 42, 'email' => 'jan@example.test']));

    expect(app(ContextCollector::class)->collect()['user'])->toBeNull();
});

it('describes a queued job entry point', function () {
    EntryPoint::markJob('App\\Jobs\\SendInvoice');

    $context = app(ContextCollector::class)->collect();

    expect($context['entry_point'])->toMatchArray(['type' => 'queue', 'identifier' => 'App\\Jobs\\SendInvoice']);
    expect($context['attributes'])->not->toHaveKey('request');
});

it('describes a command entry point and resets', function () {
    EntryPoint::markCommand('orders:sync');
    expect(app(ContextCollector::class)->collect()['entry_point'])->toMatchArray(['type' => 'command', 'identifier' => 'orders:sync']);

    EntryPoint::reset();
    expect(app(ContextCollector::class)->collect()['entry_point'])->toBeNull();
});

it('does not leak livewire payload secrets', function () {
    $payload = ['components' => [[
        'snapshot' => json_encode(['data' => ['password' => 'hunter2'], 'memo' => ['name' => 'orders.table']]),
        'updates' => ['form.password' => 'hunter2'],
        'calls' => [['method' => 'submit', 'params' => ['hunter2']]],
    ]]];
    $request = Request::create('/livewire/update', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
    app()->instance('request', $request);

    $context = app(ContextCollector::class)->collect();

    expect(json_encode($context))->not->toContain('hunter2');
    expect($context['attributes']['request']['livewire'])->toBe(['components' => ['orders.table'], 'calls' => ['submit']]);
});

it('keeps entry point and request when resolving the user fails', function () {
    $request = Request::create('/orders', 'GET');
    app()->instance('request', $request);
    auth()->extend('broken', fn () => throw new RuntimeException('db down'));
    config(['auth.guards.broken' => ['driver' => 'broken'], 'auth.defaults.guard' => 'broken']);

    $context = app(ContextCollector::class)->collect();

    expect($context['user'])->toBeNull();
    expect($context['entry_point']['identifier'])->toBe('GET orders');
    expect($context['attributes']['request']['method'])->toBe('GET');
});

it('uses a slash for the root path without a route', function () {
    app()->instance('request', Request::create('/', 'GET'));

    expect(app(ContextCollector::class)->collect()['entry_point']['identifier'])->toBe('GET /');
});

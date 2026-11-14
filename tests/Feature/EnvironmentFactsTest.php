<?php

use NoPanic\Laravel\Security\EnvironmentFacts;

it('reports the app key state without the key', function (?string $key, string $state) {
    config(['app.key' => $key]);

    $facts = EnvironmentFacts::collect();

    expect($facts['app_key'])->toBe($state);

    if ($key !== null && $key !== '') {
        expect(json_encode($facts))->not->toContain($key);
    }
})->with([
    'missing' => [null, 'missing'],
    'empty' => ['', 'missing'],
    'default' => ['SomeRandomString', 'default'],
    'set' => [fn () => 'base64:'.base64_encode(random_bytes(32)), 'set'],
]);

it('collects versions, debug and session settings', function () {
    config(['app.debug' => true, 'session.secure' => null, 'session.http_only' => true]);

    expect(EnvironmentFacts::collect())->toMatchArray([
        'php_version' => PHP_VERSION,
        'laravel_version' => app()->version(),
        'environment' => 'production',
        'app_debug' => true,
        'session_secure' => null,
        'session_http_only' => true,
    ]);
});

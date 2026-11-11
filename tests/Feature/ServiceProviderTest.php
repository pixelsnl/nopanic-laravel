<?php

use NoPanic\Laravel\NoPanicServiceProvider;
use NoPanic\Laravel\Support\Activation;

it('merges the default config', function () {
    expect(config('nopanic.max_events'))->toBe(50);
    expect(config('nopanic.enabled_environments'))->toBe(['production', 'staging']);
});

it('is disabled without a key', function () {
    config(['nopanic.key' => null]);
    app()->detectEnvironment(fn () => 'production');

    expect(app(Activation::class)->isEnabled())->toBeFalse();
});

it('is disabled outside the enabled environments', function () {
    config(['nopanic.key' => 'np_test']);
    app()->detectEnvironment(fn () => 'local');

    expect(app(Activation::class)->isEnabled())->toBeFalse();
});

it('is enabled with a key in production', function () {
    config(['nopanic.key' => 'np_test']);
    app()->detectEnvironment(fn () => 'production');

    expect(app(Activation::class)->isEnabled())->toBeTrue();
});

it('publishes the config under the nopanic-config tag', function () {
    $paths = NoPanicServiceProvider::pathsToPublish(
        NoPanicServiceProvider::class,
        'nopanic-config',
    );

    expect($paths)->toHaveCount(1);
    expect(array_values($paths)[0])->toEndWith('config/nopanic.php');
});

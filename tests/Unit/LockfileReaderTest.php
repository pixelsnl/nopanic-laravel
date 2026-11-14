<?php

use NoPanic\Laravel\Security\LockfileReader;

it('reads production composer packages only', function () {
    $packages = LockfileReader::composer(__DIR__.'/../Fixtures/composer.lock');

    expect($packages)->toBe([
        ['name' => 'laravel/framework', 'version' => 'v11.9.0'],
        ['name' => 'guzzlehttp/guzzle', 'version' => '7.8.1'],
    ]);
});

it('reads npm lockfile version 3 including nested packages', function () {
    $packages = LockfileReader::npm(__DIR__.'/../Fixtures/package-lock-v3.json');

    expect($packages)->toBe([
        ['name' => 'a', 'version' => '2.0.0', 'dev' => false],
        ['name' => 'axios', 'version' => '1.6.0', 'dev' => false],
        ['name' => 'b', 'version' => '3.1.0', 'dev' => true],
        ['name' => 'vite', 'version' => '5.0.0', 'dev' => true],
    ]);
});

it('reads npm lockfile version 1', function () {
    $packages = LockfileReader::npm(__DIR__.'/../Fixtures/package-lock-v1.json');

    expect($packages)->toBe([
        ['name' => 'a', 'version' => '2.0.0', 'dev' => false],
        ['name' => 'axios', 'version' => '0.21.0', 'dev' => false],
        ['name' => 'b', 'version' => '3.1.0', 'dev' => true],
        ['name' => 'vite', 'version' => '4.0.0', 'dev' => true],
    ]);
});

it('returns null when there is no package-lock.json', function () {
    expect(LockfileReader::npm(__DIR__.'/../Fixtures/missing-package-lock.json'))->toBeNull();
});

it('keeps a package that is both a production and a dev dependency as production and merges duplicates', function () {
    $path = tempnam(sys_get_temp_dir(), 'lock');
    file_put_contents($path, json_encode(['lockfileVersion' => 3, 'packages' => [
        'node_modules/x' => ['version' => '1.0.0', 'dev' => true],
        'node_modules/y/node_modules/x' => ['version' => '1.0.0'],
        'node_modules/z/node_modules/x' => ['version' => '1.0.0'],
    ]]));

    expect(LockfileReader::npm($path))->toBe([['name' => 'x', 'version' => '1.0.0', 'dev' => false]]);

    unlink($path);
});

it('does not throw on an unreadable lockfile', function () {
    $path = tempnam(sys_get_temp_dir(), 'lock');
    file_put_contents($path, '{not json');

    expect(LockfileReader::npm($path))->toBeNull()
        ->and(LockfileReader::composer($path))->toBe([]);

    unlink($path);
});

<?php

use NoPanic\Laravel\Security\SecurityPayload;

it('matches the security payload fixture', function () {
    $basePath = sys_get_temp_dir().'/nopanic-security-fixture-'.uniqid();
    mkdir($basePath);
    copy(__DIR__.'/../Fixtures/composer.lock', $basePath.'/composer.lock');
    copy(__DIR__.'/../Fixtures/package-lock-v3.json', $basePath.'/package-lock.json');
    app()->setBasePath($basePath);

    config([
        'nopanic.stage' => 'production',
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'app.debug' => false,
        'session.secure' => true,
        'session.http_only' => true,
    ]);

    $actual = SecurityPayload::build();
    $actual['environment']['php_version'] = '8.4.0';
    $actual['environment']['laravel_version'] = '11.9.0';

    unlink($basePath.'/composer.lock');
    unlink($basePath.'/package-lock.json');
    rmdir($basePath);

    $path = __DIR__.'/../Fixtures/security-sync-v1.json';

    if (! file_exists($path)) {
        file_put_contents($path, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $this->fail('The fixture tests/Fixtures/security-sync-v1.json was created. Run the test again.');
    }

    expect($actual)->toEqual(json_decode(file_get_contents($path), true));
});

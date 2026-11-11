<?php

use NoPanic\Laravel\Report\ReportFactory;
use NoPanic\Laravel\Tests\Support\PayloadContract;

it('builds a payload that satisfies the ingest contract', function () {
    $payload = app(ReportFactory::class)->make(new LogicException('Something broke', 7), suppressedCount: 2);

    PayloadContract::assertValid($payload);
    expect($payload['exception'])->toMatchArray(['class' => LogicException::class, 'message' => 'Something broke', 'code' => 7, 'level' => 'error']);
    expect($payload['suppressed_count'])->toBe(2);
    expect($payload['seen_at'])->toBeInt()->toBeGreaterThan(1_700_000_000_000);
    expect($payload['notifier'])->toStartWith('nopanic/laravel ');
});

it('uses defaults for missing context and takes context and events when given', function () {
    $payload = app(ReportFactory::class)->make(new LogicException('x'));

    expect($payload)->toMatchArray(['entry_point' => null, 'attributes' => [], 'user' => null, 'events' => []]);
    expect($payload['language_version'])->toBe(PHP_VERSION);
    expect($payload['framework_version'])->toBe(app()->version());

    $payload = app(ReportFactory::class)->make(
        new LogicException('x'),
        ['entry_point' => ['type' => 'web'], 'attributes' => ['request' => []], 'user' => ['id' => '1']],
        [['type' => 'query', 'at' => 1, 'data' => []]],
    );

    expect($payload['entry_point'])->toBe(['type' => 'web']);
    expect($payload['attributes'])->toBe(['request' => []]);
    expect($payload['user'])->toBe(['id' => '1']);
    expect($payload['events'])->toHaveCount(1);
});

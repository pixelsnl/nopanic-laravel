<?php

use Illuminate\Support\Facades\Cache;
use NoPanic\Laravel\Transport\ReportThrottle;

it('sends at most three reports per error per minute and carries the suppressed count', function () {
    $throttle = app(ReportThrottle::class);

    $results = collect(range(1, 5))->map(fn () => $throttle->attempt('RuntimeException|/app/Foo.php|1'));

    expect($results->all())->toBe([0, 0, 0, null, null]);

    $this->travel(1)->minutes();

    expect($throttle->attempt('RuntimeException|/app/Foo.php|1'))->toBe(2);
    expect($throttle->attempt('RuntimeException|/app/Foo.php|1'))->toBe(0);
});

it('throttles each signature separately', function () {
    $throttle = app(ReportThrottle::class);

    foreach (range(1, 3) as $ignored) {
        $throttle->attempt('A|/a.php|1');
    }

    expect($throttle->attempt('A|/a.php|1'))->toBeNull();
    expect($throttle->attempt('B|/b.php|2'))->toBe(0);
});

it('shares the counter through the cache between processes', function () {
    $signature = 'RuntimeException|/app/Foo.php|1';
    $key = 'nopanic:throttle:'.sha1($signature).':'.now()->format('YmdHi');
    Cache::put($key, 3, 120);

    expect(app(ReportThrottle::class)->attempt($signature))->toBeNull();
});

it('falls back to memory when the cache fails', function () {
    Cache::shouldReceive('get')->andThrow(new RuntimeException('cache down'));
    Cache::shouldReceive('put')->andThrow(new RuntimeException('cache down'));
    Cache::shouldReceive('increment')->andThrow(new RuntimeException('cache down'));
    Cache::shouldReceive('add')->andThrow(new RuntimeException('cache down'));

    $throttle = app(ReportThrottle::class);
    $results = collect(range(1, 4))->map(fn () => $throttle->attempt('X|/x.php|1'));

    expect($results->all())->toBe([0, 0, 0, null]);
});

it('does not keep counters of earlier minutes in memory', function () {
    $throttle = app(ReportThrottle::class);

    $throttle->attempt('A|/a.php|1');
    $this->travel(1)->minutes();
    $throttle->attempt('B|/b.php|1');

    $counters = (new ReflectionProperty($throttle, 'counts'))->getValue($throttle);

    expect($counters)->toHaveCount(1);
});

<?php

use NoPanic\Laravel\Report\FrameCollector;

it('marks frames inside the app as application frames and vendor frames not', function () {
    app()->setBasePath(dirname(__DIR__, 2));
    $exception = (fn () => new RuntimeException('boom'))();

    $frames = collect(app(FrameCollector::class)->collect($exception));

    expect($frames->first()['file'])->toStartWith('/tests/');
    expect($frames->first()['line'])->toBe($exception->getLine());
    expect($frames->where('application_frame', true))->not->toBeEmpty();
    expect($frames->filter(fn (array $frame) => str_starts_with($frame['file'], '/vendor/'))->pluck('application_frame')->unique()->all())->toBe([false]);
});

it('adds code snippets around application frame lines', function () {
    app()->setBasePath(dirname(__DIR__, 2));
    $exception = new RuntimeException('boom');

    $firstFrame = app(FrameCollector::class)->collect($exception)[0];

    expect($firstFrame['application_frame'])->toBeTrue();
    expect($firstFrame['code_snippet'])->toHaveKey($exception->getLine());
    expect(count($firstFrame['code_snippet']))->toBeLessThanOrEqual(10);
});

it('caps the number of frames', function () {
    config(['nopanic.max_frames' => 3]);

    expect(app(FrameCollector::class)->collect(new RuntimeException('boom')))->toHaveCount(3);
});

it('keeps absolute paths for frames outside the base path', function () {
    app()->setBasePath(dirname(__DIR__, 2).'/src');
    $exception = new RuntimeException('boom');

    $firstFrame = app(FrameCollector::class)->collect($exception)[0];

    expect($firstFrame['file'])->toBe(__FILE__);
    expect($firstFrame['application_frame'])->toBeFalse();
    expect($firstFrame['code_snippet'])->toBe([]);
});

it('returns an empty snippet for an unreadable file', function () {
    app()->setBasePath(dirname(__DIR__, 2));
    $thrown = new RuntimeException('boom');
    (new ReflectionProperty(Exception::class, 'file'))->setValue($thrown, base_path('tests/missing.php'));

    $firstFrame = app(FrameCollector::class)->collect($thrown)[0];

    expect($firstFrame['file'])->toBe('/tests/missing.php');
    expect($firstFrame['code_snippet'])->toBe([]);
});

it('uses the class and method of the function that contains the line', function () {
    app()->setBasePath(dirname(__DIR__, 2));

    $frames = (new class
    {
        public function fail(): array
        {
            return app(FrameCollector::class)->collect(new RuntimeException('boom'));
        }
    })->fail();

    expect($frames[0]['method'])->toBe('fail');
});

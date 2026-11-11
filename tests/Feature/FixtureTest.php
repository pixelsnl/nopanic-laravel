<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

it('builds a payload for a real request that matches the v1 fixture', function () {
    Http::fake(['*' => Http::response(['id' => 'x'], 202)]);
    config(['nopanic.key' => 'np_test', 'nopanic.stage' => 'production']);
    app()->setBasePath(dirname(__DIR__, 2));

    Route::get('/orders/{order}', function (string $order) {
        DB::select('select 1 as value');
        Log::warning('Order lookup was slow');

        throw new RuntimeException("Order {$order} could not be charged");
    });

    $this->get('/orders/42')->assertServerError();
    app()->terminate();

    $sent = Http::recorded()->map(fn (array $pair) => $pair[0])->filter(fn (Request $request) => $request->method() === 'POST');
    expect($sent)->toHaveCount(1);

    $actual = normalisePayload($sent->first()->data());
    $path = __DIR__.'/../Fixtures/report-v1.json';

    if (! file_exists($path)) {
        file_put_contents($path, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $this->fail('The fixture tests/Fixtures/report-v1.json was created. Run the test again.');
    }

    expect($actual)->toEqual(json_decode(file_get_contents($path), true));
});

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function normalisePayload(array $payload): array
{
    $payload['seen_at'] = 1700000000000;
    $payload['notifier'] = 'nopanic/laravel x.y.z';
    $payload['language_version'] = 'x.y.z';
    $payload['framework_version'] = 'x.y.z';
    $payload['attributes']['environment']['php_version'] = 'x.y.z';
    $payload['attributes']['environment']['laravel_version'] = 'x.y.z';
    $payload['frames'] = normaliseFrames($payload['frames']);

    foreach ($payload['events'] as $index => $event) {
        $payload['events'][$index]['at'] = 1700000000000;

        if (isset($event['data']['time_ms'])) {
            $payload['events'][$index]['data']['time_ms'] = 0;
        }
    }

    return $payload;
}

/**
 * @param  list<array<string, mixed>>  $frames
 * @return list<array<string, mixed>>
 */
function normaliseFrames(array $frames): array
{
    $normalised = [];

    foreach ($frames as $frame) {
        $frame['line'] = 1;
        $frame['method'] = preg_replace('/\{closure:.*\}/', '{closure}', (string) $frame['method']);

        if ($frame['code_snippet'] !== []) {
            $frame['code_snippet'] = ['1' => '<code>'];
        }

        $normalised[] = $frame;

        if ($frame['class'] === 'Illuminate\\Foundation\\Http\\Kernel' && $frame['method'] === 'handle') {
            break;
        }
    }

    return $normalised;
}

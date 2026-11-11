<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use NoPanic\Laravel\Tests\Support\PayloadNormaliser;

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

    $actual = PayloadNormaliser::normalise($sent->first()->data());
    $path = __DIR__.'/../Fixtures/report-v1.json';

    if (! file_exists($path)) {
        file_put_contents($path, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $this->fail('The fixture tests/Fixtures/report-v1.json was created. Run the test again.');
    }

    expect($actual)->toEqual(json_decode(file_get_contents($path), true));
});

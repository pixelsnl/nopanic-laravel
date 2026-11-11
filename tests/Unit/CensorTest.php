<?php

use NoPanic\Laravel\Context\Censor;

it('censors values recursively and case insensitively', function () {
    $result = (new Censor)->values([
        'Password' => 'secret',
        'nested' => ['TOKEN' => 'abc', 'name' => 'Jan', 'deep' => ['api_key' => 'k']],
        'name' => 'Jan',
    ]);

    expect($result)->toBe([
        'Password' => '[censored]',
        'nested' => ['TOKEN' => '[censored]', 'name' => 'Jan', 'deep' => ['api_key' => '[censored]']],
        'name' => 'Jan',
    ]);
});

it('censors headers from the header list', function () {
    $result = (new Censor)->headers(['authorization' => ['Bearer x'], 'accept' => ['text/html'], 'Cookie' => ['a=b']]);

    expect($result)->toBe(['authorization' => ['[censored]'], 'accept' => ['text/html'], 'Cookie' => ['[censored]']]);
});

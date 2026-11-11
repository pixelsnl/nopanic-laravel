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

it('censors every field whose name contains password, token or secret', function () {
    $result = (new Censor)->values([
        'api_token' => 'a',
        'access_token' => 'b',
        'refresh_token' => 'c',
        '_token' => 'd',
        'client_secret' => 'e',
        'new_password' => 'f',
        'signature' => 'g',
        'name' => 'Jan',
    ]);

    expect(array_unique(array_values(array_diff_key($result, ['name' => true]))))->toBe(['[censored]']);
    expect($result['name'])->toBe('Jan');
});

it('censors api key and token headers by default', function () {
    $result = (new Censor)->headers([
        'proxy-authorization' => ['Basic x'],
        'x-api-key' => ['k'],
        'x-auth-token' => ['t'],
        'x-refresh-token' => ['r'],
        'accept' => ['text/html'],
    ]);

    expect($result)->toBe([
        'proxy-authorization' => ['[censored]'],
        'x-api-key' => ['[censored]'],
        'x-auth-token' => ['[censored]'],
        'x-refresh-token' => ['[censored]'],
        'accept' => ['text/html'],
    ]);
});

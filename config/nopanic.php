<?php

return [
    'key' => env('NOPANIC_KEY'),

    'endpoint' => env('NOPANIC_ENDPOINT', 'https://ingest.nopanic.app/api/v1/reports'),

    'enabled_environments' => ['production', 'staging'],

    'stage' => env('NOPANIC_STAGE', env('APP_ENV')),

    'application_version' => env('NOPANIC_APP_VERSION'),

    'send_user' => true,

    'censor' => [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'api_key',
        'secret',
        'credit_card',
        'card_number',
        'cvc',
        'cvv',
        'pin',
        'otp',
        'iban',
        'bsn',
        'ssn',
        'signature',
    ],

    'censor_headers' => [
        'authorization',
        'cookie',
        'set-cookie',
        'x-csrf-token',
        'x-xsrf-token',
        'x-nopanic-key',
        'proxy-authorization',
        'x-api-key',
        'x-auth-token',
        'x-auth-key',
    ],

    'max_events' => 50,

    'max_frames' => 50,

    'snippet_lines' => 10,

    'timeout_seconds' => 2,

    'reports_per_error_per_minute' => 3,
];

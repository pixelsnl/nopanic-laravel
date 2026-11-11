# NoPanic for Laravel

Send unhandled exceptions from your Laravel application to NoPanic.

## Installation

```bash
composer require nopanic/laravel
```

Add your project key to `.env`:

```
NOPANIC_KEY=np_your_key_here
```

Then check the connection:

```bash
php artisan nopanic:test
```

The command sends a test exception, even when the package is not active in the current environment, and prints the result. It exits with a failure code when the key is missing or the ingest rejects it.

## Configuration

Publish the config file with `php artisan vendor:publish --tag=nopanic-config`. The main keys:

| Key | Default | Meaning |
| --- | --- | --- |
| `key` | `env('NOPANIC_KEY')` | Your project key. Without a key nothing is sent. |
| `enabled_environments` | `['production', 'staging']` | Environments in which exceptions are reported. |
| `stage` | `env('NOPANIC_STAGE', env('APP_ENV'))` | Stage label shown in NoPanic. |
| `application_version` | `env('NOPANIC_APP_VERSION')` | Version or commit of your application. |
| `send_user` | `true` | Include id and email of the authenticated user. |
| `censor` | see config | Field names whose values are replaced before sending. |
| `censor_headers` | see config | Header names whose values are replaced before sending. |
| `max_events` | `50` | Number of queries, logs and jobs kept as a trail per request. |
| `reports_per_error_per_minute` | `3` | Local limit per distinct error. Extra occurrences are counted, not sent. |
| `timeout_seconds` | `2` | Timeout for the request to NoPanic. |

## What is sent

* The exception class, message and stack trace with code snippets.
* The request URL, method, route, headers and input, with censored fields replaced.
* The authenticated user, unless `send_user` is `false`.
* A trail of recent queries (SQL text only), log messages and queued jobs.
* Application, PHP and Laravel versions.

## What is not sent

* Query bindings. Only the SQL text is recorded.
* Log context. Only log level and message are recorded.
* Values of censored fields and headers.
* The body of Livewire requests.

Exceptions that Laravel does not report (the `dontReport` list, such as validation and 404 errors) are not sent either. The package never throws into your application: failures while building or sending a report are swallowed. In web requests the report is sent after the response.

## Censoring fields

Add names to the `censor` list in `config/nopanic.php`:

```php
'censor' => [
    'password',
    'token',
    'social_security_number',
],
```

Matching is done on the field name, at any depth of the request input. Header names are configured the same way in `censor_headers`.

## License

MIT.

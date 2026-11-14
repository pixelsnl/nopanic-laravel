# NoPanic for Laravel

Send unhandled exceptions from your Laravel application to NoPanic.

## Requirements

* PHP 8.2 or higher
* Laravel 11, 12 or 13

## Installation

```bash
composer require nopanic/laravel
```

Add your project key to `.env`:

```dotenv
NOPANIC_KEY=np_your_key_here
```

Then check the connection:

```bash
php artisan nopanic:test
```

The command sends a test exception, even when the package is not active in the current environment, and prints the result. It exits with a failure code when the key is missing, the ingest cannot be reached or rejects the key, or building the report fails. It never throws.

## Configuration

Publish the config file with `php artisan vendor:publish --tag=nopanic-config`. The main keys:

| Key | Default | Meaning |
| --- | --- | --- |
| `key` | `env('NOPANIC_KEY')` | Your project key. Without a key nothing is sent. |
| `enabled_environments` | `['production', 'staging']` | Environments in which exceptions are reported. |
| `stage` | `env('NOPANIC_STAGE', env('APP_ENV'))` | Stage label shown in NoPanic. When empty, the application environment is used. |
| `application_version` | `env('NOPANIC_APP_VERSION')` | Version or commit of your application. |
| `send_user` | `true` | Include id and email of the authenticated user. |
| `censor` | see config | Field names whose values are replaced before sending. |
| `censor_headers` | see config | Header names whose values are replaced before sending. |
| `max_events` | `50` | Number of queries, logs and jobs kept as a trail per request, job or command. |
| `reports_per_error_per_minute` | `3` | Local limit per distinct error. Extra occurrences are counted, not sent. |
| `timeout_seconds` | `2` | Timeout for the request to NoPanic. Fractions such as `0.5` are allowed. |
| `schedule_monitoring` | `true` | Ping NoPanic around scheduled tasks. |
| `security` | `true` | Send dependencies and environment facts to NoPanic for security checks. |
| `tasks_endpoint` | `env('NOPANIC_TASKS_ENDPOINT')` | Base URL for scheduled task syncs and pings. When empty, the scheme and host of `endpoint` are used with the path `/api/v1/tasks`. |

## What is sent

* The exception class, message and stack trace with code snippets.
* The request URL, method, route, headers and input, with censored fields replaced.
* The authenticated user, unless `send_user` is `false`.
* A trail of recent queries (SQL text only), log messages and queued jobs. Text fields in the trail are cut off at 2000 characters.
* Application, PHP and Laravel versions.

## What is not sent

* Query bindings. Only the SQL text is recorded. For a failed query the exception message is rebuilt from the database driver message and the SQL text with placeholders, so bindings are left out. Note that some database drivers put values in their own message (for example a duplicate key error), and that message is sent as is.
* Log context. Only log level and message are recorded.
* Values of censored fields and headers.
* Basic auth credentials. The `php-auth-user`, `php-auth-pw` and `php-auth-digest` headers are always removed.
* The body of Livewire requests.
* Values of sensitive route parameters in the URL. A route such as `/reset-password/{token}` is sent as `/reset-password/[censored]`.
* The query string of the `Referer` header.

Exceptions that Laravel does not report (the `dontReport` list, such as validation and 404 errors) are not sent either. The package never throws into your application: failures while building or sending a report are swallowed. In web requests the report is sent after the response. Fatal errors are sent immediately, because the process may not get to the end of the request.

## Censoring fields

Add names to the `censor` list in `config/nopanic.php`:

```php
'censor' => [
    'password',
    'token',
    'social_security_number',
],
```

Matching is done on the field name, at any depth of the request input, and ignores case. A field is censored when its name equals a name in the list, or when its name contains `password`, `passwd`, `pwd`, `token`, `secret`, `apikey`, `api_key`, `private_key` or `credential` (so `api_token`, `refresh_token`, `_token` and `client_secret` are censored without being listed). The same rule decides which route parameters are censored in the URL. Header names are configured the same way in `censor_headers`, and the same rule applies to them.

## Scheduled tasks

NoPanic can watch your scheduled tasks and alert you when one does not start, runs too long or fails. Run this after every deploy (for example in your deploy script) so NoPanic knows your current schedule:

```bash
php artisan nopanic:schedule-sync
```

The command sends the name, command, cron expression and timezone of every task in your schedule. It prints how many tasks were synced, and exits with a failure code when NoPanic cannot be reached or rejects the request. When the package is not active (no key, or the environment is not in `enabled_environments`) it explains that and exits successfully.

While the scheduler runs, the package pings NoPanic when a task starts, finishes or fails. A command that exits with a non zero code counts as failed. When the scheduler skips a task (for example because of `withoutOverlapping`, `onOneServer`, `when` or `skip`), the package sends a skipped ping and NoPanic counts that run as on time. Background tasks are reported as finished when `schedule:finish` completes them. Pings use a timeout of 2 seconds and every error is swallowed, so a slow or unreachable NoPanic never breaks your scheduler. Only the task name, the event and, for failures, the exception message are sent. Command output is never sent.

Tasks are named by their description when one is set, otherwise by their command without `php artisan` and the path to PHP, otherwise by the class of the job or closure followed by a hash of the cron expression. Give a task a stable name with `->description()`:

```php
Schedule::call(new SendInvoices)->dailyAt('08:00')->description('Send invoices');
```

When two tasks end up with the same name, the later ones get a suffix such as `-2`. Renaming a task in your code creates a new task in NoPanic after the next sync. Set `schedule_monitoring` to `false` to turn pings and the sync off.

## Security

NoPanic can check your dependencies against known vulnerabilities and flag risky environment settings. Run this after every deploy (for example in your deploy script):

```bash
php artisan nopanic:security-sync
```

The package also registers this command in your scheduler to run hourly, so changes are picked up without a deploy. It only sends data when something changed since the last run, or when the last run was more than 24 hours ago. Use `--force` to send regardless. The command exits with a failure code when NoPanic cannot be reached or rejects the request, so a broken deploy step is noticeable. When the package is not active it explains that and exits successfully.

What is sent:

* The names and versions of the packages in `composer.lock` (production packages only) and, when it exists, `package-lock.json`.
* The PHP and Laravel versions and the application environment.
* The value of `APP_DEBUG`.
* Whether `APP_KEY` is missing, still a well known example value, or set.
* The `secure` and `http_only` session settings.

What is never sent: your source code, the value of `APP_KEY`, or the value of any other secret or environment variable.

Set `security` to `false` in `config/nopanic.php` to turn the sync and the hourly schedule off. The security sync is not shown as a scheduled task in NoPanic.

## License

MIT. See `LICENSE.md`, which also contains the notices of the third party code this package derives from.

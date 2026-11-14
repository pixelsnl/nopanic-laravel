<?php

namespace NoPanic\Laravel\Security;

class EnvironmentFacts
{
    /**
     * @return array{php_version: string, laravel_version: string, environment: string, app_debug: bool, app_key: 'missing'|'default'|'set', session_secure: ?bool, session_http_only: ?bool}
     */
    public static function collect(): array
    {
        $secure = config('session.secure');
        $httpOnly = config('session.http_only');

        return [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'environment' => app()->environment(),
            'app_debug' => (bool) config('app.debug'),
            'app_key' => static::appKeyState(config('app.key')),
            'session_secure' => $secure === null ? null : (bool) $secure,
            'session_http_only' => $httpOnly === null ? null : (bool) $httpOnly,
        ];
    }

    /**
     * @return 'missing'|'default'|'set'
     */
    protected static function appKeyState(mixed $key): string
    {
        if (! is_string($key) || trim($key) === '') {
            return 'missing';
        }

        $exampleKeys = ['SomeRandomString', 'SomeRandomKey', 'base64:'];

        return in_array($key, $exampleKeys, true) ? 'default' : 'set';
    }
}

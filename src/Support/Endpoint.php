<?php

namespace NoPanic\Laravel\Support;

class Endpoint
{
    public static function api(string $path): string
    {
        $parts = parse_url((string) config('nopanic.endpoint'));
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'ingest.nopanic.app';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return "{$scheme}://{$host}{$port}/api/v1/".ltrim($path, '/');
    }
}

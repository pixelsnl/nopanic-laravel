<?php

namespace NoPanic\Laravel\Schedule;

use Illuminate\Support\Facades\Http;
use Throwable;

class TaskPinger
{
    public static function endpoint(): string
    {
        $configured = config('nopanic.tasks_endpoint');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        $parts = parse_url((string) config('nopanic.endpoint'));
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'ingest.nopanic.app';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return "{$scheme}://{$host}{$port}/api/v1/tasks";
    }

    public function ping(string $name, string $event, ?string $output = null): void
    {
        try {
            $payload = ['name' => $name, 'event' => $event];

            if ($output !== null && $output !== '') {
                $payload['output'] = mb_substr($output, 0, 2000);
            }

            Http::withHeaders(['X-NoPanic-Key' => (string) config('nopanic.key')])
                ->acceptJson()
                ->asJson()
                ->timeout(2)
                ->connectTimeout(1)
                ->post(static::endpoint().'/ping', $payload);
        } catch (Throwable) {
            return;
        }
    }
}

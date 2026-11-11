<?php

namespace NoPanic\Laravel\Transport;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class Transport
{
    protected int $pausedUntil = 0;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(array $payload): void
    {
        $this->sendNow($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sendNow(array $payload): ?int
    {
        try {
            if ($this->isPaused()) {
                return null;
            }

            $body = $this->encode($payload);

            if ($body === null) {
                return null;
            }

            $response = Http::withBody($body, 'application/json')
                ->withHeaders(['X-NoPanic-Key' => (string) config('nopanic.key')])
                ->acceptJson()
                ->timeout((int) config('nopanic.timeout_seconds', 2))
                ->connectTimeout(1)
                ->post((string) config('nopanic.endpoint'));

            if ($response->status() === 429) {
                $this->pauseFor((int) $response->header('Retry-After'));
            }

            return $response->status();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function encode(array $payload): ?string
    {
        $maxBytes = 900000;

        $body = $this->toJson($payload);

        if (strlen($body) <= $maxBytes) {
            return $body;
        }

        $payload['events'] = [];
        $body = $this->toJson($payload);

        if (strlen($body) <= $maxBytes) {
            return $body;
        }

        unset($payload['attributes']['request']['body']);
        $body = $this->toJson($payload);

        if (strlen($body) <= $maxBytes) {
            return $body;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function toJson(array $payload): string
    {
        return json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
    }

    protected function isPaused(): bool
    {
        $now = now()->getTimestamp();

        if ($this->pausedUntil > $now) {
            return true;
        }

        try {
            $cachedUntil = (int) Cache::get('nopanic:paused-until', 0);
        } catch (Throwable) {
            return false;
        }

        if ($cachedUntil > $now) {
            $this->pausedUntil = $cachedUntil;

            return true;
        }

        return false;
    }

    protected function pauseFor(int $seconds): void
    {
        $seconds = min(max($seconds, 1), 3600);
        $this->pausedUntil = now()->getTimestamp() + $seconds;

        try {
            Cache::put('nopanic:paused-until', $this->pausedUntil, $seconds);
        } catch (Throwable) {
        }
    }
}

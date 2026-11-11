<?php

namespace NoPanic\Laravel\Tests\Support;

class PayloadNormaliser
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function normalise(array $payload): array
    {
        $payload['seen_at'] = 1700000000000;
        $payload['notifier'] = 'nopanic/laravel x.y.z';
        $payload['language_version'] = 'x.y.z';
        $payload['framework_version'] = 'x.y.z';
        $payload['attributes']['environment']['php_version'] = 'x.y.z';
        $payload['attributes']['environment']['laravel_version'] = 'x.y.z';
        $payload['frames'] = self::normaliseFrames($payload['frames']);

        foreach ($payload['events'] as $index => $event) {
            $payload['events'][$index]['at'] = 1700000000000;

            if (isset($event['data']['time_ms'])) {
                $payload['events'][$index]['data']['time_ms'] = 0;
            }
        }

        return $payload;
    }

    /**
     * @param  list<array<string, mixed>>  $frames
     * @return list<array<string, mixed>>
     */
    protected static function normaliseFrames(array $frames): array
    {
        $normalised = [];

        foreach ($frames as $frame) {
            $frame['line'] = 1;
            $frame['method'] = preg_replace('/\{closure:.*\}/', '{closure}', (string) $frame['method']);

            if ($frame['code_snippet'] !== []) {
                $frame['code_snippet'] = ['1' => '<code>'];
            }

            $normalised[] = $frame;

            if (str_starts_with($frame['file'], '/vendor/laravel/framework/src/Illuminate/Routing/')) {
                break;
            }
        }

        return $normalised;
    }
}

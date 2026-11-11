<?php

namespace NoPanic\Laravel\Report;

use Composer\InstalledVersions;
use Throwable;

class ReportFactory
{
    public function __construct(protected FrameCollector $frameCollector) {}

    /**
     * @param  array{entry_point?: ?array<string, mixed>, attributes?: array<string, mixed>, user?: ?array<string, mixed>}  $context
     * @param  array<int, array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    public function make(Throwable $exception, array $context = [], array $events = [], int $suppressedCount = 0): array
    {
        return [
            'notifier' => 'nopanic/laravel '.$this->packageVersion(),
            'seen_at' => (int) floor(microtime(true) * 1000),
            'stage' => config('nopanic.stage'),
            'application_version' => config('nopanic.application_version'),
            'language_version' => PHP_VERSION,
            'framework_version' => app()->version(),
            'exception' => [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'level' => 'error',
            ],
            'frames' => $this->frameCollector->collect($exception),
            'entry_point' => $context['entry_point'] ?? null,
            'attributes' => $context['attributes'] ?? [],
            'user' => $context['user'] ?? null,
            'events' => $events,
            'suppressed_count' => $suppressedCount,
        ];
    }

    protected function packageVersion(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('nopanic/laravel') ?? 'dev';
        } catch (Throwable) {
            return 'dev';
        }
    }
}

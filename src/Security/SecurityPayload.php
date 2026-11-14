<?php

namespace NoPanic\Laravel\Security;

class SecurityPayload
{
    /**
     * @return array{stage: string, composer: list<array{name: string, version: string}>, npm: list<array{name: string, version: string, dev: bool}>|null, environment: array<string, mixed>}
     */
    public static function build(): array
    {
        $stage = config('nopanic.stage');

        return [
            'stage' => is_string($stage) && $stage !== '' ? $stage : app()->environment(),
            'composer' => LockfileReader::composer(base_path('composer.lock')),
            'npm' => LockfileReader::npm(base_path('package-lock.json')),
            'environment' => EnvironmentFacts::collect(),
        ];
    }
}

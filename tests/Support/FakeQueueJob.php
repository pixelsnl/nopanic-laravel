<?php

namespace NoPanic\Laravel\Tests\Support;

use Illuminate\Contracts\Queue\Job;

class FakeQueueJob implements Job
{
    public function __construct(protected string $name) {}

    public function uuid(): ?string
    {
        return null;
    }

    public function getJobId(): string|int|null
    {
        return null;
    }

    public function payload(): array
    {
        return [];
    }

    public function fire(): void {}

    public function release($delay = 0): void {}

    public function isReleased(): bool
    {
        return false;
    }

    public function delete(): void {}

    public function isDeleted(): bool
    {
        return false;
    }

    public function isDeletedOrReleased(): bool
    {
        return false;
    }

    public function attempts(): int
    {
        return 1;
    }

    public function hasFailed(): bool
    {
        return false;
    }

    public function markAsFailed(): void {}

    public function fail($e = null): void {}

    public function maxTries(): ?int
    {
        return null;
    }

    public function maxExceptions(): ?int
    {
        return null;
    }

    public function timeout(): ?int
    {
        return null;
    }

    public function retryUntil(): ?int
    {
        return null;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function resolveName(): string
    {
        return $this->name;
    }

    public function resolveQueuedJobClass(): string
    {
        return $this->name;
    }

    public function getConnectionName(): string
    {
        return 'sync';
    }

    public function getQueue(): string
    {
        return 'default';
    }

    public function getRawBody(): string
    {
        return '';
    }

    public function backoff(): mixed
    {
        return null;
    }
}

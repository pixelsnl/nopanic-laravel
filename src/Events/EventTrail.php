<?php

namespace NoPanic\Laravel\Events;

use Illuminate\Contracts\Config\Repository;

class EventTrail
{
    /** @var list<array{type: string, at: int, data: array<string, mixed>}> */
    protected array $events = [];

    public function __construct(protected Repository $config) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(string $type, array $data): void
    {
        $this->events[] = [
            'type' => $type,
            'at' => (int) (microtime(true) * 1000),
            'data' => $data,
        ];

        $maxEvents = max(0, (int) $this->config->get('nopanic.max_events', 50));

        if (count($this->events) > $maxEvents) {
            $this->events = array_slice($this->events, count($this->events) - $maxEvents);
        }
    }

    /**
     * @return list<array{type: string, at: int, data: array<string, mixed>}>
     */
    public function all(): array
    {
        return $this->events;
    }

    public function flush(): void
    {
        $this->events = [];
    }
}

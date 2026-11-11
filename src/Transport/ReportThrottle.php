<?php

namespace NoPanic\Laravel\Transport;

use Illuminate\Support\Facades\Cache;
use Throwable;

class ReportThrottle
{
    /** @var array<string, int> */
    protected array $counts = [];

    /** @var array<string, int> */
    protected array $skipped = [];

    protected string $currentMinute = '';

    public function attempt(string $signature): ?int
    {
        $minute = now()->format('YmdHi');
        $hash = sha1($signature);

        if ($minute !== $this->currentMinute) {
            $this->counts = [];
            $this->currentMinute = $minute;
        }

        $key = "nopanic:throttle:{$hash}:{$minute}";
        $count = max($this->counts[$key] ?? 0, $this->cachedCount($key));

        if ($count >= (int) config('nopanic.reports_per_error_per_minute', 3)) {
            $this->rememberSkip($hash);

            return null;
        }

        $this->counts[$key] = $count + 1;
        $this->storeCount($key, $count + 1);

        $suppressed = $this->skipped[$hash] ?? 0;
        unset($this->skipped[$hash]);

        return $suppressed;
    }

    protected function rememberSkip(string $hash): void
    {
        $this->skipped[$hash] = ($this->skipped[$hash] ?? 0) + 1;

        if (count($this->skipped) > 500) {
            $this->skipped = array_slice($this->skipped, -250, null, true);
        }
    }

    protected function cachedCount(string $key): int
    {
        try {
            return (int) Cache::get($key, 0);
        } catch (Throwable) {
            return 0;
        }
    }

    protected function storeCount(string $key, int $count): void
    {
        try {
            Cache::put($key, $count, 120);
        } catch (Throwable) {
        }
    }
}

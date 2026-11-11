<?php

namespace NoPanic\Laravel\Transport;

use NoPanic\Laravel\Support\GuardedCache;

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
        $count = max($this->counts[$key] ?? 0, (int) GuardedCache::get($key, 0));

        if ($count >= (int) config('nopanic.reports_per_error_per_minute', 3)) {
            $this->rememberSkips($hash, 1);

            return null;
        }

        $this->counts[$key] = $count + 1;
        GuardedCache::put($key, $count + 1, 120);

        return $this->pullSkipped($hash);
    }

    public function recordSkipped(string $signature, int $times): void
    {
        if ($times < 1) {
            return;
        }

        $this->rememberSkips(sha1($signature), $times);
    }

    protected function rememberSkips(string $hash, int $times): void
    {
        $this->skipped[$hash] = ($this->skipped[$hash] ?? 0) + $times;

        if (count($this->skipped) > 500) {
            $this->skipped = array_slice($this->skipped, -250, null, true);
        }

        GuardedCache::increment($this->skippedKey($hash), $times, 3600);
    }

    protected function pullSkipped(string $hash): int
    {
        $skippedInMemory = $this->skipped[$hash] ?? 0;
        unset($this->skipped[$hash]);

        $skippedInCache = (int) GuardedCache::pull($this->skippedKey($hash));

        return max($skippedInMemory, $skippedInCache);
    }

    protected function skippedKey(string $hash): string
    {
        return "nopanic:skipped:{$hash}";
    }
}

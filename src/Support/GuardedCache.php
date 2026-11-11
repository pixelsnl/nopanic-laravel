<?php

namespace NoPanic\Laravel\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

class GuardedCache
{
    protected static bool $isDisabled = false;

    public static function isDisabled(): bool
    {
        return self::$isDisabled;
    }

    public static function enable(): void
    {
        self::$isDisabled = false;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::attempt(fn () => Cache::get($key, $default), $default);
    }

    public static function put(string $key, mixed $value, int $seconds): void
    {
        self::attempt(fn () => Cache::put($key, $value, $seconds));
    }

    public static function increment(string $key, int $amount, int $seconds): ?int
    {
        return self::attempt(function () use ($key, $amount, $seconds) {
            Cache::add($key, 0, $seconds);

            $value = Cache::increment($key, $amount);

            return is_numeric($value) ? (int) $value : null;
        });
    }

    public static function pull(string $key): mixed
    {
        return self::attempt(fn () => Cache::pull($key));
    }

    protected static function attempt(Closure $operation, mixed $fallback = null): mixed
    {
        if (self::$isDisabled) {
            return $fallback;
        }

        try {
            return $operation();
        } catch (Throwable) {
            self::$isDisabled = true;

            return $fallback;
        }
    }
}

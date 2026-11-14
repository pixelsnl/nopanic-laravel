<?php

namespace NoPanic\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use NoPanic\Laravel\Security\SecurityPayload;
use NoPanic\Laravel\Support\Activation;
use NoPanic\Laravel\Support\Endpoint;
use NoPanic\Laravel\Support\GuardedCache;
use Throwable;

class SecuritySyncCommand extends Command
{
    protected $signature = 'nopanic:security-sync {--force : Send even when nothing changed}';

    protected $description = 'Send the dependencies and environment facts of this application to NoPanic';

    public function handle(Activation $activation): int
    {
        try {
            return $this->sync($activation);
        } catch (Throwable $exception) {
            $this->components->error("Security sync failed: {$exception->getMessage()}");

            return self::FAILURE;
        }
    }

    protected function sync(Activation $activation): int
    {
        if (! $activation->isEnabled()) {
            $this->components->warn('NoPanic is not active (no key, or this environment is not in enabled_environments). Nothing was synced.');

            return self::SUCCESS;
        }

        if (! config('nopanic.security', true)) {
            $this->components->warn('Security checks are disabled (nopanic.security). Nothing was synced.');

            return self::SUCCESS;
        }

        $payload = SecurityPayload::build();
        $hash = sha1((string) json_encode($payload));

        if (! $this->option('force') && ! $this->isDue($hash)) {
            $this->components->info('Nothing changed since the last security sync.');

            return self::SUCCESS;
        }

        try {
            $response = Http::withHeaders(['X-NoPanic-Key' => (string) config('nopanic.key')])
                ->acceptJson()
                ->asJson()
                ->timeout(10)
                ->connectTimeout(5)
                ->post(Endpoint::api('security/sync'), $payload);
        } catch (Throwable $exception) {
            $this->components->error("Could not reach NoPanic: {$exception->getMessage()}");

            return self::FAILURE;
        }

        if ($response->status() === 401) {
            $this->components->error('NoPanic rejected the key. Check NOPANIC_KEY.');

            return self::FAILURE;
        }

        if (in_array($response->status(), [413, 422], true)) {
            $this->components->error("NoPanic rejected the security data (HTTP {$response->status()}): ".$response->body());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->components->error("NoPanic responded with HTTP status {$response->status()}.");

            return self::FAILURE;
        }

        $thirtyDays = 60 * 60 * 24 * 30;

        GuardedCache::put('nopanic:security:hash', $hash, $thirtyDays);
        GuardedCache::put('nopanic:security:sent_at', now()->getTimestamp(), $thirtyDays);

        $this->components->info('Sent '.count($payload['composer']).' composer packages and '.count($payload['npm'] ?? []).' npm packages to NoPanic.');

        return self::SUCCESS;
    }

    protected function isDue(string $hash): bool
    {
        $sentAt = GuardedCache::get('nopanic:security:sent_at');

        if (GuardedCache::get('nopanic:security:hash') !== $hash || ! is_int($sentAt)) {
            return true;
        }

        return now()->getTimestamp() - $sentAt >= 60 * 60 * 24;
    }
}

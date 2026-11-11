<?php

namespace NoPanic\Laravel\Commands;

use Illuminate\Console\Command;
use NoPanic\Laravel\Context\ContextCollector;
use NoPanic\Laravel\Events\EventTrail;
use NoPanic\Laravel\Exceptions\NoPanicTestException;
use NoPanic\Laravel\Report\ReportFactory;
use NoPanic\Laravel\Transport\Transport;
use Throwable;

class TestCommand extends Command
{
    protected $signature = 'nopanic:test';

    protected $description = 'Send a test exception to NoPanic';

    public function handle(): int
    {
        $key = config('nopanic.key');

        if (! is_string($key) || $key === '') {
            $this->components->error('No NoPanic key found. Set NOPANIC_KEY in your .env file.');

            return self::FAILURE;
        }

        try {
            $status = $this->send();
        } catch (Throwable $exception) {
            $this->components->error("Could not send the test exception: {$exception->getMessage()}");

            return self::FAILURE;
        }

        if ($status === null) {
            $this->components->error('Could not reach NoPanic. Check your endpoint and network connection.');

            return self::FAILURE;
        }

        if ($status === 202) {
            $this->components->info('Test exception sent. It should appear in NoPanic shortly.');

            return self::SUCCESS;
        }

        $this->components->error("NoPanic responded with HTTP status {$status}.");

        return self::FAILURE;
    }

    protected function send(): ?int
    {
        $payload = $this->laravel->make(ReportFactory::class)->make(
            new NoPanicTestException('This is a test exception sent by nopanic:test.'),
            $this->laravel->make(ContextCollector::class)->collect(),
            $this->laravel->make(EventTrail::class)->all(),
        );

        return $this->laravel->make(Transport::class)->sendNow($payload);
    }
}

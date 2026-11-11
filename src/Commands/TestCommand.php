<?php

namespace NoPanic\Laravel\Commands;

use Illuminate\Console\Command;
use NoPanic\Laravel\Context\ContextCollector;
use NoPanic\Laravel\Events\EventTrail;
use NoPanic\Laravel\Exceptions\NoPanicTestException;
use NoPanic\Laravel\Report\ReportFactory;
use NoPanic\Laravel\Transport\Transport;

class TestCommand extends Command
{
    protected $signature = 'nopanic:test';

    protected $description = 'Send a test exception to NoPanic';

    public function handle(ReportFactory $reportFactory, ContextCollector $contextCollector, EventTrail $eventTrail, Transport $transport): int
    {
        $key = config('nopanic.key');

        if (! is_string($key) || $key === '') {
            $this->components->error('No NoPanic key found. Set NOPANIC_KEY in your .env file.');

            return self::FAILURE;
        }

        $payload = $reportFactory->make(
            new NoPanicTestException('This is a test exception sent by nopanic:test.'),
            $contextCollector->collect(),
            $eventTrail->all(),
        );

        $status = $transport->sendNow($payload);

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
}

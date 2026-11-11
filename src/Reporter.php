<?php

namespace NoPanic\Laravel;

use Illuminate\Contracts\Foundation\Application;
use NoPanic\Laravel\Context\ContextCollector;
use NoPanic\Laravel\Events\EventTrail;
use NoPanic\Laravel\Exceptions\NoPanicTestException;
use NoPanic\Laravel\Report\ReportFactory;
use NoPanic\Laravel\Support\Activation;
use NoPanic\Laravel\Transport\ReportThrottle;
use NoPanic\Laravel\Transport\Transport;
use Throwable;

class Reporter
{
    protected bool $isReporting = false;

    public function __construct(protected Application $application) {}

    public function report(Throwable $exception): void
    {
        if ($this->isReporting) {
            return;
        }

        $this->isReporting = true;

        try {
            $this->handle($exception);
        } catch (Throwable) {
        } finally {
            $this->isReporting = false;
        }
    }

    protected function handle(Throwable $exception): void
    {
        if (! $this->application->make(Activation::class)->isEnabled()) {
            return;
        }

        if ($this->isOwnException($exception)) {
            return;
        }

        $suppressedCount = $this->application->make(ReportThrottle::class)->attempt($this->signature($exception));

        if ($suppressedCount === null) {
            return;
        }

        $payload = $this->application->make(ReportFactory::class)->make(
            $exception,
            $this->application->make(ContextCollector::class)->collect(),
            $this->application->make(EventTrail::class)->all(),
            $suppressedCount,
        );

        if ($this->isHandlingHttpRequest()) {
            $this->application->terminating(fn () => $this->sendGuarded($payload));

            return;
        }

        $this->application->make(Transport::class)->send($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function sendGuarded(array $payload): void
    {
        if ($this->isReporting) {
            return;
        }

        $this->isReporting = true;

        try {
            $this->application->make(Transport::class)->send($payload);
        } catch (Throwable) {
        } finally {
            $this->isReporting = false;
        }
    }

    protected function isOwnException(Throwable $exception): bool
    {
        if ($exception instanceof NoPanicTestException) {
            return false;
        }

        return str_starts_with($exception::class, 'NoPanic\\Laravel\\');
    }

    protected function signature(Throwable $exception): string
    {
        return $exception::class.'|'.$exception->getFile().'|'.$exception->getLine();
    }

    protected function isHandlingHttpRequest(): bool
    {
        if (! $this->application->runningInConsole()) {
            return true;
        }

        return $this->application->bound('router') && $this->application['router']->current() !== null;
    }
}

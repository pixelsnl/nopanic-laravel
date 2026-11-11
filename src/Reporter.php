<?php

namespace NoPanic\Laravel;

use Illuminate\Foundation\Application;
use NoPanic\Laravel\Context\ContextCollector;
use NoPanic\Laravel\Events\EventTrail;
use NoPanic\Laravel\Exceptions\NoPanicTestException;
use NoPanic\Laravel\Report\ReportFactory;
use NoPanic\Laravel\Support\Activation;
use NoPanic\Laravel\Transport\ReportThrottle;
use NoPanic\Laravel\Transport\Transport;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Throwable;

class Reporter
{
    protected bool $isReporting = false;

    /** @var list<array<string, mixed>> */
    protected array $pending = [];

    protected bool $shutdownRegistered = false;

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
        $application = app();

        if (! $application->make(Activation::class)->isEnabled()) {
            return;
        }

        if ($this->isOwnException($exception)) {
            return;
        }

        $payload = $application->make(ReportFactory::class)->make(
            $exception,
            $application->make(ContextCollector::class)->collect(),
            $application->make(EventTrail::class)->all(),
        );

        $suppressedCount = $application->make(ReportThrottle::class)->attempt($this->signature($exception));

        if ($suppressedCount === null) {
            return;
        }

        $payload['suppressed_count'] = $suppressedCount;

        if ($exception instanceof FatalError || ! $this->isHandlingHttpRequest($application)) {
            $application->make(Transport::class)->send($payload);

            return;
        }

        $this->defer($application, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function defer(Application $application, array $payload): void
    {
        $this->pending[] = $payload;

        if (! $this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function(fn () => $this->flushPending());
        }

        $application->terminating(fn () => $this->flushPending());
    }

    public function flushPending(): void
    {
        $payloads = $this->pending;
        $this->pending = [];

        foreach ($payloads as $payload) {
            $this->sendGuarded($payload);
        }
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
            app(Transport::class)->send($payload);
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

    protected function isHandlingHttpRequest(Application $application): bool
    {
        if (! $application->runningInConsole()) {
            return true;
        }

        return $application->bound('router') && $application['router']->current() !== null;
    }
}

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
use Symfony\Component\ErrorHandler\Error\FatalError;
use Throwable;

class Reporter
{
    protected static bool $shutdownRegistered = false;

    protected static ?Reporter $reporterToFlushOnShutdown = null;

    protected bool $isReporting = false;

    /** @var list<array{signature: string, payload: array<string, mixed>}> */
    protected array $pending = [];

    /** @var array<string, int> */
    protected array $droppedBeforeSending = [];

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

        $signature = $this->signature($exception);

        if ($exception instanceof FatalError || ! $this->isHandlingHttpRequest($application)) {
            $this->throttleAndSend($signature, $payload);

            return;
        }

        $this->defer($application, $signature, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function defer(Application $application, string $signature, array $payload): void
    {
        $pendingWithSameSignature = count(array_filter(
            $this->pending,
            fn (array $pending) => $pending['signature'] === $signature,
        ));

        if ($pendingWithSameSignature >= max(1, (int) config('nopanic.reports_per_error_per_minute', 3))) {
            $this->droppedBeforeSending[$signature] = ($this->droppedBeforeSending[$signature] ?? 0) + 1;

            return;
        }

        $this->pending[] = ['signature' => $signature, 'payload' => $payload];

        $this->registerShutdownFlush();

        $application->terminating(fn () => $this->flushPending());
    }

    protected function registerShutdownFlush(): void
    {
        self::$reporterToFlushOnShutdown = $this;

        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;

        register_shutdown_function(static function (): void {
            self::$reporterToFlushOnShutdown?->flushPending();
        });
    }

    public function flushPending(): void
    {
        if ($this->isReporting) {
            return;
        }

        $this->isReporting = true;

        try {
            $pending = $this->pending;
            $dropped = $this->droppedBeforeSending;
            $this->pending = [];
            $this->droppedBeforeSending = [];

            foreach ($pending as $report) {
                $this->sendGuarded($report['signature'], $report['payload']);
            }

            $this->recordDropped($dropped);
        } finally {
            $this->isReporting = false;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function sendGuarded(string $signature, array $payload): void
    {
        try {
            $this->throttleAndSend($signature, $payload);
        } catch (Throwable) {
        }
    }

    /**
     * @param  array<string, int>  $dropped
     */
    protected function recordDropped(array $dropped): void
    {
        try {
            $throttle = app(ReportThrottle::class);

            foreach ($dropped as $signature => $times) {
                $throttle->recordSkipped($signature, $times);
            }
        } catch (Throwable) {
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function throttleAndSend(string $signature, array $payload): void
    {
        $suppressedCount = app(ReportThrottle::class)->attempt($signature);

        if ($suppressedCount === null) {
            return;
        }

        $payload['suppressed_count'] = $suppressedCount;

        app(Transport::class)->send($payload);
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

        if (! $application->bound('router')) {
            return false;
        }

        return $application->make('router')->current() !== null;
    }
}

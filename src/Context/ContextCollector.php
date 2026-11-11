<?php

namespace NoPanic\Laravel\Context;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Throwable;

class ContextCollector
{
    public function __construct(protected Censor $censor) {}

    /**
     * @return array{entry_point: ?array<string, mixed>, attributes: array<string, mixed>, user: ?array<string, mixed>}
     */
    public function collect(): array
    {
        try {
            $request = $this->resolveRequest();
            $entryPoint = EntryPoint::current();

            $user = $this->user();
            $attributes = ['environment' => $this->environment()];

            if ($entryPoint === null && $request !== null) {
                $entryPoint = $this->webEntryPoint($request);
                $requestAttributes = $this->requestAttributes($request);

                if ($entryPoint['type'] === 'livewire') {
                    $requestAttributes = $this->livewireRequestAttributes($request, $requestAttributes);
                }

                $attributes = ['request' => $requestAttributes] + $attributes;
            }

            $attributes = ['user' => $user ?? []] + $attributes;

            return [
                'entry_point' => $entryPoint,
                'attributes' => $attributes,
                'user' => $user,
            ];
        } catch (Throwable) {
            return ['entry_point' => null, 'attributes' => [], 'user' => null];
        }
    }

    protected function resolveRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        if (! $request instanceof Request) {
            return null;
        }

        if (! app()->runningInConsole()) {
            return $request;
        }

        if ($request->route() instanceof Route) {
            return $request;
        }

        return $request->server->has('argv') ? null : $request;
    }

    /**
     * @return array{type: string, identifier: ?string, handler: ?string, url: ?string}
     */
    protected function webEntryPoint(Request $request): array
    {
        $route = $request->route();
        $route = $route instanceof Route ? $route : null;

        $livewireComponent = $this->livewireComponent($request, $route);

        if ($livewireComponent !== null) {
            return [
                'type' => 'livewire',
                'identifier' => $this->identifier($request, $route),
                'handler' => $livewireComponent,
                'url' => $this->url($request),
            ];
        }

        return [
            'type' => 'web',
            'identifier' => $this->identifier($request, $route),
            'handler' => $this->handler($route),
            'url' => $this->url($request),
        ];
    }

    protected function identifier(Request $request, ?Route $route): string
    {
        $path = $route?->uri() ?? $request->path();

        return "{$request->method()} {$path}";
    }

    protected function handler(?Route $route): ?string
    {
        $action = $route?->getActionName();

        if ($action === null || $action === 'Closure') {
            return null;
        }

        return $action;
    }

    protected function url(Request $request): string
    {
        return $request->url();
    }

    protected function livewireComponent(Request $request, ?Route $route): ?string
    {
        if (! $this->isLivewireUpdate($request, $route)) {
            return null;
        }

        $snapshot = $request->input('components.0.snapshot');

        if (! is_string($snapshot)) {
            return 'livewire';
        }

        $decoded = json_decode($snapshot, true);

        $name = is_array($decoded) ? ($decoded['memo']['name'] ?? null) : null;

        return is_string($name) ? $name : 'livewire';
    }

    protected function isLivewireUpdate(Request $request, ?Route $route): bool
    {
        if (str_ends_with((string) $route?->getName(), 'livewire.update')) {
            return true;
        }

        return preg_match('#(^|/)livewire(-[a-f0-9]+)?/update$#', trim($request->path(), '/')) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    protected function requestAttributes(Request $request): array
    {
        $attributes = [
            'method' => $request->method(),
            'url' => $this->url($request),
            'query' => $this->censor->values($request->query->all()),
        ];

        $body = $this->body($request);

        if ($body !== null) {
            $attributes['body'] = $this->censor->values($body);
        }

        $attributes['headers'] = $this->censor->headers($request->headers->all());
        $attributes['ip'] = $request->ip();

        return $attributes;
    }

    /**
     * @return ?array<array-key, mixed>
     */
    protected function body(Request $request): ?array
    {
        $isJson = $request->isJson();
        $isForm = $request->getContentTypeFormat() === 'form';

        if (! $isJson && ! $isForm) {
            return null;
        }

        $body = $isJson ? $request->json()->all() : $request->request->all();

        $size = max(strlen($request->getContent()), strlen((string) json_encode($body)));

        $maxBytes = 10240;

        return $size > $maxBytes ? null : $body;
    }

    /**
     * @return array<string, mixed>
     */
    protected function environment(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'app_env' => app()->environment(),
            'app_debug' => (bool) config('app.debug'),
            'locale' => app()->getLocale(),
        ];
    }

    /**
     * @return ?array{id: string, email: ?string}
     */
    protected function user(): ?array
    {
        try {
            if (! config('nopanic.send_user')) {
                return null;
            }

            $user = auth()->user();

            if ($user === null) {
                return null;
            }

            $email = $user->email ?? null;

            return [
                'id' => (string) $user->getAuthIdentifier(),
                'email' => is_string($email) ? $email : null,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $requestAttributes
     * @return array<string, mixed>
     */
    protected function livewireRequestAttributes(Request $request, array $requestAttributes): array
    {
        unset($requestAttributes['body']);

        $components = [];
        $calls = [];

        foreach ((array) $request->input('components', []) as $component) {
            $snapshot = is_array($component) && is_string($component['snapshot'] ?? null)
                ? json_decode($component['snapshot'], true)
                : null;

            $name = is_array($snapshot) ? ($snapshot['memo']['name'] ?? null) : null;

            if (is_string($name)) {
                $components[] = $name;
            }

            foreach ((array) ($component['calls'] ?? []) as $call) {
                $method = is_array($call) ? ($call['method'] ?? null) : null;

                if (is_string($method)) {
                    $calls[] = $method;
                }
            }
        }

        $requestAttributes['livewire'] = ['components' => $components, 'calls' => $calls];

        return $requestAttributes;
    }
}

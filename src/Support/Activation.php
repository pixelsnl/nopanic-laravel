<?php

namespace NoPanic\Laravel\Support;

use Illuminate\Contracts\Foundation\Application;

class Activation
{
    public function __construct(private Application $application) {}

    public function isEnabled(): bool
    {
        $key = $this->application['config']->get('nopanic.key');

        if (! is_string($key) || $key === '') {
            return false;
        }

        $environments = (array) $this->application['config']->get('nopanic.enabled_environments', []);

        return $this->application->environment($environments);
    }
}

<?php

namespace NoPanic\Laravel\Context;

class Censor
{
    protected string $mask = '[censored]';

    /** @var array<int, string> */
    protected array $sensitiveFragments = ['password', 'passwd', 'pwd', 'token', 'secret', 'apikey', 'api_key', 'private_key', 'credential'];

    /** @var array<int, string> */
    protected array $basicAuthHeaders = ['php-auth-user', 'php-auth-pw', 'php-auth-digest'];

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function values(array $data): array
    {
        return $this->censor($data, $this->keys('nopanic.censor'));
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    public function headers(array $headers): array
    {
        $keys = $this->keys('nopanic.censor_headers');
        $censored = [];

        foreach ($headers as $name => $value) {
            $lowercaseName = strtolower((string) $name);

            if (in_array($lowercaseName, $this->basicAuthHeaders, true)) {
                continue;
            }

            if (! $this->isSensitive($lowercaseName, $keys)) {
                $censored[$name] = $value;

                continue;
            }

            $censored[$name] = is_array($value) ? array_map(fn () => $this->mask, $value) : $this->mask;
        }

        return $censored;
    }

    public function isSensitiveName(string $name): bool
    {
        return $this->isSensitive(strtolower($name), $this->keys('nopanic.censor'));
    }

    public function mask(): string
    {
        return $this->mask;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<int, string>  $keys
     * @return array<array-key, mixed>
     */
    protected function censor(array $data, array $keys): array
    {
        foreach ($data as $key => $value) {
            if ($this->isSensitive(strtolower((string) $key), $keys)) {
                $data[$key] = $this->mask;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->censor($value, $keys);
            }
        }

        return $data;
    }

    /**
     * @param  array<int, string>  $keys
     */
    protected function isSensitive(string $lowercaseName, array $keys): bool
    {
        if (in_array($lowercaseName, $keys, true)) {
            return true;
        }

        foreach ($this->sensitiveFragments as $fragment) {
            if (str_contains($lowercaseName, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    protected function keys(string $configKey): array
    {
        return array_map(fn ($key) => strtolower((string) $key), (array) config($configKey, []));
    }
}

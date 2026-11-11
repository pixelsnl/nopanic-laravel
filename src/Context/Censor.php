<?php

namespace NoPanic\Laravel\Context;

class Censor
{
    protected string $mask = '[censored]';

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
            if (! in_array(strtolower((string) $name), $keys, true)) {
                $censored[$name] = $value;

                continue;
            }

            $censored[$name] = is_array($value) ? array_map(fn () => $this->mask, $value) : $this->mask;
        }

        return $censored;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<int, string>  $keys
     * @return array<array-key, mixed>
     */
    protected function censor(array $data, array $keys): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), $keys, true)) {
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
     * @return array<int, string>
     */
    protected function keys(string $configKey): array
    {
        return array_map(fn ($key) => strtolower((string) $key), (array) config($configKey, []));
    }
}

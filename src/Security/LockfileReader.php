<?php

namespace NoPanic\Laravel\Security;

use Throwable;

class LockfileReader
{
    /**
     * @return list<array{name: string, version: string}>
     */
    public static function composer(string $path): array
    {
        $lock = static::decode($path);
        $packages = [];

        foreach ((array) ($lock['packages'] ?? []) as $package) {
            if (is_array($package) && is_string($package['name'] ?? null) && is_string($package['version'] ?? null)) {
                $packages[] = ['name' => $package['name'], 'version' => $package['version']];
            }
        }

        return $packages;
    }

    /**
     * @return list<array{name: string, version: string, dev: bool}>|null
     */
    public static function npm(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $lock = static::decode($path);

        if ($lock === null) {
            return null;
        }

        $found = isset($lock['packages']) && is_array($lock['packages'])
            ? static::fromPackages($lock['packages'])
            : static::fromDependencies((array) ($lock['dependencies'] ?? []));

        $unique = [];

        foreach ($found as [$name, $version, $dev]) {
            $key = $name."\0".$version;
            $unique[$key] = ['name' => $name, 'version' => $version, 'dev' => ($unique[$key]['dev'] ?? true) && $dev];
        }

        $packages = array_values($unique);

        usort($packages, fn (array $a, array $b) => [$a['name'], $a['version']] <=> [$b['name'], $b['version']]);

        return $packages;
    }

    /**
     * @param  array<string, mixed>  $packages
     * @return list<array{string, string, bool}>
     */
    protected static function fromPackages(array $packages): array
    {
        $found = [];

        foreach ($packages as $key => $package) {
            $position = strrpos((string) $key, 'node_modules/');

            if ($position === false || ! is_array($package) || ! is_string($package['version'] ?? null)) {
                continue;
            }

            $found[] = [substr((string) $key, $position + strlen('node_modules/')), $package['version'], (bool) ($package['dev'] ?? false)];
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $dependencies
     * @return list<array{string, string, bool}>
     */
    protected static function fromDependencies(array $dependencies): array
    {
        $found = [];

        foreach ($dependencies as $name => $dependency) {
            if (! is_array($dependency)) {
                continue;
            }

            if (is_string($dependency['version'] ?? null)) {
                $found[] = [(string) $name, $dependency['version'], (bool) ($dependency['dev'] ?? false)];
            }

            array_push($found, ...static::fromDependencies((array) ($dependency['dependencies'] ?? [])));
        }

        return $found;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function decode(string $path): ?array
    {
        try {
            $contents = is_file($path) ? file_get_contents($path) : false;
            $decoded = $contents === false ? null : json_decode($contents, true);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}

<?php

namespace NoPanic\Laravel\Context;

class EntryPoint
{
    protected static ?string $type = null;

    protected static ?string $identifier = null;

    public static function markJob(string $jobClass): void
    {
        self::$type = 'queue';
        self::$identifier = $jobClass;
    }

    public static function markCommand(string $commandName): void
    {
        self::$type = 'command';
        self::$identifier = $commandName;
    }

    public static function reset(): void
    {
        self::$type = null;
        self::$identifier = null;
    }

    /**
     * @return ?array{type: string, identifier: ?string, handler: ?string, url: ?string}
     */
    public static function current(): ?array
    {
        if (self::$type === null) {
            return null;
        }

        return [
            'type' => self::$type,
            'identifier' => self::$identifier,
            'handler' => self::$identifier,
            'url' => null,
        ];
    }
}

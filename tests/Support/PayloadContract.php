<?php

namespace NoPanic\Laravel\Tests\Support;

use PHPUnit\Framework\Assert;

class PayloadContract
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function assertValid(array $payload): void
    {
        Assert::assertIsArray($payload['exception'] ?? null);
        Assert::assertIsString($payload['exception']['class'] ?? null);
        Assert::assertNotSame('', $payload['exception']['class']);
        Assert::assertIsArray($payload['frames'] ?? null);
        Assert::assertTrue(array_is_list($payload['frames']));

        foreach ($payload['frames'] as $frame) {
            Assert::assertIsString($frame['file'] ?? null);
            Assert::assertIsInt($frame['line'] ?? null);
        }
    }
}

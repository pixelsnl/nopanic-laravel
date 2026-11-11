<?php

namespace NoPanic\Laravel\Tests\Support;

use NoPanic\Laravel\Context\ContextCollector;

class FakeOrdersController
{
    public static ?array $context = null;

    public function show(string $order): string
    {
        self::$context = app(ContextCollector::class)->collect();

        return $order;
    }
}

<?php

namespace NoPanic\Laravel\Tests;

use NoPanic\Laravel\Context\EntryPoint;
use NoPanic\Laravel\NoPanicServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function tearDown(): void
    {
        EntryPoint::reset();

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [NoPanicServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['env'] = 'production';
        $app['config']->set('nopanic.key', 'np_test');
    }
}

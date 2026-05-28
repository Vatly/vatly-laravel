<?php

declare(strict_types=1);

namespace Vatly\Laravel\Tests;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Orchestra\Testbench\TestCase;
use Vatly\Laravel\VatlyServiceProvider;

abstract class BaseTestCase extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function getPackageProviders($app): array
    {
        return [
            VatlyServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('vatly.api_key', 'test_xxxxxxxxxxxxxxxxxx');
        $app['config']->set('vatly.redirect_url_success', 'https://example.com/success');
        $app['config']->set('vatly.redirect_url_canceled', 'https://example.com/canceled');
    }
}

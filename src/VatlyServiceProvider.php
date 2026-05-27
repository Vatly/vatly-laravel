<?php

declare(strict_types=1);

namespace Vatly\Laravel;

use Illuminate\Support\ServiceProvider;
use Vatly\Fluent\Contracts\ConfigurationInterface;
use Vatly\Fluent\Contracts\CustomerRepositoryInterface;
use Vatly\Fluent\Contracts\EventDispatcherInterface;
use Vatly\Fluent\Contracts\OrderRepositoryInterface;
use Vatly\Fluent\Contracts\SubscriptionRepositoryInterface;
use Vatly\Fluent\Contracts\WebhookCallRepositoryInterface;
use Vatly\Fluent\Vatly;
use Vatly\Fluent\Webhooks\WebhookProcessor;
use Vatly\Fluent\Wiring;
use Vatly\Laravel\Events\LaravelEventDispatcher;
use Vatly\Laravel\Repositories\EloquentCustomerRepository;
use Vatly\Laravel\Repositories\EloquentOrderRepository;
use Vatly\Laravel\Repositories\EloquentSubscriptionRepository;
use Vatly\Laravel\Repositories\EloquentWebhookCallRepository;

class VatlyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vatly.php', 'vatly');

        // Laravel-specific impls of the fluent contracts.
        $this->app->singleton(VatlyConfig::class);
        $this->app->bind(ConfigurationInterface::class,         VatlyConfig::class);
        $this->app->bind(CustomerRepositoryInterface::class,    EloquentCustomerRepository::class);
        $this->app->bind(SubscriptionRepositoryInterface::class, EloquentSubscriptionRepository::class);
        $this->app->bind(OrderRepositoryInterface::class,       EloquentOrderRepository::class);
        $this->app->bind(WebhookCallRepositoryInterface::class, EloquentWebhookCallRepository::class);
        $this->app->bind(EventDispatcherInterface::class,       LaravelEventDispatcher::class);

        // Composition root — fluent does the wiring; we just hand it the impls.
        $this->app->singleton(Vatly::class, fn ($app) => new Vatly(new Wiring(
            config:        $app->make(ConfigurationInterface::class),
            subscriptions: $app->make(SubscriptionRepositoryInterface::class),
            customers:     $app->make(CustomerRepositoryInterface::class),
            orders:        $app->make(OrderRepositoryInterface::class),
            webhookCalls:  $app->make(WebhookCallRepositoryInterface::class),
            events:        $app->make(EventDispatcherInterface::class),
        )));

        // WebhookProcessor is reached by the inbound controller via the
        // container; bind it as a thin proxy to the composition root.
        $this->app->bind(
            WebhookProcessor::class,
            fn ($app) => $app->make(Vatly::class)->webhookProcessor(),
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->bootPublishing();
    }

    private function bootPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/vatly.php' => config_path('vatly.php'),
        ], 'vatly-config');

        $this->publishes([
            __DIR__.'/../database/migrations/create_vatly_billable_columns.php.stub' => $this->getMigrationFileName('create_vatly_billable_columns.php'),
        ], 'vatly-billable-migrations');

        $this->publishes([
            __DIR__.'/../database/migrations/create_vatly_subscriptions_table.php.stub' => $this->getMigrationFileName('create_vatly_subscriptions_table.php'),
            __DIR__.'/../database/migrations/create_vatly_webhook_calls_table.php.stub' => $this->getMigrationFileName('create_vatly_webhook_calls_table.php'),
            __DIR__.'/../database/migrations/create_vatly_orders_table.php.stub' => $this->getMigrationFileName('create_vatly_orders_table.php'),
        ], 'vatly-migrations');
    }

    private function getMigrationFileName(string $migrationFileName): string
    {
        $timestamp = date('Y_m_d_His');

        return $this->app->databasePath("migrations/{$timestamp}_{$migrationFileName}");
    }
}

<?php

declare(strict_types=1);

namespace Vatly\Laravel;

use Illuminate\Support\ServiceProvider;
use Vatly\Fluent\Actions\CancelSubscription;
use Vatly\Fluent\Actions\CreateCheckout;
use Vatly\Fluent\Actions\CreateCustomer;
use Vatly\Fluent\Actions\GetCheckout;
use Vatly\Fluent\Actions\GetCustomer;
use Vatly\Fluent\Actions\GetPaymentMethodUpdateUrl;
use Vatly\Fluent\Actions\GetSubscription;
use Vatly\Fluent\Actions\SwapSubscriptionPlan;
use Vatly\API\VatlyApiClient;
use Vatly\Fluent\Contracts\ConfigurationInterface;
use Vatly\Fluent\Contracts\CustomerRepositoryInterface;
use Vatly\Fluent\Contracts\EventDispatcherInterface;
use Vatly\Fluent\Contracts\OrderRepositoryInterface;
use Vatly\Fluent\Contracts\SubscriptionRepositoryInterface;
use Vatly\Fluent\Contracts\WebhookCallRepositoryInterface;
use Vatly\Fluent\Webhooks\Reactions\CancelSubscriptionOnCanceled;
use Vatly\Fluent\Webhooks\Reactions\StoreOrderOnPaid;
use Vatly\Fluent\Webhooks\Reactions\SyncSubscriptionOnStarted;
use Vatly\Fluent\Webhooks\SignatureVerifier;
use Vatly\Fluent\Webhooks\WebhookEventFactory;
use Vatly\Fluent\Webhooks\WebhookProcessor;
use Vatly\Laravel\Events\LaravelEventDispatcher;
use Vatly\Laravel\Repositories\EloquentCustomerRepository;
use Vatly\Laravel\Repositories\EloquentOrderRepository;
use Vatly\Laravel\Repositories\EloquentSubscriptionRepository;
use Vatly\Laravel\Repositories\EloquentWebhookCallRepository;

class VatlyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/vatly.php', 'vatly'
        );

        $this->registerConfiguration();
        $this->registerApiClient();
        $this->registerActions();
        $this->registerRepositories();
        $this->registerEventDispatcher();
        $this->registerWebhookProcessor();
    }

    public function boot(): void
    {
        $this->bootRoutes();
        $this->bootPublishing();
    }

    private function registerConfiguration(): void
    {
        $this->app->singleton(VatlyConfig::class);
        $this->app->bind(ConfigurationInterface::class, VatlyConfig::class);
    }

    private function registerApiClient(): void
    {
        $this->app->singleton(VatlyApiClient::class, function () {
            $config = $this->app->make(ConfigurationInterface::class);

            $client = new VatlyApiClient();
            $client->setApiKey($config->getApiKey());
            $client->setApiEndpoint($config->getApiUrl());
            $client->setApiVersion($config->getApiVersion());

            return $client;
        });
    }

    private function registerActions(): void
    {
        // All actions are registered as singletons that receive the API client
        $actions = [
            CreateCustomer::class,
            GetCustomer::class,
            CreateCheckout::class,
            GetCheckout::class,
            GetSubscription::class,
            GetPaymentMethodUpdateUrl::class,
            CancelSubscription::class,
            SwapSubscriptionPlan::class,
        ];

        foreach ($actions as $action) {
            $this->app->singleton($action, function () use ($action) {
                return new $action($this->app->make(VatlyApiClient::class));
            });
        }
    }

    private function registerRepositories(): void
    {
        $this->app->bind(CustomerRepositoryInterface::class, EloquentCustomerRepository::class);
        $this->app->bind(SubscriptionRepositoryInterface::class, EloquentSubscriptionRepository::class);
        $this->app->bind(OrderRepositoryInterface::class, EloquentOrderRepository::class);
        $this->app->bind(WebhookCallRepositoryInterface::class, EloquentWebhookCallRepository::class);
    }

    private function registerEventDispatcher(): void
    {
        $this->app->bind(EventDispatcherInterface::class, LaravelEventDispatcher::class);
    }

    private function registerWebhookProcessor(): void
    {
        $this->app->singleton(SignatureVerifier::class);
        $this->app->singleton(WebhookEventFactory::class);

        $this->app->singleton(WebhookProcessor::class, function () {
            $config = $this->app->make(ConfigurationInterface::class);

            return new WebhookProcessor(
                signatureVerifier: $this->app->make(SignatureVerifier::class),
                eventFactory: $this->app->make(WebhookEventFactory::class),
                repository: $this->app->make(WebhookCallRepositoryInterface::class),
                dispatcher: $this->app->make(EventDispatcherInterface::class),
                webhookSecret: $config->getWebhookSecret() ?? '',
                reactions: [
                    new SyncSubscriptionOnStarted(
                        $this->app->make(SubscriptionRepositoryInterface::class),
                        $this->app->make(EventDispatcherInterface::class),
                    ),
                    new CancelSubscriptionOnCanceled(
                        $this->app->make(SubscriptionRepositoryInterface::class),
                    ),
                    new StoreOrderOnPaid(
                        $this->app->make(OrderRepositoryInterface::class),
                    ),
                ],
            );
        });
    }

    private function bootRoutes(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }

    private function bootPublishing(): void
    {
        if (!$this->app->runningInConsole()) {
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

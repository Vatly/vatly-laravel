<?php

declare(strict_types=1);

namespace Vatly\Laravel\Tests\TestHelpers;

use Illuminate\Testing\TestResponse;
use Mockery;
use ReflectionClass;
use Vatly\API\Resources\Order as ApiOrder;
use Vatly\API\Resources\Subscription as ApiSubscription;
use Vatly\API\Types\Mandate;
use Vatly\API\Types\Money;
use Vatly\API\Types\TaxSummaryCollection;
use Vatly\API\VatlyApiClient;
use Vatly\Fluent\Actions\GetOrder;
use Vatly\Fluent\Actions\GetSubscription;
use Vatly\Fluent\Vatly;
use Vatly\Fluent\Webhooks\WebhookProcessor;

/**
 * Test helper for driving the Vatly webhook controller end-to-end.
 *
 * Builds + signs the JSON payload Vatly would POST, swaps the cached
 * `GetOrder` action on the composition root so order.paid handling
 * doesn't need a real API, and exposes a small toolkit
 * (`postWebhook`, `makeWebhookPayload`, `fakeGetOrder`, `buildApiOrder`)
 * shared by the controller test and the higher-level flow tests.
 */
trait PostsVatlyWebhooks
{
    protected string $webhookSecret = 'test-webhook-secret';

    protected function configureWebhookSecret(?string $secret = null): void
    {
        if ($secret !== null) {
            $this->webhookSecret = $secret;
        }

        $this->app['config']->set('vatly.webhook_secret', $this->webhookSecret);
        $this->app->forgetInstance(WebhookProcessor::class);
    }

    /**
     * @param  array<string, mixed>  $object
     */
    protected function makeWebhookPayload(string $eventName, string $entityId, string $entityType, array $object = []): string
    {
        return (string) json_encode([
            'id' => 'webhook_event_'.bin2hex(random_bytes(10)),
            'resource' => 'webhook_event',
            'eventName' => $eventName,
            'entityType' => $entityType,
            'entityId' => $entityId,
            'testmode' => true,
            'createdAt' => now()->toIso8601String(),
            'object' => (object) $object,
        ]);
    }

    protected function postSignedWebhook(string $payload): TestResponse
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $this->webhookSecret);

        return $this->call(
            'POST',
            'webhooks/vatly',
            server: ['HTTP_VATLY_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
            content: $payload,
        );
    }

    /**
     * Convenience: build + sign + POST in one go.
     *
     * @param  array<string, mixed>  $object
     */
    protected function postWebhookEvent(string $eventName, string $entityId, string $entityType, array $object = []): TestResponse
    {
        return $this->postSignedWebhook(
            $this->makeWebhookPayload($eventName, $entityId, $entityType, $object),
        );
    }

    /**
     * Replace the cached `GetOrder` action on the composition root so
     * the `order.paid` webhook flow doesn't need a real API call. Clears
     * the downstream WebhookEventFactory / WebhookProcessor caches so
     * they re-resolve through the mocked action.
     */
    protected function fakeGetOrder(ApiOrder $order): void
    {
        $action = Mockery::mock(GetOrder::class);
        $action->shouldReceive('execute')->andReturn($order);

        $vatly = $this->app->make(Vatly::class);

        $this->writeVatlyPrivate($vatly, 'getOrder', $action);
        $this->writeVatlyPrivate($vatly, 'webhookEventFactory', null);
        $this->writeVatlyPrivate($vatly, 'webhookProcessor', null);

        $this->app->forgetInstance(WebhookProcessor::class);
    }

    /**
     * Replace the cached `GetSubscription` action on the composition root
     * so the `subscription.started` webhook flow doesn't need a real API
     * call. WebhookEventFactory enriches the event via this action to pull
     * the mandate summary into the dispatched event.
     *
     * Returns the same subscription regardless of which Vatly id is asked
     * for. For tests that need different subscriptions per id, use
     * {@see self::fakeGetSubscriptions()}.
     */
    protected function fakeGetSubscription(ApiSubscription $subscription): void
    {
        $this->fakeGetSubscriptions([
            $subscription->id => $subscription,
        ], strict: false);
    }

    /**
     * Replace the cached `GetSubscription` action with a fake that returns
     * a different `ApiSubscription` per Vatly subscription id.
     *
     * @param  array<string, ApiSubscription>  $subscriptions  keyed by Vatly subscription id
     * @param  bool  $strict  when true, asking for an id not in the map throws.
     */
    protected function fakeGetSubscriptions(array $subscriptions, bool $strict = true): void
    {
        $action = Mockery::mock(GetSubscription::class);
        $action->shouldReceive('execute')->andReturnUsing(function (string $id) use ($subscriptions, $strict) {
            if (isset($subscriptions[$id])) {
                return $subscriptions[$id];
            }
            if ($strict) {
                throw new \RuntimeException("No fake ApiSubscription registered for id '{$id}'.");
            }

            return reset($subscriptions);
        });

        $vatly = $this->app->make(Vatly::class);

        $this->writeVatlyPrivate($vatly, 'getSubscription', $action);
        $this->writeVatlyPrivate($vatly, 'webhookEventFactory', null);
        $this->writeVatlyPrivate($vatly, 'webhookProcessor', null);

        $this->app->forgetInstance(WebhookProcessor::class);
    }

    /**
     * Build a minimal ApiSubscription for `fakeGetSubscription()` callers.
     *
     * @param  array{
     *   id: string,
     *   customerId: ?string,
     *   subscriptionPlanId: string,
     *   name: string,
     *   quantity: int,
     *   mandate?: ?Mandate,
     * }  $data
     */
    protected function buildApiSubscription(array $data): ApiSubscription
    {
        $subscription = new ApiSubscription(Mockery::mock(VatlyApiClient::class));
        $subscription->id = $data['id'];
        $subscription->customerId = $data['customerId'] ?? null;
        $subscription->subscriptionPlanId = $data['subscriptionPlanId'];
        $subscription->name = $data['name'];
        $subscription->quantity = $data['quantity'];
        $subscription->mandate = $data['mandate'] ?? null;

        return $subscription;
    }

    private function writeVatlyPrivate(object $target, string $property, mixed $value): void
    {
        $ref = (new ReflectionClass($target))->getProperty($property);
        $ref->setAccessible(true);
        $ref->setValue($target, $value);
    }

    /**
     * @param  array{
     *   id: string,
     *   customerId: string,
     *   totalValue: string,
     *   subtotalValue: string,
     *   currency: string,
     *   invoiceNumber: ?string,
     *   paymentMethod: ?string,
     *   taxRates: array<int, array{name: string, percentage: float, taxablePercentage: float, amount: string}>,
     * }  $data
     */
    protected function buildApiOrder(array $data): ApiOrder
    {
        $order = new ApiOrder(Mockery::mock(VatlyApiClient::class));
        $order->id = $data['id'];
        $order->customerId = $data['customerId'];
        $order->total = new Money($data['currency'], $data['totalValue']);
        $order->subtotal = new Money($data['currency'], $data['subtotalValue']);
        $order->invoiceNumber = $data['invoiceNumber'];
        $order->paymentMethod = $data['paymentMethod'];
        $order->status = 'paid';
        $order->taxSummary = new TaxSummaryCollection(array_map(
            fn (array $rate) => [
                'taxRate' => [
                    'name' => $rate['name'],
                    'percentage' => $rate['percentage'],
                    'taxablePercentage' => $rate['taxablePercentage'],
                ],
                'amount' => ['currency' => $data['currency'], 'value' => $rate['amount']],
            ],
            $data['taxRates'],
        ));

        return $order;
    }
}

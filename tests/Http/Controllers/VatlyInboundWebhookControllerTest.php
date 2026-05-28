<?php

declare(strict_types=1);

namespace Vatly\Laravel\Tests\Http\Controllers;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use ReflectionClass;
use Vatly\API\Resources\Order as ApiOrder;
use Vatly\API\Types\Money;
use Vatly\API\Types\TaxSummaryCollection;
use Vatly\API\VatlyApiClient;
use Vatly\Fluent\Actions\GetOrder;
use Vatly\Fluent\Vatly;
use Vatly\Fluent\Webhooks\WebhookProcessor;
use Vatly\Laravel\Models\Order;
use Vatly\Laravel\Models\Subscription;
use Vatly\Laravel\Tests\BaseTestCase;

class VatlyInboundWebhookControllerTest extends BaseTestCase
{
    use RefreshDatabase;

    private string $secret = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('vatly.webhook_secret', $this->secret);
        $this->app->forgetInstance(WebhookProcessor::class);
    }

    public function test_it_returns_201_for_a_valid_signed_webhook(): void
    {
        User::factory()->create(['vatly_id' => 'customer_foo']);

        $payload = $this->makePayload('subscription.started', 'sub_123', 'subscription', [
            'customerId' => 'customer_foo',
            'subscriptionPlanId' => 'plan_foo',
            'quantity' => 1,
            'name' => 'Test Plan',
        ]);

        $response = $this->postWebhook($payload);

        $response->assertStatus(201);
        $this->assertDatabaseCount('vatly_webhook_calls', 1);
    }

    public function test_it_handles_unknown_webhook_events(): void
    {
        $payload = $this->makePayload('unknown.event.type', 'res_123', 'unknown', ['foo' => 'bar']);

        $response = $this->postWebhook($payload);

        $response->assertStatus(201);
        $this->assertDatabaseCount('vatly_webhook_calls', 1);
    }

    public function test_it_returns_403_for_an_invalid_signature(): void
    {
        $payload = $this->makePayload('subscription.started', 'sub_123', 'subscription');

        $response = $this->call(
            'POST',
            'webhooks/vatly',
            server: ['HTTP_VATLY_SIGNATURE' => 't='.time().',v1=deadbeef', 'CONTENT_TYPE' => 'application/json'],
            content: $payload,
        );

        $response->assertStatus(403);
        $this->assertDatabaseCount('vatly_webhook_calls', 0);
    }

    public function test_it_returns_403_for_a_missing_signature(): void
    {
        $payload = $this->makePayload('subscription.started', 'sub_123', 'subscription');

        $response = $this->call(
            'POST',
            'webhooks/vatly',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $payload,
        );

        $response->assertStatus(403);
    }

    public function test_it_returns_403_for_a_stale_timestamp(): void
    {
        $payload = $this->makePayload('subscription.started', 'sub_123', 'subscription');
        $staleTimestamp = time() - 3600;
        $signature = hash_hmac('sha256', $staleTimestamp.'.'.$payload, $this->secret);

        $response = $this->call(
            'POST',
            'webhooks/vatly',
            server: ['HTTP_VATLY_SIGNATURE' => "t={$staleTimestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
            content: $payload,
        );

        $response->assertStatus(403);
        $this->assertDatabaseCount('vatly_webhook_calls', 0);
    }

    public function test_it_creates_a_subscription_from_webhook(): void
    {
        $user = User::factory()->create(['vatly_id' => 'customer_abc']);

        $payload = $this->makePayload('subscription.started', 'sub_999', 'subscription', [
            'customerId' => 'customer_abc',
            'subscriptionPlanId' => 'plan_premium',
            'quantity' => 1,
            'name' => 'Premium Plan',
        ]);

        $response = $this->postWebhook($payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('vatly_subscriptions', [
            'vatly_id' => 'sub_999',
            'plan_id' => 'plan_premium',
            'name' => 'Premium Plan',
            'owner_id' => $user->id,
        ]);
    }

    public function test_it_creates_an_order_from_webhook(): void
    {
        $user = User::factory()->create(['vatly_id' => 'customer_abc']);

        $this->fakeGetOrder($this->buildApiOrder([
            'id' => 'order_abc123',
            'customerId' => 'customer_abc',
            'totalValue' => '99.00',
            'subtotalValue' => '81.82',
            'currency' => 'EUR',
            'invoiceNumber' => 'INV-001',
            'paymentMethod' => 'card',
            'taxRates' => [
                ['name' => 'VAT', 'percentage' => 21.0, 'taxablePercentage' => 100.0, 'amount' => '17.18'],
            ],
        ]));

        $payload = $this->makePayload('order.paid', 'order_abc123', 'order', [
            'customerId' => 'customer_abc',
            'total' => ['currency' => 'EUR', 'value' => '99.00'],
            'invoiceNumber' => 'INV-001',
            'paymentMethod' => 'card',
        ]);

        $response = $this->postWebhook($payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('vatly_orders', [
            'vatly_id' => 'order_abc123',
            'status' => 'paid',
            'total' => 9900,
            'currency' => 'EUR',
            'owner_id' => $user->id,
        ]);
    }

    public function test_it_persists_tax_breakdown_when_creating_an_order_from_webhook(): void
    {
        $user = User::factory()->create(['vatly_id' => 'customer_abc']);

        $this->fakeGetOrder($this->buildApiOrder([
            'id' => 'order_tax_1',
            'customerId' => 'customer_abc',
            'totalValue' => '49.99',
            'subtotalValue' => '41.31',
            'currency' => 'USD',
            'invoiceNumber' => null,
            'paymentMethod' => null,
            'taxRates' => [
                ['name' => 'Sales Tax', 'percentage' => 21.0, 'taxablePercentage' => 100.0, 'amount' => '8.68'],
            ],
        ]));

        $payload = $this->makePayload('order.paid', 'order_tax_1', 'order', [
            'customerId' => 'customer_abc',
            'total' => ['currency' => 'USD', 'value' => '49.99'],
        ]);

        $response = $this->postWebhook($payload);

        $response->assertStatus(201);

        $order = Order::where('vatly_id', 'order_tax_1')->firstOrFail();
        $this->assertSame(4131, $order->subtotal);
        $this->assertSame('Sales Tax', $order->tax_summary[0]['rate']['name']);
        $this->assertSame(868, $order->tax_summary[0]['amount']);
        $this->assertSame('USD', $order->tax_summary[0]['currency']);
        $this->assertSame($user->id, $order->owner_id);
    }

    private function fakeGetOrder(ApiOrder $order): void
    {
        $action = Mockery::mock(GetOrder::class);
        $action->shouldReceive('execute')->andReturn($order);

        // The Vatly composition root caches actions and the dependent
        // WebhookEventFactory / WebhookProcessor internally. To swap a
        // single action for a test, overwrite the cached slot on the
        // singleton Vatly and clear the downstream caches so they
        // re-resolve through the mocked action.
        $vatly = $this->app->make(Vatly::class);

        $this->writePrivate($vatly, 'getOrder', $action);
        $this->writePrivate($vatly, 'webhookEventFactory', null);
        $this->writePrivate($vatly, 'webhookProcessor', null);

        $this->app->forgetInstance(WebhookProcessor::class);
    }

    private function writePrivate(object $target, string $property, mixed $value): void
    {
        $ref = (new ReflectionClass($target))->getProperty($property);
        $ref->setAccessible(true);
        $ref->setValue($target, $value);
    }

    /**
     * @param array{
     *   id: string,
     *   customerId: string,
     *   totalValue: string,
     *   subtotalValue: string,
     *   currency: string,
     *   invoiceNumber: ?string,
     *   paymentMethod: ?string,
     *   taxRates: array<int, array{name: string, percentage: float, taxablePercentage: float, amount: string}>,
     * } $data
     */
    private function buildApiOrder(array $data): ApiOrder
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

    public function test_it_cancels_a_subscription_immediately_from_webhook(): void
    {
        $user = User::factory()->create(['vatly_id' => 'customer_abc']);

        // First create the subscription
        Subscription::create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'vatly_id' => 'sub_cancel',
            'plan_id' => 'plan_foo',
            'name' => 'Test Plan',
            'type' => 'default',
            'quantity' => 1,
        ]);

        $payload = $this->makePayload('subscription.canceled_immediately', 'sub_cancel', 'subscription', [
            'customerId' => 'customer_abc',
        ]);

        $response = $this->postWebhook($payload);

        $response->assertStatus(201);
        $subscription = Subscription::where('vatly_id', 'sub_cancel')->first();
        $this->assertTrue($subscription->isCancelled());
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function makePayload(string $eventName, string $entityId, string $entityType, array $object = []): string
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

    private function postWebhook(string $payload): TestResponse
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $this->secret);

        return $this->call(
            'POST',
            'webhooks/vatly',
            server: ['HTTP_VATLY_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
            content: $payload,
        );
    }
}

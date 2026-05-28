<?php

declare(strict_types=1);

namespace Vatly\Laravel\Tests\Http\Controllers;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vatly\Laravel\Models\Order;
use Vatly\Laravel\Models\Subscription;
use Vatly\Laravel\Tests\BaseTestCase;
use Vatly\Laravel\Tests\TestHelpers\PostsVatlyWebhooks;

class VatlyInboundWebhookControllerTest extends BaseTestCase
{
    use PostsVatlyWebhooks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureWebhookSecret();
    }

    public function test_it_returns_201_for_a_valid_signed_webhook(): void
    {
        User::factory()->create(['vatly_id' => 'customer_foo']);

        $response = $this->postWebhookEvent('subscription.started', 'sub_123', 'subscription', [
            'customerId' => 'customer_foo',
            'subscriptionPlanId' => 'plan_foo',
            'quantity' => 1,
            'name' => 'Test Plan',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('vatly_webhook_calls', 1);
    }

    public function test_it_handles_unknown_webhook_events(): void
    {
        $response = $this->postWebhookEvent('unknown.event.type', 'res_123', 'unknown', ['foo' => 'bar']);

        $response->assertStatus(201);
        $this->assertDatabaseCount('vatly_webhook_calls', 1);
    }

    public function test_it_returns_403_for_an_invalid_signature(): void
    {
        $payload = $this->makeWebhookPayload('subscription.started', 'sub_123', 'subscription');

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
        $payload = $this->makeWebhookPayload('subscription.started', 'sub_123', 'subscription');

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
        $payload = $this->makeWebhookPayload('subscription.started', 'sub_123', 'subscription');
        $staleTimestamp = time() - 3600;
        $signature = hash_hmac('sha256', $staleTimestamp.'.'.$payload, $this->webhookSecret);

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

        $response = $this->postWebhookEvent('subscription.started', 'sub_999', 'subscription', [
            'customerId' => 'customer_abc',
            'subscriptionPlanId' => 'plan_premium',
            'quantity' => 1,
            'name' => 'Premium Plan',
        ]);

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

        $response = $this->postWebhookEvent('order.paid', 'order_abc123', 'order', [
            'customerId' => 'customer_abc',
            'total' => ['currency' => 'EUR', 'value' => '99.00'],
            'invoiceNumber' => 'INV-001',
            'paymentMethod' => 'card',
        ]);

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

        $response = $this->postWebhookEvent('order.paid', 'order_tax_1', 'order', [
            'customerId' => 'customer_abc',
            'total' => ['currency' => 'USD', 'value' => '49.99'],
        ]);

        $response->assertStatus(201);

        $order = Order::where('vatly_id', 'order_tax_1')->firstOrFail();
        $this->assertSame(4131, $order->subtotal);
        $this->assertSame('Sales Tax', $order->tax_summary[0]['rate']['name']);
        $this->assertSame(868, $order->tax_summary[0]['amount']);
        $this->assertSame('USD', $order->tax_summary[0]['currency']);
        $this->assertSame($user->id, $order->owner_id);
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

        $response = $this->postWebhookEvent('subscription.canceled_immediately', 'sub_cancel', 'subscription', [
            'customerId' => 'customer_abc',
        ]);

        $response->assertStatus(201);
        $subscription = Subscription::where('vatly_id', 'sub_cancel')->first();
        $this->assertTrue($subscription->isCancelled());
    }
}

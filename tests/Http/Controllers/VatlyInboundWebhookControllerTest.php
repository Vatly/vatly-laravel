<?php

declare(strict_types=1);

namespace Vatly\Laravel\Tests\Http\Controllers;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vatly\Fluent\Webhooks\WebhookProcessor;
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

    /** @test */
    public function it_returns_201_for_a_valid_signed_webhook(): void
    {
        User::factory()->create(['vatly_id' => 'customer_foo']);

        $payload = $this->makePayload('subscription.started', 'sub_123', 'subscription', [
            'data' => [
                'customerId' => 'customer_foo',
                'subscriptionPlanId' => 'plan_foo',
                'quantity' => 1,
                'name' => 'Test Plan',
            ],
        ]);

        $response = $this->postWebhook($payload);

        $response->assertStatus(201);
        $this->assertDatabaseCount('vatly_webhook_calls', 1);
    }

    /** @test */
    public function it_handles_unknown_webhook_events(): void
    {
        $payload = $this->makePayload('unknown.event.type', 'res_123', 'unknown', ['foo' => 'bar']);

        $response = $this->postWebhook($payload);

        $response->assertStatus(201);
        $this->assertDatabaseCount('vatly_webhook_calls', 1);
    }

    /** @test */
    public function it_returns_403_for_an_invalid_signature(): void
    {
        $payload = $this->makePayload('subscription.started', 'sub_123', 'subscription');

        $response = $this->call(
            'POST',
            'webhooks/vatly',
            server: ['HTTP_X_VATLY_SIGNATURE' => 'invalid-signature', 'CONTENT_TYPE' => 'application/json'],
            content: $payload,
        );

        $response->assertStatus(403);
        $this->assertDatabaseCount('vatly_webhook_calls', 0);
    }

    /** @test */
    public function it_returns_403_for_a_missing_signature(): void
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

    /** @test */
    public function it_creates_a_subscription_from_webhook(): void
    {
        $user = User::factory()->create(['vatly_id' => 'customer_abc']);

        $payload = $this->makePayload('subscription.started', 'sub_999', 'subscription', [
            'data' => [
                'customerId' => 'customer_abc',
                'subscriptionPlanId' => 'plan_premium',
                'quantity' => 1,
                'name' => 'Premium Plan',
            ],
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

    /** @test */
    public function it_creates_an_order_from_webhook(): void
    {
        $user = User::factory()->create(['vatly_id' => 'customer_abc']);

        $payload = $this->makePayload('order.paid', 'ord_123', 'order', [
            'data' => [
                'customerId' => 'customer_abc',
                'total' => 9900,
                'currency' => 'EUR',
                'invoiceNumber' => 'INV-001',
                'paymentMethod' => 'card',
            ],
        ]);

        $response = $this->postWebhook($payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('vatly_orders', [
            'vatly_id' => 'ord_123',
            'status' => 'paid',
            'total' => 9900,
            'currency' => 'EUR',
            'owner_id' => $user->id,
        ]);
    }

    /** @test */
    public function it_cancels_a_subscription_immediately_from_webhook(): void
    {
        $user = User::factory()->create(['vatly_id' => 'customer_abc']);

        // First create the subscription
        \Vatly\Laravel\Models\Subscription::create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'vatly_id' => 'sub_cancel',
            'plan_id' => 'plan_foo',
            'name' => 'Test Plan',
            'type' => 'default',
            'quantity' => 1,
        ]);

        $payload = $this->makePayload('subscription.canceled_immediately', 'sub_cancel', 'subscription', [
            'data' => [
                'customerId' => 'customer_abc',
            ],
        ]);

        $response = $this->postWebhook($payload);

        $response->assertStatus(201);
        $subscription = \Vatly\Laravel\Models\Subscription::where('vatly_id', 'sub_cancel')->first();
        $this->assertTrue($subscription->isCancelled());
    }

    private function makePayload(string $eventName, string $resourceId, string $resourceName, array $object = []): string
    {
        return json_encode([
            'eventName' => $eventName,
            'resourceId' => $resourceId,
            'resourceName' => $resourceName,
            'object' => $object,
            'raisedAt' => now()->toIso8601String(),
            'testmode' => true,
        ]);
    }

    private function postWebhook(string $payload): \Illuminate\Testing\TestResponse
    {
        $signature = hash_hmac('sha256', $payload, $this->secret);

        return $this->call(
            'POST',
            'webhooks/vatly',
            server: ['HTTP_X_VATLY_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            content: $payload,
        );
    }
}

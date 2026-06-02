<?php

declare(strict_types=1);

namespace Vatly\Laravel\Tests\Http\Controllers;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Vatly\API\Types\Mandate;
use Vatly\Fluent\Events\CheckoutCanceled;
use Vatly\Fluent\Events\CheckoutExpired;
use Vatly\Fluent\Events\CheckoutFailed;
use Vatly\Fluent\Events\CheckoutPaid;
use Vatly\Fluent\Events\PaymentFailed;
use Vatly\Fluent\Events\SubscriptionCancellationGracePeriodCompleted;
use Vatly\Laravel\Models\Order;
use Vatly\Laravel\Models\Refund;
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

        $this->fakeGetSubscription($this->buildApiSubscription([
            'id' => 'sub_123',
            'customerId' => 'customer_foo',
            'subscriptionPlanId' => 'plan_foo',
            'name' => 'Test Plan',
            'quantity' => 1,
        ]));

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

        $this->fakeGetSubscription($this->buildApiSubscription([
            'id' => 'sub_999',
            'customerId' => 'customer_abc',
            'subscriptionPlanId' => 'plan_premium',
            'name' => 'Premium Plan',
            'quantity' => 1,
            'mandate' => new Mandate('card', '4242'),
        ]));

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
            'mandate_method' => 'card',
            'mandate_masked_identifier' => '4242',
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

    public function test_it_stores_an_order_when_a_payment_fails_from_webhook(): void
    {
        $user = User::factory()->create(['vatly_id' => 'customer_abc']);

        $apiOrder = $this->buildApiOrder([
            'id' => 'order_failed_1',
            'customerId' => 'customer_abc',
            'totalValue' => '99.00',
            'subtotalValue' => '81.82',
            'currency' => 'EUR',
            'invoiceNumber' => 'INV-009',
            'paymentMethod' => 'card',
            'taxRates' => [
                ['name' => 'VAT', 'percentage' => 21.0, 'taxablePercentage' => 100.0, 'amount' => '17.18'],
            ],
        ]);
        // The reaction mirrors the upstream status verbatim — not a synthetic "failed".
        $apiOrder->status = 'pending';
        $this->fakeGetOrder($apiOrder);

        $response = $this->postWebhookEvent('payment.failed', 'order_failed_1', 'order', [
            'customerId' => 'customer_abc',
            'total' => ['currency' => 'EUR', 'value' => '99.00'],
            'invoiceNumber' => 'INV-009',
            'paymentMethod' => 'card',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('vatly_orders', [
            'vatly_id' => 'order_failed_1',
            'status' => 'pending',
            'total' => 9900,
            'currency' => 'EUR',
            'owner_id' => $user->id,
        ]);
    }

    public function test_it_dispatches_the_payment_failed_event_from_webhook(): void
    {
        Event::fake([PaymentFailed::class]);

        User::factory()->create(['vatly_id' => 'customer_abc']);

        $apiOrder = $this->buildApiOrder([
            'id' => 'order_failed_2',
            'customerId' => 'customer_abc',
            'totalValue' => '99.00',
            'subtotalValue' => '81.82',
            'currency' => 'EUR',
            'invoiceNumber' => null,
            'paymentMethod' => null,
            'taxRates' => [
                ['name' => 'VAT', 'percentage' => 21.0, 'taxablePercentage' => 100.0, 'amount' => '17.18'],
            ],
        ]);
        $apiOrder->status = 'pending';
        $this->fakeGetOrder($apiOrder);

        $this->postWebhookEvent('payment.failed', 'order_failed_2', 'order', [
            'customerId' => 'customer_abc',
            'total' => ['currency' => 'EUR', 'value' => '99.00'],
        ])->assertStatus(201);

        Event::assertDispatched(
            PaymentFailed::class,
            fn (PaymentFailed $event): bool => $event->orderId === 'order_failed_2'
                && $event->customerId === 'customer_abc',
        );
    }

    public function test_it_dispatches_the_checkout_paid_event_from_webhook(): void
    {
        Event::fake([CheckoutPaid::class]);

        $this->postWebhookEvent('checkout.paid', 'checkout_abc123', 'checkout', [
            'customerId' => 'customer_abc',
            'orderId' => 'order_abc123',
            'status' => 'paid',
            'metadata' => ['cart' => 'cart_1'],
        ])->assertStatus(201);

        $this->assertDatabaseCount('vatly_webhook_calls', 1);

        Event::assertDispatched(
            CheckoutPaid::class,
            fn (CheckoutPaid $event): bool => $event->checkoutId === 'checkout_abc123'
                && $event->customerId === 'customer_abc'
                && $event->orderId === 'order_abc123'
                && $event->status === 'paid'
                && $event->metadata === ['cart' => 'cart_1'],
        );
    }

    public function test_it_dispatches_the_checkout_failed_event_from_webhook(): void
    {
        Event::fake([CheckoutFailed::class]);

        $this->postWebhookEvent('checkout.failed', 'checkout_failed_1', 'checkout', [
            'customerId' => 'customer_abc',
            'orderId' => null,
            'status' => 'failed',
            'metadata' => ['cart' => 'cart_2'],
        ])->assertStatus(201);

        $this->assertDatabaseCount('vatly_webhook_calls', 1);

        Event::assertDispatched(
            CheckoutFailed::class,
            fn (CheckoutFailed $event): bool => $event->checkoutId === 'checkout_failed_1'
                && $event->customerId === 'customer_abc'
                && $event->orderId === null
                && $event->status === 'failed'
                && $event->metadata === ['cart' => 'cart_2'],
        );
    }

    public function test_it_dispatches_the_checkout_canceled_event_from_webhook(): void
    {
        Event::fake([CheckoutCanceled::class]);

        $this->postWebhookEvent('checkout.canceled', 'checkout_canceled_1', 'checkout', [
            'customerId' => 'customer_abc',
            'orderId' => null,
            'status' => 'canceled',
        ])->assertStatus(201);

        $this->assertDatabaseCount('vatly_webhook_calls', 1);

        Event::assertDispatched(
            CheckoutCanceled::class,
            fn (CheckoutCanceled $event): bool => $event->checkoutId === 'checkout_canceled_1'
                && $event->customerId === 'customer_abc'
                && $event->orderId === null
                && $event->status === 'canceled',
        );
    }

    public function test_it_dispatches_the_checkout_expired_event_from_webhook(): void
    {
        Event::fake([CheckoutExpired::class]);

        $this->postWebhookEvent('checkout.expired', 'checkout_expired_1', 'checkout', [
            'customerId' => null,
            'orderId' => null,
            'status' => 'expired',
        ])->assertStatus(201);

        $this->assertDatabaseCount('vatly_webhook_calls', 1);

        Event::assertDispatched(
            CheckoutExpired::class,
            fn (CheckoutExpired $event): bool => $event->checkoutId === 'checkout_expired_1'
                && $event->customerId === null
                && $event->orderId === null
                && $event->status === 'expired',
        );
    }

    public function test_it_dispatches_the_grace_period_completed_event_from_webhook(): void
    {
        Event::fake([SubscriptionCancellationGracePeriodCompleted::class]);

        $this->postWebhookEvent('subscription.cancellation_grace_period_completed', 'sub_grace', 'subscription', [
            'customerId' => 'customer_abc',
            'endedAt' => '2026-01-01T00:00:00+00:00',
        ])->assertStatus(201);

        $this->assertDatabaseCount('vatly_webhook_calls', 1);

        Event::assertDispatched(
            SubscriptionCancellationGracePeriodCompleted::class,
            fn (SubscriptionCancellationGracePeriodCompleted $event): bool => $event->subscriptionId === 'sub_grace'
                && $event->customerId === 'customer_abc'
                && $event->endsAt->format('Y-m-d') === '2026-01-01',
        );
    }

    public function test_it_stamps_ends_at_when_a_grace_period_completes_from_webhook(): void
    {
        $user = User::factory()->create(['vatly_id' => 'customer_abc']);

        // Simulate a subscription whose `canceled_with_grace_period` webhook was
        // missed: the local row never got an `ends_at`, so it still looks active.
        Subscription::create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'vatly_id' => 'sub_grace',
            'plan_id' => 'plan_foo',
            'name' => 'Test Plan',
            'type' => 'default',
            'quantity' => 1,
            'ends_at' => null,
        ]);

        $response = $this->postWebhookEvent('subscription.cancellation_grace_period_completed', 'sub_grace', 'subscription', [
            'customerId' => 'customer_abc',
            'endedAt' => '2026-01-01T00:00:00+00:00',
        ]);

        $response->assertStatus(201);

        // The EndSubscriptionOnGracePeriodCompleted reaction self-heals the row:
        // ends_at is stamped to the actual end and the derived state flips to ended.
        $subscription = Subscription::where('vatly_id', 'sub_grace')->firstOrFail();
        $this->assertNotNull($subscription->ends_at);
        $this->assertSame('2026-01-01', $subscription->ends_at->format('Y-m-d'));
        $this->assertTrue($subscription->isEnded());
        $this->assertFalse($subscription->isActive());
    }

    public function test_it_corrects_a_drifted_end_date_when_a_grace_period_completes_from_webhook(): void
    {
        $user = User::factory()->create(['vatly_id' => 'customer_abc']);

        // The cancellation stamped a *scheduled* end date; the grace period then
        // ended on a different *actual* date upstream (e.g. it was shortened).
        Subscription::create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'vatly_id' => 'sub_drift',
            'plan_id' => 'plan_foo',
            'name' => 'Test Plan',
            'type' => 'default',
            'quantity' => 1,
            'ends_at' => '2026-02-01T00:00:00+00:00',
        ]);

        $response = $this->postWebhookEvent('subscription.cancellation_grace_period_completed', 'sub_drift', 'subscription', [
            'customerId' => 'customer_abc',
            'endedAt' => '2026-01-15T00:00:00+00:00',
        ]);

        $response->assertStatus(201);

        // The reaction overwrites the scheduled end with the authoritative actual end.
        $subscription = Subscription::where('vatly_id', 'sub_drift')->firstOrFail();
        $this->assertSame('2026-01-15', $subscription->ends_at->format('Y-m-d'));
        $this->assertTrue($subscription->isEnded());
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

    public function test_it_persists_a_refund_from_webhook(): void
    {
        $user = User::factory()->create(['vatly_id' => 'customer_abc']);

        $this->fakeGetRefund($this->buildApiRefund([
            'id' => 'refund_abc123',
            'customerId' => 'customer_abc',
            'originalOrderId' => 'order_abc123',
            'status' => 'refunded',
            'totalValue' => '99.00',
            'subtotalValue' => '81.82',
            'currency' => 'EUR',
            'taxRates' => [
                ['name' => 'VAT', 'percentage' => 21.0, 'taxablePercentage' => 100.0, 'amount' => '17.18'],
            ],
        ]));

        $response = $this->postWebhookEvent('refund.completed', 'refund_abc123', 'refund', [
            'customerId' => 'customer_abc',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('vatly_refunds', [
            'vatly_id' => 'refund_abc123',
            'original_order_id' => 'order_abc123',
            'status' => 'refunded',
            'total' => 9900,
            'currency' => 'EUR',
            'owner_id' => $user->id,
        ]);

        $refund = Refund::where('vatly_id', 'refund_abc123')->firstOrFail();
        $this->assertSame(8182, $refund->subtotal);
        $this->assertSame(1718, $refund->tax_summary[0]['amount']);
        $this->assertTrue($refund->isCompleted());
    }
}

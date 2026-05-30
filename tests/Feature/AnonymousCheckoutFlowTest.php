<?php

declare(strict_types=1);

namespace Vatly\Laravel\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vatly\Fluent\SubscriptionHandle;
use Vatly\Laravel\Models\Order;
use Vatly\Laravel\Models\Subscription;
use Vatly\Laravel\Tests\BaseTestCase;
use Vatly\Laravel\Tests\TestHelpers\PostsVatlyWebhooks;

/**
 * End-to-end coverage of the "Vatly for non-auth users" flow:
 *
 * 1. A visitor (no host User row exists) completes a Vatly checkout.
 * 2. Vatly fires `subscription.started` and `order.paid` webhooks.
 * 3. The webhooks land — `customer_id` is captured on each row, but
 *    `owner_id` / `owner_type` stay null (no host to attribute to).
 * 4. The visitor later signs up.
 * 5. The signup hook calls `$user->claimVatlyCustomer($vatlyCustomerId)`
 *    (the `cus_…` typically pulled from a success-URL param / session).
 * 6. The previously-orphan rows get re-attributed to the new user.
 * 7. The full Billable trait surface (`subscribed()`, `subscription()`,
 *    `$user->subscriptions`, `$user->orders`, `$user->order($vatlyId)`)
 *    works as if the user had owned those purchases all along.
 */
class AnonymousCheckoutFlowTest extends BaseTestCase
{
    use PostsVatlyWebhooks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureWebhookSecret();
    }

    public function test_full_anonymous_lifecycle_from_webhook_to_signup_to_claim(): void
    {
        $anonymousCustomerId = 'cus_anon_alice';

        // Stage the order.paid mock up-front. Fluent's GetOrder action is
        // captured the moment the WebhookProcessor is first resolved, so the
        // mock must be in place before any webhook lands.
        $this->fakeGetOrder($this->buildApiOrder([
            'id' => 'order_anon_1',
            'customerId' => $anonymousCustomerId,
            'totalValue' => '99.00',
            'subtotalValue' => '81.82',
            'currency' => 'EUR',
            'invoiceNumber' => 'INV-ANON-001',
            'paymentMethod' => 'card',
            'taxRates' => [
                ['name' => 'VAT', 'percentage' => 21.0, 'taxablePercentage' => 100.0, 'amount' => '17.18'],
            ],
        ]));

        // ---------------- 1. Anonymous visitor's subscription starts. ----------------
        // No User row exists yet; the webhook arrives for a Vatly customer
        // id our app has never seen.

        $this->assertDatabaseMissing('users', ['vatly_id' => $anonymousCustomerId]);

        $this->fakeGetSubscription($this->buildApiSubscription([
            'id' => 'sub_anon_1',
            'customerId' => $anonymousCustomerId,
            'subscriptionPlanId' => 'plan_basic',
            'name' => 'Basic',
            'quantity' => 1,
        ]));

        $this->postWebhookEvent('subscription.started', 'sub_anon_1', 'subscription', [
            'customerId' => $anonymousCustomerId,
            'subscriptionPlanId' => 'plan_basic',
            'quantity' => 1,
            'name' => 'Basic',
        ])->assertStatus(201);

        // The subscription was persisted with the Vatly customer id but no host owner.
        $sub = Subscription::where('vatly_id', 'sub_anon_1')->firstOrFail();
        $this->assertSame($anonymousCustomerId, $sub->customer_id);
        $this->assertNull($sub->owner_id);
        $this->assertNull($sub->owner_type);

        // ---------------- 2. Same anonymous customer pays an order. ----------------
        $this->postWebhookEvent('order.paid', 'order_anon_1', 'order', [
            'customerId' => $anonymousCustomerId,
            'total' => ['currency' => 'EUR', 'value' => '99.00'],
            'invoiceNumber' => 'INV-ANON-001',
            'paymentMethod' => 'card',
        ])->assertStatus(201);

        $order = Order::where('vatly_id', 'order_anon_1')->firstOrFail();
        $this->assertSame($anonymousCustomerId, $order->customer_id);
        $this->assertNull($order->owner_id);
        $this->assertSame(9900, $order->total);

        // ---------------- 3. The visitor finally signs up. ----------------
        // In a real app the host pulls `cus_anon_alice` from the
        // checkout-success URL (e.g. `?customer=cus_anon_alice`) or a
        // session cookie set when checkout was initiated.

        $user = User::factory()->create([
            'email' => 'alice@example.test',
            'vatly_id' => null,
        ]);

        // Before claiming: the trait surface sees no purchases.
        $this->assertFalse($user->subscribed());
        $this->assertNull($user->subscription());
        $this->assertCount(0, $user->orders);

        // ---------------- 4. Claim. ----------------
        $claimed = $user->claimVatlyCustomer($anonymousCustomerId);

        // Two orphan rows (one subscription + one order) were re-attributed.
        $this->assertSame(2, $claimed);
        $this->assertSame($anonymousCustomerId, $user->fresh()->vatly_id);

        // ---------------- 5. Trait surface now works as if the user had paid all along. ----------------
        $fresh = $user->fresh();

        $this->assertCount(1, $fresh->subscriptions);
        $this->assertSame('sub_anon_1', $fresh->subscriptions->first()->vatly_id);

        $this->assertCount(1, $fresh->orders);
        $this->assertSame('order_anon_1', $fresh->orders->first()->vatly_id);

        $this->assertTrue($fresh->subscribed());

        $handle = $fresh->subscription();
        $this->assertInstanceOf(SubscriptionHandle::class, $handle);
        $this->assertSame('sub_anon_1', $handle->getVatlyId());
        $this->assertTrue($handle->active());

        // The order is reachable via $user->order($vatlyId) too.
        $orderHandle = $fresh->order('order_anon_1');
        $this->assertSame('order_anon_1', $orderHandle->getVatlyId());
    }

    public function test_claim_does_not_touch_purchases_belonging_to_a_different_anonymous_customer(): void
    {
        // Two distinct anonymous customers each made a purchase.
        $this->fakeGetSubscriptions([
            'sub_alice' => $this->buildApiSubscription([
                'id' => 'sub_alice',
                'customerId' => 'cus_alice',
                'subscriptionPlanId' => 'plan_basic',
                'name' => 'Basic',
                'quantity' => 1,
            ]),
            'sub_bob' => $this->buildApiSubscription([
                'id' => 'sub_bob',
                'customerId' => 'cus_bob',
                'subscriptionPlanId' => 'plan_basic',
                'name' => 'Basic',
                'quantity' => 1,
            ]),
        ]);

        $this->postWebhookEvent('subscription.started', 'sub_alice', 'subscription', [
            'customerId' => 'cus_alice',
            'subscriptionPlanId' => 'plan_basic',
            'quantity' => 1,
            'name' => 'Basic',
        ])->assertStatus(201);

        $this->postWebhookEvent('subscription.started', 'sub_bob', 'subscription', [
            'customerId' => 'cus_bob',
            'subscriptionPlanId' => 'plan_basic',
            'quantity' => 1,
            'name' => 'Basic',
        ])->assertStatus(201);

        // Alice signs up and claims her purchase.
        $alice = User::factory()->create(['email' => 'alice@example.test', 'vatly_id' => null]);
        $claimed = $alice->claimVatlyCustomer('cus_alice');

        $this->assertSame(1, $claimed);
        $this->assertCount(1, $alice->fresh()->subscriptions);

        // Bob's row remains orphaned.
        $bobsSub = Subscription::where('vatly_id', 'sub_bob')->firstOrFail();
        $this->assertNull($bobsSub->owner_id);
        $this->assertSame('cus_bob', $bobsSub->customer_id);
    }

    public function test_subsequent_webhook_after_signup_arrives_already_attributed(): void
    {
        // Stage the order.paid mock up-front; fluent's GetOrder is cached on
        // the composition root the moment a WebhookProcessor is resolved, so
        // any fakeGetOrder() call has to land before that resolution to take
        // effect. (Calling it after the first webhook works in principle —
        // we clear the relevant caches — but real apps don't switch the
        // GetOrder action mid-test, so we mirror that here.)
        $this->fakeGetOrder($this->buildApiOrder([
            'id' => 'order_post_signup',
            'customerId' => 'cus_charlie',
            'totalValue' => '19.00',
            'subtotalValue' => '15.70',
            'currency' => 'EUR',
            'invoiceNumber' => 'INV-POST-001',
            'paymentMethod' => 'card',
            'taxRates' => [
                ['name' => 'VAT', 'percentage' => 21.0, 'taxablePercentage' => 100.0, 'amount' => '3.30'],
            ],
        ]));

        $this->fakeGetSubscription($this->buildApiSubscription([
            'id' => 'sub_first',
            'customerId' => 'cus_charlie',
            'subscriptionPlanId' => 'plan_basic',
            'name' => 'Basic',
            'quantity' => 1,
        ]));

        // Anonymous purchase lands first.
        $this->postWebhookEvent('subscription.started', 'sub_first', 'subscription', [
            'customerId' => 'cus_charlie',
            'subscriptionPlanId' => 'plan_basic',
            'quantity' => 1,
            'name' => 'Basic',
        ])->assertStatus(201);

        // User signs up and claims.
        $user = User::factory()->create(['email' => 'charlie@example.test', 'vatly_id' => null]);
        $user->claimVatlyCustomer('cus_charlie');

        // A follow-up webhook for the same Vatly customer (e.g. they bought
        // a second plan after signing up) now finds the binding in place
        // and writes the host owner directly — no claim step needed.
        $this->postWebhookEvent('order.paid', 'order_post_signup', 'order', [
            'customerId' => 'cus_charlie',
            'total' => ['currency' => 'EUR', 'value' => '19.00'],
            'invoiceNumber' => 'INV-POST-001',
            'paymentMethod' => 'card',
        ])->assertStatus(201);

        $postSignupOrder = Order::where('vatly_id', 'order_post_signup')->firstOrFail();
        $this->assertSame($user->id, $postSignupOrder->owner_id);
        $this->assertSame('cus_charlie', $postSignupOrder->customer_id);
    }
}

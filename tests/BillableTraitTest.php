<?php

declare(strict_types=1);

namespace Vatly\Laravel\Tests;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vatly\Fluent\Builders\CheckoutBuilder;
use Vatly\Fluent\Builders\SubscriptionBuilder;
use Vatly\Fluent\CustomerProfile;
use Vatly\Fluent\Exceptions\InvalidOrderException;
use Vatly\Fluent\OrderHandle;
use Vatly\Fluent\SubscriptionHandle;
use Vatly\Fluent\Vatly;
use Vatly\Laravel\Models\Order;
use Vatly\Laravel\Models\Subscription;

class BillableTraitTest extends BaseTestCase
{
    use RefreshDatabase;

    public function test_vatly_composition_root_is_a_singleton(): void
    {
        $vatlyA = $this->app->make(Vatly::class);
        $vatlyB = $this->app->make(Vatly::class);

        $this->assertSame($vatlyA, $vatlyB);
    }

    public function test_customer_profile_snapshots_eloquent_columns(): void
    {
        $user = User::factory()->create([
            'vatly_id' => 'customer_xyz',
            'email' => 'sander@example.test',
            'name' => 'Sander',
        ]);

        $profile = $user->customerProfile();

        $this->assertInstanceOf(CustomerProfile::class, $profile);
        $this->assertSame('customer_xyz', $profile->vatlyId);
        $this->assertSame('sander@example.test', $profile->email);
        $this->assertSame('Sander', $profile->name);
    }

    public function test_subscribed_returns_false_when_no_subscription_exists(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->subscribed());
        $this->assertFalse($user->subscribed('team'));
    }

    public function test_subscribed_returns_true_for_an_active_subscription(): void
    {
        $user = User::factory()->create();

        Subscription::create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'vatly_id' => 'subscription_abc',
            'type' => 'default',
            'plan_id' => 'plan_basic',
            'name' => 'Basic',
            'quantity' => 1,
        ]);

        $this->assertTrue($user->subscribed());
        $this->assertFalse($user->subscribed('team'));
    }

    public function test_subscription_returns_null_when_none_exists(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->subscription());
    }

    public function test_subscription_returns_a_handle_when_one_exists(): void
    {
        $user = User::factory()->create();

        $subscription = Subscription::create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'vatly_id' => 'subscription_abc',
            'type' => 'default',
            'plan_id' => 'plan_basic',
            'name' => 'Basic',
            'quantity' => 1,
        ]);

        $handle = $user->subscription();

        $this->assertInstanceOf(SubscriptionHandle::class, $handle);
        $this->assertSame('subscription_abc', $handle->getVatlyId());
        $this->assertSame('plan_basic', $handle->getPlanId());
        $this->assertTrue($handle->active());
        $this->assertEquals($subscription->id, $handle->model()->getKey());
    }

    public function test_subscribe_returns_a_subscription_builder(): void
    {
        $user = User::factory()->create();

        $this->assertInstanceOf(SubscriptionBuilder::class, $user->subscribe());
    }

    public function test_checkout_returns_a_checkout_builder(): void
    {
        $user = User::factory()->create();

        $this->assertInstanceOf(CheckoutBuilder::class, $user->checkout());
    }

    public function test_vatly_accessors_read_eloquent_columns(): void
    {
        $user = User::factory()->create([
            'vatly_id' => 'customer_xyz',
            'email' => 'sander@example.test',
            'name' => 'Sander',
        ]);

        $this->assertSame('customer_xyz', $user->vatlyId());
        $this->assertTrue($user->hasVatlyId());
        $this->assertSame('sander@example.test', $user->vatlyEmail());
        $this->assertSame('Sander', $user->vatlyName());
    }

    public function test_find_billable_locates_the_user(): void
    {
        $user = User::factory()->create(['vatly_id' => 'customer_lookup']);

        $found = User::findBillable('customer_lookup');

        $this->assertNotNull($found);
        $this->assertSame($user->getKey(), $found->getKey());
    }

    public function test_find_billable_or_fail_throws_when_no_match(): void
    {
        $this->expectException(ModelNotFoundException::class);

        User::findBillableOrFail('customer_nonexistent');
    }

    public function test_order_returns_a_handle_for_a_known_order(): void
    {
        $user = User::factory()->create();

        Order::create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'vatly_id' => 'order_abc',
            'status' => 'paid',
            'total' => 9900,
            'currency' => 'EUR',
        ]);

        $handle = $user->order('order_abc');

        $this->assertInstanceOf(OrderHandle::class, $handle);
        $this->assertSame('order_abc', $handle->getVatlyId());
    }

    public function test_order_throws_invalid_order_exception_for_an_unknown_id(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidOrderException::class);
        $this->expectExceptionMessageMatches('/order_unknown/');

        $user->order('order_unknown');
    }

    public function test_order_throws_invalid_order_exception_for_an_order_owned_by_someone_else(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        Order::create([
            'owner_type' => $other->getMorphClass(),
            'owner_id' => $other->getKey(),
            'vatly_id' => 'order_someone_else',
            'status' => 'paid',
            'total' => 4900,
            'currency' => 'EUR',
        ]);

        $this->expectException(InvalidOrderException::class);

        $owner->order('order_someone_else');
    }

    public function test_claim_vatly_customer_binds_and_backfills_orphan_rows(): void
    {
        // Two orphan rows persisted by the webhook flow before any user existed,
        // both keyed by the same Vatly customer id.
        Subscription::create([
            'vatly_id' => 'sub_anon',
            'customer_id' => 'cus_anon',
            'type' => 'default',
            'plan_id' => 'plan_basic',
            'name' => 'Basic',
            'quantity' => 1,
        ]);
        Order::create([
            'vatly_id' => 'order_anon',
            'customer_id' => 'cus_anon',
            'status' => 'paid',
            'total' => 9900,
            'currency' => 'EUR',
        ]);

        // Unrelated row should not be touched.
        Order::create([
            'vatly_id' => 'order_other',
            'customer_id' => 'cus_other',
            'status' => 'paid',
            'total' => 100,
            'currency' => 'EUR',
        ]);

        $user = User::factory()->create(['vatly_id' => null]);

        $claimed = $user->claimVatlyCustomer('cus_anon');

        $this->assertSame(2, $claimed);
        $this->assertSame('cus_anon', $user->fresh()->vatly_id);

        $sub = Subscription::where('vatly_id', 'sub_anon')->first();
        $this->assertSame($user->id, $sub->owner_id);
        $this->assertSame($user->getMorphClass(), $sub->owner_type);

        $order = Order::where('vatly_id', 'order_anon')->first();
        $this->assertSame($user->id, $order->owner_id);

        $unrelated = Order::where('vatly_id', 'order_other')->first();
        $this->assertNull($unrelated->owner_id);
    }

    public function test_claim_vatly_customer_returns_zero_when_no_orphan_rows_exist(): void
    {
        $user = User::factory()->create(['vatly_id' => null]);

        $claimed = $user->claimVatlyCustomer('cus_unknown');

        $this->assertSame(0, $claimed);
        $this->assertSame('cus_unknown', $user->fresh()->vatly_id);
    }
}

<?php

declare(strict_types=1);

namespace Vatly\Laravel\Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vatly\Fluent\Billable as FluentBillable;
use Vatly\Fluent\BillableFactory;
use Vatly\Fluent\Builders\CheckoutBuilder;
use Vatly\Fluent\Builders\SubscriptionBuilder;
use Vatly\Fluent\Vatly;
use Vatly\Laravel\Models\Subscription;

class BillableTraitTest extends BaseTestCase
{
    use RefreshDatabase;

    public function test_vatly_billable_returns_a_fluent_orchestrator_bound_to_the_user(): void
    {
        $user = User::factory()->create();

        $billable = $user->vatlyBillable();

        $this->assertInstanceOf(FluentBillable::class, $billable);
        $this->assertSame($user, $billable->owner());
    }

    public function test_vatly_composition_root_is_a_singleton(): void
    {
        $vatlyA = $this->app->make(Vatly::class);
        $vatlyB = $this->app->make(Vatly::class);

        $this->assertSame($vatlyA, $vatlyB);
    }

    public function test_billable_factory_is_cached_by_the_composition_root(): void
    {
        $vatly = $this->app->make(Vatly::class);

        $this->assertSame($vatly->billableFactory(), $vatly->billableFactory());
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

        $this->assertNotNull($handle);
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

    public function test_billable_interface_methods_read_eloquent_columns(): void
    {
        $user = User::factory()->create([
            'vatly_id' => 'customer_xyz',
            'email' => 'sander@example.test',
            'name' => 'Sander',
        ]);

        $this->assertSame('customer_xyz', $user->getVatlyId());
        $this->assertTrue($user->hasVatlyId());
        $this->assertSame('sander@example.test', $user->getVatlyEmail());
        $this->assertSame('Sander', $user->getVatlyName());
    }

    public function test_set_vatly_id_writes_to_the_eloquent_column(): void
    {
        $user = User::factory()->create(['vatly_id' => null]);

        $user->setVatlyId('customer_new');

        $this->assertSame('customer_new', $user->vatly_id);
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
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        User::findBillableOrFail('customer_nonexistent');
    }
}

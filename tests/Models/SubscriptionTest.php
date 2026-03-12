<?php

declare(strict_types=1);

namespace Vatly\Laravel\Tests\Models;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vatly\Laravel\Models\Subscription;
use Vatly\Laravel\Tests\BaseTestCase;

class SubscriptionTest extends BaseTestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_implements_subscription_interface_getters()
    {
        $user = User::factory()->create([
            'vatly_id' => 'customer_456',
        ]);

        $subscription = Subscription::create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'vatly_id' => 'sub_789',
            'plan_id' => 'plan_abc',
            'name' => 'Basic Plan',
            'type' => 'basic',
            'quantity' => 1,
        ]);

        $this->assertEquals('sub_789', $subscription->getVatlyId());
        $this->assertEquals('basic', $subscription->getType());
        $this->assertEquals('plan_abc', $subscription->getPlanId());
        $this->assertEquals('Basic Plan', $subscription->getName());
        $this->assertEquals(1, $subscription->getQuantity());
        $this->assertNull($subscription->getEndsAt());
    }

    /** @test */
    public function it_identifies_active_subscriptions()
    {
        $user = User::factory()->create([
            'vatly_id' => 'customer_789',
        ]);

        $subscription = Subscription::create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'vatly_id' => 'sub_active',
            'plan_id' => 'plan_xyz',
            'name' => 'Active Plan',
            'type' => Subscription::DEFAULT_TYPE,
            'quantity' => 1,
            'ends_at' => null,
        ]);

        $this->assertTrue($subscription->isActive());
        $this->assertTrue($subscription->active()); // Legacy alias
        $this->assertFalse($subscription->isCancelled());
        $this->assertFalse($subscription->cancelled()); // Legacy alias
        $this->assertFalse($subscription->isOnGracePeriod());
        $this->assertFalse($subscription->onGracePeriod()); // Legacy alias
    }

    /** @test */
    public function it_identifies_canceled_subscriptions()
    {
        $user = User::factory()->create([
            'vatly_id' => 'customer_canceled',
        ]);

        $subscription = Subscription::create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'vatly_id' => 'sub_canceled',
            'plan_id' => 'plan_xyz',
            'name' => 'Canceled Plan',
            'type' => Subscription::DEFAULT_TYPE,
            'quantity' => 1,
            'ends_at' => now()->subDay(), // Ended in the past
        ]);

        $this->assertFalse($subscription->isActive());
        $this->assertTrue($subscription->isCancelled());
        $this->assertFalse($subscription->isOnGracePeriod());
    }

    /** @test */
    public function it_identifies_subscriptions_on_grace_period()
    {
        $user = User::factory()->create([
            'vatly_id' => 'customer_grace',
        ]);

        $subscription = Subscription::create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'vatly_id' => 'sub_grace',
            'plan_id' => 'plan_xyz',
            'name' => 'Grace Period Plan',
            'type' => Subscription::DEFAULT_TYPE,
            'quantity' => 1,
            'ends_at' => now()->addWeek(), // Ends in the future
        ]);

        $this->assertTrue($subscription->isActive()); // Still active during grace period
        $this->assertTrue($subscription->isCancelled()); // But is cancelled
        $this->assertTrue($subscription->isOnGracePeriod());
    }
}

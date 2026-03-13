<?php

declare(strict_types=1);

namespace Vatly\Laravel\Tests\Models;

use Carbon\Carbon;
use Mockery;
use Vatly\API\Resources\Subscription as ApiSubscription;
use Vatly\Fluent\Actions\GetSubscription;
use Vatly\Laravel\Models\Subscription;
use Vatly\Laravel\Tests\BaseTestCase;

class SubscriptionSyncTest extends BaseTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_updates_local_subscription_with_fresh_data_from_vatly(): void
    {
        $apiSubscription = Mockery::mock(ApiSubscription::class);
        $apiSubscription->id = 'subscription_test123';
        $apiSubscription->subscriptionPlanId = 'plan_new';
        $apiSubscription->name = 'New Plan';
        $apiSubscription->quantity = 5;
        $apiSubscription->status = 'active';
        $apiSubscription->endedAt = null;
        $apiSubscription->cancelledAt = null;
        $apiSubscription->trialUntil = null;

        $mockAction = Mockery::mock(GetSubscription::class);
        $mockAction->shouldReceive('execute')
            ->once()
            ->with('subscription_test123')
            ->andReturn($apiSubscription);

        app()->instance(GetSubscription::class, $mockAction);

        $subscription = Mockery::mock(Subscription::class)->makePartial();
        $subscription->vatly_id = 'subscription_test123';
        $subscription->shouldReceive('save')
            ->once()
            ->andReturn(true);

        $result = $subscription->sync();

        $this->assertSame($subscription, $result);
        $this->assertSame('plan_new', $subscription->plan_id);
        $this->assertSame('New Plan', $subscription->name);
        $this->assertSame(5, $subscription->quantity);
    }

    /** @test */
    public function it_syncs_ends_at_when_subscription_is_ended(): void
    {
        $endedAt = '2026-03-01T00:00:00Z';

        $apiSubscription = Mockery::mock(ApiSubscription::class);
        $apiSubscription->id = 'subscription_test123';
        $apiSubscription->subscriptionPlanId = 'plan_123';
        $apiSubscription->name = 'Plan';
        $apiSubscription->quantity = 1;
        $apiSubscription->status = 'canceled';
        $apiSubscription->endedAt = $endedAt;
        $apiSubscription->cancelledAt = null;
        $apiSubscription->trialUntil = null;

        $mockAction = Mockery::mock(GetSubscription::class);
        $mockAction->shouldReceive('execute')
            ->once()
            ->andReturn($apiSubscription);

        app()->instance(GetSubscription::class, $mockAction);

        $subscription = Mockery::mock(Subscription::class)->makePartial();
        $subscription->vatly_id = 'subscription_test123';
        $subscription->shouldReceive('save')
            ->once()
            ->andReturn(true);

        $subscription->sync();

        $this->assertInstanceOf(Carbon::class, $subscription->ends_at);
        $this->assertEquals(Carbon::parse($endedAt)->toIso8601String(), $subscription->ends_at->toIso8601String());
    }

    /** @test */
    public function it_syncs_trial_ends_at_when_present(): void
    {
        $trialUntil = '2026-02-15T00:00:00Z';

        $apiSubscription = Mockery::mock(ApiSubscription::class);
        $apiSubscription->id = 'subscription_test123';
        $apiSubscription->subscriptionPlanId = 'plan_123';
        $apiSubscription->name = 'Trial Plan';
        $apiSubscription->quantity = 1;
        $apiSubscription->status = 'trial';
        $apiSubscription->endedAt = null;
        $apiSubscription->cancelledAt = null;
        $apiSubscription->trialUntil = $trialUntil;

        $mockAction = Mockery::mock(GetSubscription::class);
        $mockAction->shouldReceive('execute')
            ->once()
            ->andReturn($apiSubscription);

        app()->instance(GetSubscription::class, $mockAction);

        $subscription = Mockery::mock(Subscription::class)->makePartial();
        $subscription->vatly_id = 'subscription_test123';
        $subscription->shouldReceive('save')
            ->once()
            ->andReturn(true);

        $subscription->sync();

        $this->assertInstanceOf(Carbon::class, $subscription->trial_ends_at);
    }
}

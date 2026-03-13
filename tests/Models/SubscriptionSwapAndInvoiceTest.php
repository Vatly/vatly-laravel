<?php

declare(strict_types=1);

namespace Vatly\Laravel\Tests\Models;

use Mockery;
use Vatly\API\Resources\Subscription as ApiSubscription;
use Vatly\Fluent\Actions\SwapSubscriptionPlan;
use Vatly\Laravel\Models\Subscription;
use Vatly\Laravel\Tests\BaseTestCase;

class SubscriptionSwapAndInvoiceTest extends BaseTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_swaps_plan_with_immediate_invoicing(): void
    {
        $apiSubscription = Mockery::mock(ApiSubscription::class);
        $apiSubscription->id = 'subscription_test123';
        $apiSubscription->subscriptionPlanId = 'plan_premium';
        $apiSubscription->quantity = 1;

        $mockAction = Mockery::mock(SwapSubscriptionPlan::class);
        $mockAction->shouldReceive('execute')
            ->once()
            ->with(
                'subscription_test123',
                'plan_premium',
                Mockery::on(function ($options) {
                    return $options['applyImmediately'] === true
                        && $options['invoiceImmediately'] === true;
                })
            )
            ->andReturn($apiSubscription);

        app()->instance(SwapSubscriptionPlan::class, $mockAction);

        $subscription = Mockery::mock(Subscription::class)->makePartial();
        $subscription->vatly_id = 'subscription_test123';
        $subscription->shouldReceive('save')
            ->once()
            ->andReturn(true);

        $result = $subscription->swapAndInvoice('premium', 'plan_premium');

        $this->assertSame($subscription, $result);
        $this->assertSame('premium', $subscription->type);
        $this->assertSame('plan_premium', $subscription->plan_id);
        $this->assertSame(1, $subscription->quantity);
    }

    /** @test */
    public function it_passes_additional_options_while_forcing_immediate_flags(): void
    {
        $apiSubscription = Mockery::mock(ApiSubscription::class);
        $apiSubscription->id = 'subscription_test123';
        $apiSubscription->subscriptionPlanId = 'plan_enterprise';
        $apiSubscription->quantity = 5;

        $mockAction = Mockery::mock(SwapSubscriptionPlan::class);
        $mockAction->shouldReceive('execute')
            ->once()
            ->with(
                'subscription_test123',
                'plan_enterprise',
                Mockery::on(function ($options) {
                    return $options['prorate'] === true
                        && $options['applyImmediately'] === true
                        && $options['invoiceImmediately'] === true;
                })
            )
            ->andReturn($apiSubscription);

        app()->instance(SwapSubscriptionPlan::class, $mockAction);

        $subscription = Mockery::mock(Subscription::class)->makePartial();
        $subscription->vatly_id = 'subscription_test123';
        $subscription->shouldReceive('save')
            ->once()
            ->andReturn(true);

        $subscription->swapAndInvoice('enterprise', 'plan_enterprise', ['prorate' => true]);
        
        $this->assertTrue(true); // Mockery verifies expectations
    }

    /** @test */
    public function it_overrides_user_provided_immediate_flags(): void
    {
        $apiSubscription = Mockery::mock(ApiSubscription::class);
        $apiSubscription->id = 'subscription_test123';
        $apiSubscription->subscriptionPlanId = 'plan_basic';
        $apiSubscription->quantity = 1;

        $mockAction = Mockery::mock(SwapSubscriptionPlan::class);
        $mockAction->shouldReceive('execute')
            ->once()
            ->with(
                'subscription_test123',
                'plan_basic',
                Mockery::on(function ($options) {
                    return $options['applyImmediately'] === true
                        && $options['invoiceImmediately'] === true;
                })
            )
            ->andReturn($apiSubscription);

        app()->instance(SwapSubscriptionPlan::class, $mockAction);

        $subscription = Mockery::mock(Subscription::class)->makePartial();
        $subscription->vatly_id = 'subscription_test123';
        $subscription->shouldReceive('save')
            ->once()
            ->andReturn(true);

        $subscription->swapAndInvoice('basic', 'plan_basic', [
            'applyImmediately' => false,
            'invoiceImmediately' => false,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Vatly\Laravel\Tests\Models;

use Mockery;
use Vatly\API\Types\Link;
use Vatly\Fluent\Actions\CreateSubscriptionBillingUpdateLink;
use Vatly\Laravel\Models\Subscription;
use Vatly\Laravel\Tests\BaseTestCase;

class SubscriptionCreateBillingUpdateLinkTest extends BaseTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_returns_the_billing_update_link(): void
    {
        $expectedUrl = 'https://checkout.vatly.com/billing-update/abc123';

        $mockAction = Mockery::mock(CreateSubscriptionBillingUpdateLink::class);
        $mockAction->shouldReceive('execute')
            ->once()
            ->with('subscription_test123', [])
            ->andReturn(new Link($expectedUrl, 'text/html'));

        app()->instance(CreateSubscriptionBillingUpdateLink::class, $mockAction);

        $subscription = new Subscription(['vatly_id' => 'subscription_test123']);
        $url = $subscription->createBillingUpdateLink();

        $this->assertSame($expectedUrl, $url);
    }

    /** @test */
    public function it_passes_prefill_data_to_the_action(): void
    {
        $prefillData = ['billingAddress' => ['city' => 'Amsterdam']];

        $mockAction = Mockery::mock(CreateSubscriptionBillingUpdateLink::class);
        $mockAction->shouldReceive('execute')
            ->once()
            ->with('subscription_test123', $prefillData)
            ->andReturn(new Link('https://checkout.vatly.com/billing-update', 'text/html'));

        app()->instance(CreateSubscriptionBillingUpdateLink::class, $mockAction);

        $subscription = new Subscription(['vatly_id' => 'subscription_test123']);
        $url = $subscription->createBillingUpdateLink($prefillData);

        $this->assertSame('https://checkout.vatly.com/billing-update', $url);
    }
}

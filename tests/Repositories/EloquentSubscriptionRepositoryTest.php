<?php

declare(strict_types=1);

namespace Vatly\Laravel\Tests\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vatly\Fluent\Data\StoreSubscriptionData;
use Vatly\Fluent\Data\UpdateSubscriptionData;
use Vatly\Laravel\Models\Subscription;
use Vatly\Laravel\Repositories\EloquentSubscriptionRepository;
use Vatly\Laravel\Tests\BaseTestCase;

class EloquentSubscriptionRepositoryTest extends BaseTestCase
{
    use RefreshDatabase;

    private EloquentSubscriptionRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = $this->app->make(EloquentSubscriptionRepository::class);
    }

    public function test_update_with_clear_ends_at_nulls_the_column(): void
    {
        $subscription = $this->makeSubscription([
            'ends_at' => CarbonImmutable::parse('2026-06-01T00:00:00+00:00'),
        ]);

        $this->repo->update($subscription, new UpdateSubscriptionData(
            planId: 'plan_basic',
            quantity: 1,
            clearEndsAt: true,
        ));

        $this->assertNull($subscription->fresh()->ends_at);
    }

    public function test_update_with_ends_at_sets_the_column(): void
    {
        $subscription = $this->makeSubscription(['ends_at' => null]);

        $endsAt = CarbonImmutable::parse('2026-07-15T12:00:00+00:00');

        $this->repo->update($subscription, new UpdateSubscriptionData(
            endsAt: $endsAt,
        ));

        $this->assertSame(
            $endsAt->format('Y-m-d H:i:s'),
            $subscription->fresh()->ends_at->format('Y-m-d H:i:s'),
        );
    }

    public function test_update_ignores_clear_ends_at_when_ends_at_is_also_provided(): void
    {
        // endsAt wins — clearEndsAt is the "no replacement value" signal,
        // not "force-null even if a replacement was given".
        $subscription = $this->makeSubscription(['ends_at' => null]);

        $endsAt = CarbonImmutable::parse('2026-08-01T00:00:00+00:00');

        $this->repo->update($subscription, new UpdateSubscriptionData(
            endsAt: $endsAt,
            clearEndsAt: true,
        ));

        $this->assertNotNull($subscription->fresh()->ends_at);
        $this->assertSame(
            $endsAt->format('Y-m-d H:i:s'),
            $subscription->fresh()->ends_at->format('Y-m-d H:i:s'),
        );
    }

    public function test_update_leaves_ends_at_alone_when_neither_flag_is_set(): void
    {
        $existing = CarbonImmutable::parse('2026-09-01T00:00:00+00:00');

        $subscription = $this->makeSubscription(['ends_at' => $existing]);

        $this->repo->update($subscription, new UpdateSubscriptionData(
            planId: 'plan_premium',
        ));

        $this->assertSame(
            $existing->format('Y-m-d H:i:s'),
            $subscription->fresh()->ends_at->format('Y-m-d H:i:s'),
        );
        $this->assertSame('plan_premium', $subscription->fresh()->plan_id);
    }

    public function test_store_persists_mandate_fields_when_present(): void
    {
        $stored = $this->repo->store(new StoreSubscriptionData(
            vatlyId: 'subscription_with_mandate',
            customerId: 'cus_xyz',
            type: 'default',
            planId: 'plan_basic',
            name: 'Basic',
            quantity: 1,
            mandateMethod: 'card',
            mandateMaskedIdentifier: '4242',
        ));

        $this->assertSame('card', $stored->getMandateMethod());
        $this->assertSame('4242', $stored->getMandateMaskedIdentifier());
        $this->assertDatabaseHas('vatly_subscriptions', [
            'vatly_id' => 'subscription_with_mandate',
            'mandate_method' => 'card',
            'mandate_masked_identifier' => '4242',
        ]);
    }

    public function test_store_leaves_mandate_null_when_not_supplied(): void
    {
        $stored = $this->repo->store(new StoreSubscriptionData(
            vatlyId: 'subscription_no_mandate',
            customerId: 'cus_xyz',
            type: 'default',
            planId: 'plan_basic',
            name: 'Basic',
            quantity: 1,
        ));

        $this->assertNull($stored->getMandateMethod());
        $this->assertNull($stored->getMandateMaskedIdentifier());
    }

    public function test_update_writes_mandate_fields_when_present(): void
    {
        $subscription = $this->makeSubscription([
            'mandate_method' => 'card',
            'mandate_masked_identifier' => '4242',
        ]);

        $this->repo->update($subscription, new UpdateSubscriptionData(
            mandateMethod: 'sepa_debit',
            mandateMaskedIdentifier: 'NL91****4300',
        ));

        $fresh = $subscription->fresh();
        $this->assertSame('sepa_debit', $fresh->mandate_method);
        $this->assertSame('NL91****4300', $fresh->mandate_masked_identifier);
    }

    public function test_update_leaves_existing_mandate_alone_when_data_is_null(): void
    {
        $subscription = $this->makeSubscription([
            'mandate_method' => 'card',
            'mandate_masked_identifier' => '4242',
        ]);

        $this->repo->update($subscription, new UpdateSubscriptionData(
            planId: 'plan_premium',
        ));

        $fresh = $subscription->fresh();
        $this->assertSame('card', $fresh->mandate_method);
        $this->assertSame('4242', $fresh->mandate_masked_identifier);
        $this->assertSame('plan_premium', $fresh->plan_id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeSubscription(array $overrides = []): Subscription
    {
        return Subscription::create(array_merge([
            'type' => 'default',
            'plan_id' => 'plan_basic',
            'vatly_id' => 'subscription_'.bin2hex(random_bytes(8)),
            'name' => 'Test subscription',
            'quantity' => 1,
        ], $overrides));
    }
}

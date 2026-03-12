<?php

declare(strict_types=1);

namespace Vatly\Laravel\Repositories;

use Vatly\Fluent\Contracts\BillableInterface;
use Vatly\Fluent\Contracts\CustomerRepositoryInterface;
use Vatly\Fluent\Contracts\SubscriptionInterface;
use Vatly\Fluent\Contracts\SubscriptionRepositoryInterface;
use Vatly\Laravel\Models\Subscription;

class EloquentSubscriptionRepository implements SubscriptionRepositoryInterface
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
    ) {
        //
    }

    public function findByVatlyId(string $vatlyId): ?SubscriptionInterface
    {
        return Subscription::where('vatly_id', $vatlyId)->first();
    }

    public function findByOwnerAndType(BillableInterface $owner, string $type): ?SubscriptionInterface
    {
        return Subscription::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('type', $type)
            ->first();
    }

    /**
     * @return SubscriptionInterface[]
     */
    public function findAllByOwner(BillableInterface $owner): array
    {
        return Subscription::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->get()
            ->all();
    }

    public function ownerHasActiveSubscription(BillableInterface $owner, string $type): bool
    {
        $subscription = $this->findByOwnerAndType($owner, $type);

        return $subscription !== null && $subscription->isActive();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): SubscriptionInterface
    {
        if (isset($attributes['customer_id']) && !isset($attributes['owner_type'])) {
            $owner = $this->customers->findByVatlyIdOrFail($attributes['customer_id']);
            $attributes['owner_type'] = $owner->getMorphClass();
            $attributes['owner_id'] = $owner->getKey();
            unset($attributes['customer_id']);
        }

        return Subscription::create($attributes);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(SubscriptionInterface $subscription, array $attributes): SubscriptionInterface
    {
        if ($subscription instanceof Subscription) {
            $subscription->update($attributes);
        }

        return $subscription;
    }
}

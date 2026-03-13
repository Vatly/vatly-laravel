<?php

declare(strict_types=1);

namespace Vatly\Laravel\Repositories;

use Carbon\Carbon;
use Vatly\Fluent\Contracts\BillableInterface;
use Vatly\Fluent\Contracts\CustomerRepositoryInterface;
use Vatly\Fluent\Contracts\SubscriptionInterface;
use Vatly\Fluent\Contracts\SubscriptionRepositoryInterface;
use Vatly\Fluent\Data\StoreSubscriptionData;
use Vatly\Fluent\Data\UpdateSubscriptionData;
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

    public function store(StoreSubscriptionData $data): SubscriptionInterface
    {
        $owner = $this->customers->findByVatlyIdOrFail($data->customerId);

        return Subscription::create([
            'vatly_id' => $data->vatlyId,
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'type' => $data->type,
            'plan_id' => $data->planId,
            'name' => $data->name,
            'quantity' => $data->quantity,
        ]);
    }

    public function update(SubscriptionInterface $subscription, UpdateSubscriptionData $data): SubscriptionInterface
    {
        if ($subscription instanceof Subscription) {
            if ($data->planId !== null) {
                $subscription->plan_id = $data->planId;
            }
            if ($data->name !== null) {
                $subscription->name = $data->name;
            }
            if ($data->quantity !== null) {
                $subscription->quantity = $data->quantity;
            }
            if ($data->endsAt !== null) {
                $subscription->ends_at = Carbon::instance($data->endsAt);
            }
            $subscription->save();
        }

        return $subscription;
    }
}

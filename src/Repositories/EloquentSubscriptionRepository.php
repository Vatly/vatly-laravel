<?php

declare(strict_types=1);

namespace Vatly\Laravel\Repositories;

use Carbon\Carbon;
use Vatly\Fluent\Contracts\SubscriptionInterface;
use Vatly\Fluent\Contracts\SubscriptionRepositoryInterface;
use Vatly\Fluent\Data\StoreSubscriptionData;
use Vatly\Fluent\Data\UpdateSubscriptionData;
use Vatly\Laravel\Models\Subscription;
use Vatly\Laravel\VatlyConfig;

class EloquentSubscriptionRepository implements SubscriptionRepositoryInterface
{
    public function __construct(
        private readonly VatlyConfig $config,
    ) {
        //
    }

    public function findByVatlyId(string $vatlyId): ?SubscriptionInterface
    {
        return Subscription::where('vatly_id', $vatlyId)->first();
    }

    public function store(StoreSubscriptionData $data): SubscriptionInterface
    {
        $attrs = [
            'vatly_id' => $data->vatlyId,
            'type'     => $data->type,
            'plan_id'  => $data->planId,
            'name'     => $data->name,
            'quantity' => $data->quantity,
        ];

        if ($data->hostCustomerId !== null) {
            $model = $this->config->getBillableModel();
            $attrs['owner_type'] = (new $model)->getMorphClass();
            $attrs['owner_id']   = $data->hostCustomerId;
        }

        return Subscription::create($attrs);
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

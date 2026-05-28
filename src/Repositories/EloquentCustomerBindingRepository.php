<?php

declare(strict_types=1);

namespace Vatly\Laravel\Repositories;

use Vatly\Fluent\Contracts\CustomerBindingRepository;
use Vatly\Laravel\VatlyConfig;

/**
 * Stores the link between a Vatly customer id and a billable Eloquent model's
 * primary key as a column on the billable table itself (the
 * `vatly_id` column added by the published billable-columns migration).
 *
 * Drivers that can't add a column to the host table can swap in a join-table
 * implementation instead — see fluent's recipes for the pattern.
 */
final class EloquentCustomerBindingRepository implements CustomerBindingRepository
{
    public function __construct(private VatlyConfig $config)
    {
    }

    public function bind(string $vatlyId, string $hostId): void
    {
        $model = $this->config->getBillableModel();
        $model::query()->whereKey($hostId)->update(['vatly_id' => $vatlyId]);
    }

    public function record(string $vatlyId): void
    {
        // No-op for the Laravel driver: anonymous customers leave a null
        // owner on subscription/order rows until attributed later. Drivers
        // that want explicit unattributed tracking can swap in a custom
        // CustomerBindingRepository.
    }

    public function hostIdFor(string $vatlyId): ?string
    {
        $model = $this->config->getBillableModel();
        $instance = new $model;
        $key = $model::query()->where('vatly_id', $vatlyId)->value($instance->getKeyName());

        return $key !== null ? (string) $key : null;
    }

    public function vatlyIdFor(string $hostId): ?string
    {
        $model = $this->config->getBillableModel();

        return $model::query()->whereKey($hostId)->value('vatly_id');
    }
}

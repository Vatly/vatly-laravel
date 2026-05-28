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
 * implementation instead — see fluent's README step 2 for the pattern.
 */
final class EloquentCustomerBindingRepository implements CustomerBindingRepository
{
    public function __construct(private VatlyConfig $config)
    {
    }

    public function bind(string $vatlyCustomerId, string $hostCustomerId): void
    {
        $model = $this->config->getBillableModel();
        $model::query()->whereKey($hostCustomerId)->update(['vatly_id' => $vatlyCustomerId]);
    }

    public function record(string $vatlyCustomerId): void
    {
        // No-op for the Laravel driver: anonymous customers leave a null
        // owner on subscription/order rows until attributed later. Drivers
        // that want explicit unattributed tracking can swap in a custom
        // CustomerBindingRepository.
    }

    public function hostCustomerIdFor(string $vatlyCustomerId): ?string
    {
        $model = $this->config->getBillableModel();
        $instance = new $model;
        $key = $model::query()->where('vatly_id', $vatlyCustomerId)->value($instance->getKeyName());

        return $key !== null ? (string) $key : null;
    }

    public function vatlyCustomerIdFor(string $hostCustomerId): ?string
    {
        $model = $this->config->getBillableModel();

        return $model::query()->whereKey($hostCustomerId)->value('vatly_id');
    }
}

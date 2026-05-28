<?php

declare(strict_types=1);

namespace Vatly\Laravel\Repositories;

use Vatly\Fluent\Contracts\CustomerBindingRepository;
use Vatly\Laravel\Exceptions\AnonymousVatlyCustomerNotSupportedException;
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
    public function __construct(private VatlyConfig $config) {}

    public function bind(string $vatlyCustomerId, string $hostCustomerId): void
    {
        $model = $this->config->getBillableModel();
        $model::query()->whereKey($hostCustomerId)->update(['vatly_id' => $vatlyCustomerId]);
    }

    /**
     * Acknowledge that a Vatly customer id has been seen.
     *
     * For the default Eloquent impl, "tracking" only happens via the
     * vatly_id column on the billable table — there's no separate place
     * for unattributed customers. So `record()` is a cheap no-op when
     * the id is already bound, and a hard failure when it isn't.
     *
     * @throws AnonymousVatlyCustomerNotSupportedException When no host entity carries this id.
     */
    public function record(string $vatlyCustomerId): void
    {
        $model = $this->config->getBillableModel();

        if (! $model::query()->where('vatly_id', $vatlyCustomerId)->exists()) {
            throw AnonymousVatlyCustomerNotSupportedException::forVatlyCustomerId($vatlyCustomerId);
        }
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

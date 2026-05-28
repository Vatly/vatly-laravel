<?php

declare(strict_types=1);

namespace Vatly\Laravel\Repositories;

use Vatly\Fluent\Contracts\CustomerBindingRepository;
use Vatly\Fluent\CustomerService;
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
     * Intentionally a no-op for the default Eloquent impl. Fluent's webhook
     * reactions call `record()` for every incoming customer id — including
     * the anonymous-checkout case where no host entity has been bound yet —
     * and rely on the call being idempotent and side-effect-free for any
     * driver that doesn't separately track unattributed customers.
     *
     * For this driver, the binding only exists as a `vatly_id` column on
     * the billable table, so there's no separate place to record an
     * unattributed customer. Subscription / order rows persist with a
     * null `owner_id` and can be linked later via
     * {@see CustomerService::attribute()}.
     *
     * Drivers that want eager tracking of unattributed customers (a
     * dedicated join table, an audit log, etc.) can swap in a custom
     * `CustomerBindingRepository`.
     */
    public function record(string $vatlyCustomerId): void
    {
        // no-op (see docblock).
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

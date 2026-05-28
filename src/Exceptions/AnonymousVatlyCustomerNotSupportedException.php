<?php

declare(strict_types=1);

namespace Vatly\Laravel\Exceptions;

use RuntimeException;
use Vatly\Fluent\Contracts\CustomerBindingRepository;
use Vatly\Fluent\Exceptions\VatlyException;
use Vatly\Laravel\Repositories\EloquentCustomerBindingRepository;

/**
 * Thrown when fluent's reactions try to track a Vatly customer the
 * default {@see EloquentCustomerBindingRepository}
 * has no record of.
 *
 * The default Eloquent impl stores the binding as a column on the host
 * billable table, so it can only see customers already bound to a host.
 * Anonymous-checkout flows (webhook arrives for a Vatly customer that
 * isn't yet linked to any host entity) need either pre-binding or a
 * custom {@see CustomerBindingRepository} that
 * persists unattributed ids separately (e.g. a dedicated join table).
 */
final class AnonymousVatlyCustomerNotSupportedException extends RuntimeException implements VatlyException
{
    public static function forVatlyCustomerId(string $vatlyCustomerId): self
    {
        return new self(
            "Vatly customer '{$vatlyCustomerId}' is not bound to any host entity. "
            .'The default EloquentCustomerBindingRepository tracks customers via the '
            ."vatly_id column on the billable table, so it can't record anonymous "
            .'customers. Either bind the customer to a host before the webhook fires, '
            .'or swap in a custom CustomerBindingRepository that records unattributed '
            .'customers separately.'
        );
    }
}

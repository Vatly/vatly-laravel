<?php

declare(strict_types=1);

namespace Vatly\Laravel;

use Vatly\Laravel\Concerns\ManagesApiClient;
use Vatly\Laravel\Concerns\ManagesCheckouts;
use Vatly\Laravel\Concerns\ManagesCustomer;
use Vatly\Laravel\Concerns\ManagesOrders;
use Vatly\Laravel\Concerns\ManagesSubscriptions;

/**
 * Trait to add Vatly billing capabilities to an Eloquent model.
 *
 * Use this trait on your User model (or any billable entity) to enable
 * subscription management and checkout functionality.
 *
 * Your model should also implement BillableInterface:
 *
 * ```php
 * use Vatly\Fluent\Contracts\BillableInterface;
 * use Vatly\Laravel\Billable;
 *
 * class User extends Model implements BillableInterface
 * {
 *     use Billable;
 * }
 * ```
 *
 * The ManagesCustomer concern provides the BillableInterface implementation.
 */
trait Billable
{
    use ManagesApiClient;
    use ManagesCheckouts;
    use ManagesCustomer;
    use ManagesOrders;
    use ManagesSubscriptions;
}

<?php

declare(strict_types=1);

use Vatly\Fluent\Billable as FluentBillable;
use Vatly\Fluent\BillableFactory;
use Vatly\Fluent\Contracts\BillableInterface;

if (! function_exists('vatly')) {
    /**
     * Resolve the Vatly orchestrator for a given owner.
     *
     * Returns null when $owner doesn't implement BillableInterface — useful
     * for apps that toggle the Vatly\Laravel\Billable trait on/off (e.g.
     * Larafast's "uncomment one provider" pattern). Call sites stay valid
     * whether the trait is currently applied or not:
     *
     *     vatly(Auth::user())?->subscribe(...)->plan('pro')->create();
     */
    function vatly(?object $owner): ?FluentBillable
    {
        if (! $owner instanceof BillableInterface) {
            return null;
        }

        return app(BillableFactory::class)->forOwner($owner);
    }
}

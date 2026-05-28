<?php

declare(strict_types=1);

namespace Vatly\Laravel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Vatly\Laravel\Models\VatlyWebhookCall;

class VatlyHelpers
{
    /**
     * Get the billable instance by its Vatly customer ID.
     */
    public static function findBillable(string $vatlyCustomerId): ?Model
    {
        $billableModel = app()->make(VatlyConfig::class)->getBillableModel();

        return $billableModel::where('vatly_id', $vatlyCustomerId)->first();
    }

    /**
     * Get the billable instance by its Vatly customer ID.
     *
     * @throws ModelNotFoundException
     */
    public static function findBillableOrFail(string $vatlyCustomerId): Model
    {
        $billableModel = app()->make(VatlyConfig::class)->getBillableModel();

        return $billableModel::where('vatly_id', $vatlyCustomerId)->firstOrFail();
    }

    public static function cleanUp(): void
    {
        VatlyWebhookCall::cleanUp();
    }
}

<?php

declare(strict_types=1);

namespace Vatly\Laravel\Repositories;

use Vatly\Fluent\Contracts\RefundInterface;
use Vatly\Fluent\Contracts\RefundRepositoryInterface;
use Vatly\Fluent\Data\StoreRefundData;
use Vatly\Fluent\Data\UpdateRefundData;
use Vatly\Laravel\Models\Refund;
use Vatly\Laravel\VatlyConfig;

class EloquentRefundRepository implements RefundRepositoryInterface
{
    public function __construct(
        private readonly VatlyConfig $config,
    ) {
        //
    }

    public function findByVatlyId(string $vatlyId): ?RefundInterface
    {
        return Refund::where('vatly_id', $vatlyId)->first();
    }

    public function store(StoreRefundData $data): RefundInterface
    {
        $attrs = [
            'vatly_id' => $data->vatlyId,
            'customer_id' => $data->customerId,
            'original_order_id' => $data->originalOrderId,
            'status' => $data->status,
            'total' => $data->total,
            'subtotal' => $data->subtotal,
            'tax_summary' => $data->taxSummary?->toArray(),
            'currency' => $data->currency,
        ];

        if ($data->hostCustomerId !== null) {
            $model = $this->config->getBillableModel();
            $attrs['owner_type'] = (new $model)->getMorphClass();
            $attrs['owner_id'] = $data->hostCustomerId;
        }

        return Refund::create($attrs);
    }

    public function update(RefundInterface $refund, UpdateRefundData $data): RefundInterface
    {
        if ($refund instanceof Refund) {
            if ($data->status !== null) {
                $refund->status = $data->status;
            }
            if ($data->total !== null) {
                $refund->total = $data->total;
            }
            if ($data->subtotal !== null) {
                $refund->subtotal = $data->subtotal;
            }
            if ($data->taxSummary !== null) {
                $refund->tax_summary = $data->taxSummary->toArray();
            }
            if ($data->currency !== null) {
                $refund->currency = $data->currency;
            }
            $refund->save();
        }

        return $refund;
    }
}

<?php

declare(strict_types=1);

namespace Vatly\Laravel\Repositories;

use Vatly\Fluent\Contracts\OrderInterface;
use Vatly\Fluent\Contracts\OrderRepositoryInterface;
use Vatly\Fluent\Data\StoreOrderData;
use Vatly\Fluent\Data\UpdateOrderData;
use Vatly\Laravel\Models\Order;
use Vatly\Laravel\VatlyConfig;

class EloquentOrderRepository implements OrderRepositoryInterface
{
    public function __construct(
        private readonly VatlyConfig $config,
    ) {
        //
    }

    public function findByVatlyId(string $vatlyId): ?OrderInterface
    {
        return Order::where('vatly_id', $vatlyId)->first();
    }

    public function store(StoreOrderData $data): OrderInterface
    {
        $attrs = [
            'vatly_id'       => $data->vatlyId,
            'status'         => $data->status,
            'total'          => $data->total,
            'subtotal'       => $data->subtotal,
            'tax_summary'    => $data->taxSummary?->toArray(),
            'currency'       => $data->currency,
            'invoice_number' => $data->invoiceNumber,
            'payment_method' => $data->paymentMethod,
        ];

        if ($data->hostCustomerId !== null) {
            $model = $this->config->getBillableModel();
            $attrs['owner_type'] = (new $model)->getMorphClass();
            $attrs['owner_id']   = $data->hostCustomerId;
        }

        return Order::create($attrs);
    }

    public function update(OrderInterface $order, UpdateOrderData $data): OrderInterface
    {
        if ($order instanceof Order) {
            if ($data->status !== null) {
                $order->status = $data->status;
            }
            if ($data->total !== null) {
                $order->total = $data->total;
            }
            if ($data->subtotal !== null) {
                $order->subtotal = $data->subtotal;
            }
            if ($data->taxSummary !== null) {
                $order->tax_summary = $data->taxSummary->toArray();
            }
            if ($data->currency !== null) {
                $order->currency = $data->currency;
            }
            if ($data->invoiceNumber !== null) {
                $order->invoice_number = $data->invoiceNumber;
            }
            if ($data->paymentMethod !== null) {
                $order->payment_method = $data->paymentMethod;
            }
            $order->save();
        }

        return $order;
    }
}

<?php

declare(strict_types=1);

namespace Vatly\Laravel\Repositories;

use Vatly\Fluent\Contracts\BillableInterface;
use Vatly\Fluent\Contracts\CustomerRepositoryInterface;
use Vatly\Fluent\Contracts\OrderInterface;
use Vatly\Fluent\Contracts\OrderRepositoryInterface;
use Vatly\Fluent\Data\StoreOrderData;
use Vatly\Fluent\Data\UpdateOrderData;
use Vatly\Laravel\Models\Order;

class EloquentOrderRepository implements OrderRepositoryInterface
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
    ) {
        //
    }

    public function findByVatlyId(string $vatlyId): ?OrderInterface
    {
        return Order::where('vatly_id', $vatlyId)->first();
    }

    /**
     * @return OrderInterface[]
     */
    public function findAllByOwner(BillableInterface $owner): array
    {
        return Order::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    public function store(StoreOrderData $data): OrderInterface
    {
        $owner = $this->customers->findByVatlyIdOrFail($data->customerId);

        return Order::create([
            'vatly_id' => $data->vatlyId,
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'status' => $data->status,
            'total' => $data->total,
            'currency' => $data->currency,
            'invoice_number' => $data->invoiceNumber,
            'payment_method' => $data->paymentMethod,
        ]);
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

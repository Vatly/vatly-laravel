<?php

declare(strict_types=1);

namespace Vatly\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Vatly\Fluent\Contracts\BillableInterface;
use Vatly\Fluent\Contracts\OrderInterface;

/**
 * @property string $vatly_id
 * @property string $owner_type
 * @property int $owner_id
 * @property string $status
 * @property int $total
 * @property int|null $subtotal
 * @property array<int, array{rate: array{name: string, percentage: float, taxablePercentage: float}, amount: int, currency: string}>|null $tax_summary
 * @property string $currency
 * @property string|null $invoice_number
 * @property string|null $payment_method
 *
 * @method static create(array<string, mixed> $array)
 * @method static where(string $column, mixed $value)
 */
class Order extends Model implements OrderInterface
{
    protected $table = 'vatly_orders';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'tax_summary' => 'array',
    ];

    /**
     * @return MorphTo<Model, Order>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo('owner');
    }

    // OrderInterface implementation

    public function getVatlyId(): string
    {
        return $this->vatly_id;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getInvoiceNumber(): ?string
    {
        return $this->invoice_number;
    }

    public function getTotal(): int
    {
        return (int) $this->total;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getPaymentMethod(): ?string
    {
        return $this->payment_method;
    }

    public function getOwner(): BillableInterface
    {
        return $this->owner;
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /**
     * Get the invoice URL for this order.
     *
     * Fetches the order from the Vatly API and returns the invoice link.
     */
    public function invoiceUrl(): ?string
    {
        /** @var \Vatly\API\VatlyApiClient $client */
        $client = app()->make(\Vatly\API\VatlyApiClient::class);
        $apiOrder = $client->orders->get($this->vatly_id);

        return $apiOrder->links->invoice->href ?? null;
    }
}

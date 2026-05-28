<?php

declare(strict_types=1);

namespace Vatly\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Vatly\Fluent\Contracts\OrderInterface;
use Vatly\Fluent\Vatly;

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

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /**
     * Get the hosted invoice URL for this order.
     *
     * Cashier-style convenience: lets consumers iterate the orders relation
     * and call `invoiceUrl()` on each model directly. Internally delegates
     * to the framework-agnostic {@see \Vatly\Fluent\Order::invoiceUrl()}.
     */
    public function invoiceUrl(): ?string
    {
        return app(Vatly::class)->order($this)->invoiceUrl();
    }
}

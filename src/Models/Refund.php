<?php

declare(strict_types=1);

namespace Vatly\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Vatly\Fluent\Contracts\RefundInterface;

/**
 * @property string $vatly_id
 * @property string|null $owner_type
 * @property int|null $owner_id
 * @property string|null $customer_id The Vatly customer id (cus_…).
 * @property string $original_order_id The Vatly id of the order the refund was issued against.
 * @property string $status
 * @property int $total
 * @property int|null $subtotal
 * @property array<int, array{rate: array{name: string, percentage: float, taxablePercentage: float}, amount: int, currency: string}>|null $tax_summary
 * @property string $currency
 *
 * @method static create(array<string, mixed> $array)
 * @method static where(string $column, mixed $value)
 */
class Refund extends Model implements RefundInterface
{
    protected $table = 'vatly_refunds';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'tax_summary' => 'array',
    ];

    /**
     * @return MorphTo<Model, Refund>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo('owner');
    }

    // RefundInterface implementation

    public function getVatlyId(): string
    {
        return $this->vatly_id;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getTotal(): int
    {
        return (int) $this->total;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getOriginalOrderId(): string
    {
        return $this->original_order_id;
    }

    public function isCompleted(): bool
    {
        return $this->status === 'refunded';
    }
}

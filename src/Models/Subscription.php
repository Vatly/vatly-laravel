<?php

declare(strict_types=1);

namespace Vatly\Laravel\Models;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Vatly\Fluent\Contracts\BillableInterface;
use Vatly\Fluent\Contracts\SubscriptionInterface;

/**
 * The local representation of a Vatly subscription.
 *
 * State-only — operations (swap, cancel, sync, updateBilling)
 * live on Vatly\Fluent\SubscriptionHandle and are reached via
 * $user->subscription('default').
 *
 * @property BillableInterface $owner
 * @property string $type
 * @property string $plan_id
 * @property string $vatly_id
 * @property string $name
 * @property int $quantity
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $ends_at
 *
 * @method static create(array<string, mixed> $array)
 * @method static where(string $column, mixed $value)
 */
class Subscription extends Model implements SubscriptionInterface
{
    public const DEFAULT_TYPE = 'default';

    protected $table = 'vatly_subscriptions';

    protected $guarded = [];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /**
     * @return MorphTo<Model, Subscription>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo('owner');
    }

    // --- SubscriptionInterface implementation ---

    public function getVatlyId(): string
    {
        return $this->vatly_id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getPlanId(): string
    {
        return $this->plan_id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getEndsAt(): ?DateTimeInterface
    {
        return $this->ends_at;
    }

    public function getOwner(): BillableInterface
    {
        return $this->owner;
    }

    public function isCancelled(): bool
    {
        return $this->ends_at !== null;
    }

    public function isOnGracePeriod(): bool
    {
        return $this->isCancelled() && $this->ends_at?->isFuture();
    }

    public function isActive(): bool
    {
        return ! $this->isCancelled() || $this->isOnGracePeriod();
    }
}

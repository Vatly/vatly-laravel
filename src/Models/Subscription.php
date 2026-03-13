<?php

declare(strict_types=1);

namespace Vatly\Laravel\Models;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Vatly\Fluent\Actions\CancelSubscription;
use Vatly\Fluent\Actions\SwapSubscriptionPlan;
use Vatly\Fluent\Contracts\BillableInterface;
use Vatly\Fluent\Contracts\SubscriptionInterface;
use Vatly\Fluent\Exceptions\FeatureUnavailableException;

/**
 * @property BillableInterface $owner
 * @property string $type
 * @property string $plan_id
 * @property string $vatly_id
 * @property string $name
 * @property int $quantity
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

    // SubscriptionInterface implementation

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
        return !$this->isCancelled() || $this->isOnGracePeriod();
    }

    // Legacy method aliases for backward compatibility

    public function cancelled(): bool
    {
        return $this->isCancelled();
    }

    public function onGracePeriod(): bool
    {
        return $this->isOnGracePeriod();
    }

    public function active(): bool
    {
        return $this->isActive();
    }

    // Business logic

    /**
     * @param array<string, mixed> $options
     */
    public function swap(string $type, string $planId, array $options = []): self
    {
        /** @var SwapSubscriptionPlan $action */
        $action = app()->make(SwapSubscriptionPlan::class);
        $response = $action->execute($this->vatly_id, $planId, $options);

        $this->type = $type;
        $this->plan_id = $response->subscriptionPlanId;
        $this->quantity = $response->quantity;
        $this->save();

        return $this;
    }

    /**
     * Swap to a new plan and invoice immediately.
     *
     * This applies the plan change immediately and creates an invoice
     * for any prorated charges right away.
     *
     * @param array<string, mixed> $options
     */
    public function swapAndInvoice(string $type, string $planId, array $options = []): self
    {
        // Force immediate application and invoicing
        $options['applyImmediately'] = true;
        $options['invoiceImmediately'] = true;

        return $this->swap($type, $planId, $options);
    }

    public function resume(): self
    {
        throw FeatureUnavailableException::notImplementedOnApi();
    }

    /**
     * Get the URL where the customer can update their payment method.
     *
     * @param array<string, mixed> $prefillData
     */
    public function updatePaymentMethodUrl(array $prefillData = []): string
    {
        /** @var \Vatly\Fluent\Actions\GetPaymentMethodUpdateUrl $action */
        $action = app()->make(\Vatly\Fluent\Actions\GetPaymentMethodUpdateUrl::class);
        $response = $action->execute($this->vatly_id, $prefillData);

        return $response->href;
    }

    /**
     * Sync the local subscription with the current state at Vatly.
     *
     * Fetches fresh data from the Vatly API and updates the local model.
     */
    public function sync(): self
    {
        /** @var \Vatly\Fluent\Actions\GetSubscription $action */
        $action = app()->make(\Vatly\Fluent\Actions\GetSubscription::class);
        $response = $action->execute($this->vatly_id);

        $updates = [
            'plan_id' => $response->subscriptionPlanId,
            'name' => $response->name,
            'quantity' => $response->quantity,
        ];

        // Determine ends_at from API response
        if ($response->endedAt !== null) {
            $updates['ends_at'] = Carbon::parse($response->endedAt);
        } elseif ($response->cancelledAt !== null) {
            // Subscription is cancelled but may still be in grace period
            // The actual end date would be set via webhook, but we can note it's cancelled
            $updates['ends_at'] = $this->ends_at ?? Carbon::parse($response->cancelledAt);
        }

        // Sync trial end date if present
        if ($response->trialUntil !== null) {
            $updates['trial_ends_at'] = Carbon::parse($response->trialUntil);
        }

        foreach ($updates as $key => $value) {
            $this->{$key} = $value;
        }
        $this->save();

        return $this;
    }

    /**
     * Cancel the subscription at Vatly.
     */
    public function cancel(): void
    {
        app()->make(CancelSubscription::class)->execute($this->vatly_id);
    }
}

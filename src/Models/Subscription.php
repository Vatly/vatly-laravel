<?php

declare(strict_types=1);

namespace Vatly\Laravel\Models;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Vatly\Fluent\Concerns\DerivesSubscriptionState;
use Vatly\Fluent\Contracts\SubscriptionInterface;
use Vatly\Fluent\SubscriptionHandle;
use Vatly\Fluent\Vatly;

/**
 * The local representation of a Vatly subscription.
 *
 * State accessors satisfy {@see SubscriptionInterface}. The derived
 * predicates (isActive/isCancelled/isOnGracePeriod/isValid/isRecurring/
 * isEnded) come from the {@see DerivesSubscriptionState} trait. Operation
 * methods (cancel/cancelNow/swap/updateBilling/resume) delegate to a
 * fresh {@see SubscriptionHandle} so this consumer code works:
 *
 *     $user->subscription('default')->cancel();   // via Vatly\Fluent\SubscriptionHandle
 *     $user->subscriptions->first()->cancel();    // via this model
 *
 * @property string $type
 * @property string $plan_id
 * @property string $vatly_id
 * @property string $name
 * @property int $quantity
 * @property string|null $customer_id The Vatly customer id (cus_…), populated even for anonymous flows.
 * @property string|null $mandate_method Normalized payment method category (card, sepa_debit, paypal, bacs_debit).
 * @property string|null $mandate_masked_identifier Customer-facing identifier — card last 4, masked IBAN, etc.
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $ends_at
 *
 * @method static create(array<string, mixed> $array)
 * @method static where(string $column, mixed $value)
 */
class Subscription extends Model implements SubscriptionInterface
{
    use DerivesSubscriptionState;

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

    // --- SubscriptionInterface state accessors ---

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

    public function getMandateMethod(): ?string
    {
        return $this->mandate_method;
    }

    public function getMandateMaskedIdentifier(): ?string
    {
        return $this->mandate_masked_identifier;
    }

    // --- Predicate aliases (active / canceled / onGracePeriod / valid / recurring / ended) ---

    public function active(): bool
    {
        return $this->isActive();
    }

    public function canceled(): bool
    {
        return $this->isCancelled();
    }

    public function onGracePeriod(): bool
    {
        return $this->isOnGracePeriod();
    }

    public function valid(): bool
    {
        return $this->isValid();
    }

    public function recurring(): bool
    {
        return $this->isRecurring();
    }

    public function ended(): bool
    {
        return $this->isEnded();
    }

    // --- Operation methods (delegate to Vatly\Fluent\SubscriptionHandle) ---

    /**
     * Cancel the subscription at Vatly.
     */
    public function cancel(): void
    {
        $this->handle()->cancel();
    }

    /**
     * Resume a subscription currently in its grace period.
     */
    public function resume(): self
    {
        $this->handle()->resume();

        return $this;
    }

    /**
     * Swap to a different plan.
     *
     * @param  array<string, mixed>  $options
     */
    public function swap(string $planId, array $options = []): self
    {
        $this->handle()->swap($planId, $options);

        return $this;
    }

    /**
     * Swap to a different plan and invoice immediately.
     *
     * @param  array<string, mixed>  $options
     */
    public function swapAndInvoice(string $planId, array $options = []): self
    {
        $this->handle()->swapAndInvoice($planId, $options);

        return $this;
    }

    /**
     * Create a signed URL where the customer can update billing details.
     *
     * @param  array<string, mixed>  $prefillData
     */
    public function updateBilling(array $prefillData = []): string
    {
        return $this->handle()->updateBilling($prefillData);
    }

    /**
     * Refresh this local record from Vatly.
     */
    public function sync(): self
    {
        $this->handle()->sync();

        return $this;
    }

    /**
     * Build a fresh fluent Subscription wrapping this model.
     */
    private function handle(): SubscriptionHandle
    {
        return app(Vatly::class)->subscription($this);
    }
}

<?php

declare(strict_types=1);

namespace Vatly\Laravel;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Vatly\API\Resources\Customer;
use Vatly\Fluent\Builders\CheckoutBuilder;
use Vatly\Fluent\Builders\SubscriptionBuilder;
use Vatly\Fluent\CustomerProfile;
use Vatly\Fluent\Subscription as FluentSubscription;
use Vatly\Fluent\Vatly;
use Vatly\Laravel\Exceptions\NoVatlyCustomer;
use Vatly\Laravel\Models\Order;
use Vatly\Laravel\Models\Subscription;

/**
 * Vatly billing capability for an Eloquent model.
 *
 * Apply on your User/Tenant model. The trait exposes Cashier-style methods
 * (`subscribe`, `subscription`, `checkout`, `createAsVatlyCustomer`, …) that
 * compose fluent's framework-agnostic surface with Eloquent queries.
 *
 * @property string|null $vatly_id
 * @property string|null $email
 * @property string|null $name
 *
 * @method static where(string $column, mixed $value)
 * @method mixed getKey()
 * @method string getMorphClass()
 */
trait Billable
{
    // --- Vatly identity / profile accessors ---

    public function vatlyId(): ?string
    {
        return $this->vatly_id;
    }

    public function hasVatlyId(): bool
    {
        return $this->vatly_id !== null;
    }

    public function vatlyEmail(): ?string
    {
        return $this->email ?? null;
    }

    public function vatlyName(): ?string
    {
        return $this->name ?? null;
    }

    /**
     * Snapshot the host-side fields fluent uses when talking to the API.
     */
    public function customerProfile(): CustomerProfile
    {
        return new CustomerProfile(
            vatlyId: $this->vatlyId(),
            email:   $this->vatlyEmail(),
            name:    $this->vatlyName(),
        );
    }

    // --- Eloquent relations (Laravel-specific) ---

    /**
     * @return MorphMany<Subscription>
     */
    public function subscriptions(): MorphMany
    {
        return $this->morphMany(Subscription::class, 'owner')->orderByDesc('created_at');
    }

    /**
     * @return MorphMany<Order>
     */
    public function orders(): MorphMany
    {
        return $this->morphMany(Order::class, 'owner')->orderByDesc('created_at');
    }

    // --- Cashier-style subscription accessors ---

    public function subscribe(): SubscriptionBuilder
    {
        return app(Vatly::class)->subscriptionBuilder($this->customerProfile());
    }

    public function subscribed(string $type = Subscription::DEFAULT_TYPE): bool
    {
        $subscription = $this->subscriptions()
            ->where('type', $type)
            ->first();

        return $subscription !== null && $subscription->isActive();
    }

    public function subscription(string $type = Subscription::DEFAULT_TYPE): ?FluentSubscription
    {
        $local = $this->subscriptions()
            ->where('type', $type)
            ->first();

        return $local !== null ? app(Vatly::class)->subscription($local) : null;
    }

    public function checkout(): CheckoutBuilder
    {
        return app(Vatly::class)->checkoutBuilder($this->customerProfile());
    }

    public function order(string $vatlyId): \Vatly\Fluent\Order
    {
        $local = $this->orders()
            ->where('vatly_id', $vatlyId)
            ->firstOrFail();

        return app(Vatly::class)->order($local);
    }

    // --- Customer-creation shortcuts ---

    /**
     * Create the Vatly customer for this owner and bind the resulting id.
     *
     * The host id is written via the configured CustomerBindingRepository
     * (which for the default Eloquent impl updates the `vatly_id` column
     * directly). The in-memory model is also refreshed so subsequent calls
     * see the new id without an extra query.
     *
     * @param array<string, mixed> $options Extra payload keys forwarded to the API
     *                                      (e.g. {'email' => '...'}).
     */
    public function createAsVatlyCustomer(array $options = []): Customer
    {
        $profile = new CustomerProfile(
            email: $options['email'] ?? $this->vatlyEmail(),
            name:  $options['name']  ?? $this->vatlyName(),
        );

        $customer = app(Vatly::class)
            ->customers()
            ->createFor((string) $this->getKey(), $profile);

        $this->vatly_id = $customer->id;

        return $customer;
    }

    public function asVatlyCustomer(): Customer
    {
        if (! $this->hasVatlyId()) {
            throw NoVatlyCustomer::notYetCreated($this);
        }

        return app(Vatly::class)->customers()->findByVatlyId((string) $this->vatlyId());
    }

    /**
     * @param array<string, mixed> $options
     */
    public function createOrGetVatlyCustomer(array $options = []): Customer
    {
        if ($this->hasVatlyId()) {
            return $this->asVatlyCustomer();
        }

        return $this->createAsVatlyCustomer($options);
    }

    // --- Static finders ---

    public static function findBillable(string $vatlyId): ?static
    {
        return static::where('vatly_id', $vatlyId)->first();
    }

    public static function findBillableOrFail(string $vatlyId): static
    {
        return static::where('vatly_id', $vatlyId)->firstOrFail();
    }
}

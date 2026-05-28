<?php

declare(strict_types=1);

namespace Vatly\Laravel;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Vatly\API\Resources\Customer;
use Vatly\Fluent\Builders\CheckoutBuilder;
use Vatly\Fluent\Builders\SubscriptionBuilder;
use Vatly\Fluent\CustomerProfile;
use Vatly\Fluent\Exceptions\InvalidOrderException;
use Vatly\Fluent\OrderHandle;
use Vatly\Fluent\SubscriptionHandle;
use Vatly\Fluent\Vatly;
use Vatly\Laravel\Exceptions\NoVatlyCustomerException;
use Vatly\Laravel\Models\Order;
use Vatly\Laravel\Models\Subscription;

/**
 * Vatly billing capability for an Eloquent model.
 *
 * Apply on your User/Tenant model. The trait exposes `subscribe`,
 * `subscription`, `checkout`, `createAsVatlyCustomer`, … — composing
 * fluent's framework-agnostic surface with Eloquent queries.
 *
 * @property string|null $vatly_id
 * @property string|null $email
 * @property string|null $name
 *
 * @method static where(string $column, mixed $value)
 * @method bool save()
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
            email: $this->vatlyEmail(),
            name: $this->vatlyName(),
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

    // --- Subscription accessors ---

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

    public function subscription(string $type = Subscription::DEFAULT_TYPE): ?SubscriptionHandle
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

    /**
     * @throws InvalidOrderException When no order with the given Vatly id exists for this owner.
     */
    public function order(string $vatlyId): OrderHandle
    {
        $local = $this->orders()
            ->where('vatly_id', $vatlyId)
            ->first();

        if ($local === null) {
            throw InvalidOrderException::notFound($vatlyId);
        }

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
     * `$options` keys not consumed by `email` / `name` (the host-supplied
     * defaults the trait fills in from this model) are forwarded as-is to
     * the create-customer API call — `locale`, `metadata`, etc.
     *
     * @param  array<string, mixed>  $options  Create-customer API payload keys.
     *                                         Caller-supplied `email` / `name`
     *                                         override the host defaults.
     */
    public function createAsVatlyCustomer(array $options = []): Customer
    {
        $profile = new CustomerProfile(
            email: $options['email'] ?? $this->vatlyEmail(),
            name: $options['name'] ?? $this->vatlyName(),
        );

        unset($options['email'], $options['name']);

        $customer = app(Vatly::class)
            ->customers()
            ->createFor((string) $this->getKey(), $profile, $options);

        $this->vatly_id = $customer->id;

        return $customer;
    }

    public function asVatlyCustomer(): Customer
    {
        if (! $this->hasVatlyId()) {
            throw NoVatlyCustomerException::notYetCreated($this);
        }

        return app(Vatly::class)->customers()->findByVatlyCustomerId((string) $this->vatlyId());
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function createOrGetVatlyCustomer(array $options = []): Customer
    {
        if ($this->hasVatlyId()) {
            return $this->asVatlyCustomer();
        }

        return $this->createAsVatlyCustomer($options);
    }

    /**
     * Claim an anonymous Vatly customer for this host entity.
     *
     * Use this on a user-signup hook when the user paid via guest checkout
     * before the account existed. Pass the Vatly customer id (typically
     * stashed on the checkout-success redirect or in the session) and this
     * method:
     *
     *   1. binds the Vatly id ↔ host id pair (via the CustomerBindingRepository),
     *   2. writes `vatly_id` onto this model so subsequent lookups find it,
     *   3. backfills `owner_type` / `owner_id` on every `vatly_subscriptions`
     *      and `vatly_orders` row that carries this `customer_id` but had no
     *      owner yet — the rows the webhook persisted during the anonymous
     *      flow.
     *
     * Returns the number of subscription + order rows that were re-attributed.
     */
    public function claimVatlyCustomer(string $vatlyCustomerId): int
    {
        app(Vatly::class)
            ->customers()
            ->attribute($vatlyCustomerId, (string) $this->getKey());

        $this->vatly_id = $vatlyCustomerId;
        $this->save();

        $ownerAttrs = [
            'owner_type' => $this->getMorphClass(),
            'owner_id' => $this->getKey(),
        ];

        $subscriptions = Subscription::query()
            ->whereNull('owner_id')
            ->where('customer_id', $vatlyCustomerId)
            ->update($ownerAttrs);

        $orders = Order::query()
            ->whereNull('owner_id')
            ->where('customer_id', $vatlyCustomerId)
            ->update($ownerAttrs);

        return $subscriptions + $orders;
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

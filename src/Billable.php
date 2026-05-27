<?php

declare(strict_types=1);

namespace Vatly\Laravel;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Vatly\API\Resources\Customer;
use Vatly\Fluent\Billable as FluentBillable;
use Vatly\Fluent\Builders\CheckoutBuilder;
use Vatly\Fluent\Builders\SubscriptionBuilder;
use Vatly\Fluent\OrderHandle;
use Vatly\Fluent\SubscriptionHandle;
use Vatly\Fluent\Vatly;
use Vatly\Laravel\Models\Order;
use Vatly\Laravel\Models\Subscription;

/**
 * Vatly billing capability for an Eloquent model.
 *
 * Apply on your User/Tenant model (also implementing
 * Vatly\Fluent\Contracts\BillableInterface). Methods are Cashier-style
 * shortcuts that proxy to a Vatly\Fluent\Billable orchestrator — drivers
 * in other frameworks expose the same surface through their own accessor.
 *
 * @property string|null $vatly_id
 * @property string|null $email
 * @property string|null $name
 *
 * @method static where(string $column, mixed $value)
 * @method bool saveQuietly()
 * @method mixed getKey()
 * @method string getMorphClass()
 */
trait Billable
{
    /**
     * Access the framework-agnostic Vatly orchestrator for this owner.
     *
     * Use this directly when you want to be explicit about the namespace,
     * or for operations not exposed as shortcut methods on this trait.
     */
    public function vatlyBillable(): FluentBillable
    {
        return app(Vatly::class)->billable($this);
    }

    // --- BillableInterface implementation (reads Eloquent columns) ---

    public function getVatlyId(): ?string
    {
        return $this->vatly_id;
    }

    public function setVatlyId(string $id): void
    {
        $this->vatly_id = $id;
    }

    public function hasVatlyId(): bool
    {
        return $this->vatly_id !== null;
    }

    public function getVatlyEmail(): ?string
    {
        return $this->email ?? null;
    }

    public function getVatlyName(): ?string
    {
        return $this->name ?? null;
    }

    // --- Cashier-shape aliases (bare-verb readability) ---

    public function vatlyId(): ?string
    {
        return $this->getVatlyId();
    }

    public function vatlyEmail(): ?string
    {
        return $this->getVatlyEmail();
    }

    public function vatlyName(): ?string
    {
        return $this->getVatlyName();
    }

    // --- Eloquent relations (Laravel-specific; can't move to fluent) ---

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

    // --- Cashier-style shortcuts (proxy to the orchestrator) ---

    public function subscribe(): SubscriptionBuilder
    {
        return $this->vatlyBillable()->subscribe();
    }

    public function subscribed(string $type = Subscription::DEFAULT_TYPE): bool
    {
        return $this->vatlyBillable()->subscribed($type);
    }

    public function subscription(string $type = Subscription::DEFAULT_TYPE): ?SubscriptionHandle
    {
        return $this->vatlyBillable()->subscription($type);
    }

    public function checkout(): CheckoutBuilder
    {
        return $this->vatlyBillable()->checkout();
    }

    /**
     * Build an OrderHandle for one of this owner's orders.
     */
    public function order(string $vatlyId): OrderHandle
    {
        return $this->vatlyBillable()->order($vatlyId);
    }

    // --- Customer shortcuts ---

    /**
     * @param array<string, mixed> $options
     */
    public function createAsVatlyCustomer(array $options = []): Customer
    {
        return $this->vatlyBillable()->createAsVatlyCustomer($options);
    }

    public function asVatlyCustomer(): Customer
    {
        return $this->vatlyBillable()->asVatlyCustomer();
    }

    /**
     * @param array<string, mixed> $options
     */
    public function createOrGetVatlyCustomer(array $options = []): Customer
    {
        return $this->vatlyBillable()->createOrGetVatlyCustomer($options);
    }

    // --- Static finders (Laravel-specific; Cashier-aligned names) ---

    public static function findBillable(string $vatlyId): ?static
    {
        return static::where('vatly_id', $vatlyId)->first();
    }

    public static function findBillableOrFail(string $vatlyId): static
    {
        return static::where('vatly_id', $vatlyId)->firstOrFail();
    }
}

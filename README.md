# Vatly Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/vatly/vatly-laravel.svg?style=flat-square)](https://packagist.org/packages/vatly/vatly-laravel)
[![Tests](https://github.com/Vatly/vatly-laravel/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/Vatly/vatly-laravel/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/vatly/vatly-laravel.svg?style=flat-square)](https://packagist.org/packages/vatly/vatly-laravel)

> **Alpha — under active development. Expect breaking changes between minor versions.**

A Cashier-style integration for [Vatly](https://vatly.com) in your Laravel application. Drop a `Billable` trait on your User model and you get subscriptions, checkouts, customer management, hosted billing update links, and a fully wired webhook endpoint — built around Eloquent and Laravel's IoC, events, and routing.

If you've used Laravel Cashier for Stripe, this will feel familiar. Vatly handles Merchant of Record billing for EU SaaS, so you get a similar developer experience without managing VAT, invoicing, or payment compliance yourself.

## Documentation

Full docs at [docs.vatly.com](https://docs.vatly.com). In this repo:

- [Getting started](docs/README.md)
- [Configuration](docs/configuration.md)
- [Customers](docs/Customers.md)
- [Checkouts](docs/Checkouts.md)
- [Subscriptions](docs/Subscriptions.md)
- [Orders](docs/Orders.md)
- [Webhooks](docs/Webhooks.md)

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- A Vatly API key ([vatly.com](https://vatly.com))

## Installation

```bash
composer require vatly/vatly-laravel:v0.7.0-alpha.1
```

Pin to an exact version during alpha — the API will change.

## Setup

1. **Publish the config:**

   ```bash
   php artisan vendor:publish --tag=vatly-config
   ```

2. **Add credentials to `.env`:**

   ```env
   VATLY_KEY=test_xxxxxxxxxxxx
   VATLY_WEBHOOK_SECRET=your-webhook-secret
   VATLY_REDIRECT_URL_SUCCESS=https://your-app.test/checkout/success
   VATLY_REDIRECT_URL_CANCELED=https://your-app.test/checkout/canceled
   ```

   See [docs/configuration.md](docs/configuration.md) for the full list. Testmode is inferred from the key prefix (`test_` vs `live_`) — no extra config needed.

3. **Publish and run migrations:**

   ```bash
   php artisan vendor:publish --tag=vatly-migrations
   php artisan vendor:publish --tag=vatly-billable-migrations
   php artisan migrate
   ```

   This adds a `vatly_id` column to your users table plus `vatly_subscriptions`, `vatly_orders`, `vatly_refunds`, and `vatly_webhook_calls` tables.

4. **Add the `Billable` trait to your User model:**

   ```php
   use Vatly\Laravel\Billable;

   class User extends Authenticatable
   {
       use Billable;
   }
   ```

## Usage

```php
// Start a subscription checkout
$checkout = $user->subscribe()
    ->toPlan('plan_premium')
    ->create();

return redirect($checkout->links->checkoutUrl->href);

// One-off checkouts with explicit items
$checkout = $user->checkout()->create(
    items: [['id' => 'plan_premium', 'quantity' => 1]],
    redirectUrlSuccess: 'https://example.com/success',
    redirectUrlCanceled: 'https://example.com/canceled',
);

// Guest checkout: put {CHECKOUT_ID} in the return URL — Vatly fills it in,
// and claimVatlyCustomerFromReturn() links the purchase on the way back.
$user->checkout()->create(
    items: [['id' => 'plan_premium', 'quantity' => 1]],
    redirectUrlSuccess: route('vatly.return').'?checkout_id={CHECKOUT_ID}',
    redirectUrlCanceled: 'https://example.com/billing',
);
// …on the return route (multi-tab safe; no session/cookie plumbing):
$request->user()->claimVatlyCustomerFromReturn($request);   // see docs/Customers.md

// Subscription state — Cashier-shape predicates
$user->subscribed();                                    // bool, default type
$user->subscribed('team');                              // bool, custom type
$user->subscription()->active();
$user->subscription()->onGracePeriod();
$user->subscription()->canceled();
$user->subscription()->valid();
$user->subscription()->ended();

// Subscription operations
$user->subscription()->swap('plan_premium');
$user->subscription()->cancel();                        // Vatly decides immediate vs grace
$user->subscription()->resume();                        // while in grace period
$user->subscription()->updateBilling();                 // signed link for hosted update flow

// Orders — Cashier-style iteration works on the Eloquent collection too
foreach ($user->orders as $order) {
    echo $order->invoiceUrl();                          // hosted invoice URL
}

// Or explicit lookup
$invoiceUrl = $user->order('order_abc')->invoiceUrl();

// Static finders
$user = User::findBillable('customer_xyz');             // ?User
$user = User::findBillableOrFail('customer_xyz');       // User
```

See [docs/Subscriptions.md](docs/Subscriptions.md) and [docs/Checkouts.md](docs/Checkouts.md) for the full surface.

## Webhooks

The package registers `POST /webhooks/vatly` automatically. Set this URL and your `VATLY_WEBHOOK_SECRET` in the Vatly dashboard, and subscriptions/orders sync to your database automatically.

Vatly events are dispatched on Laravel's event bus — register listeners the usual way:

```php
use Vatly\Fluent\Events\OrderPaid;

Event::listen(OrderPaid::class, function (OrderPaid $event) {
    // send receipt, etc.
});
```

Events available:

- `Vatly\Fluent\Events\OrderPaid` — carries `total`, `subtotal`, `taxSummary` (full per-rate breakdown), `currency`, `invoiceNumber`, `paymentMethod`. Materialize local invoices without an extra API call.
- `Vatly\Fluent\Events\OrderCanceled` — the local order's status is mirrored to `canceled`.
- `Vatly\Fluent\Events\OrderChargebackReceived` / `OrderChargebackReversed` — dispute signals carrying the affected `orderId` (no local row is mutated; react to suspend/reinstate access).
- `Vatly\Fluent\Events\PaymentFailed` — same enriched order shape as `OrderPaid`; typically the start of dunning.
- `Vatly\Fluent\Events\RefundCompleted` / `RefundFailed` / `RefundCanceled` — each with full `taxSummary`; persisted to `vatly_refunds` (see below).
- `Vatly\Fluent\Events\SubscriptionStarted`
- `Vatly\Fluent\Events\SubscriptionBillingUpdated` — the stored mandate (`mandate_method` / `mandate_masked_identifier`) is refreshed.
- `Vatly\Fluent\Events\SubscriptionResumed` — the stored end date is cleared.
- `Vatly\Fluent\Events\SubscriptionCanceledImmediately`
- `Vatly\Fluent\Events\SubscriptionCanceledWithGracePeriod`
- `Vatly\Fluent\Events\LocalSubscriptionCreated`
- `Vatly\Fluent\Events\UnsupportedWebhookReceived`

Refund webhooks (`refund.completed` / `refund.failed` / `refund.canceled`) are persisted to the `vatly_refunds` table via the bundled `Refund` model and `EloquentRefundRepository`. Chargeback events ship no built-in persistence — Vatly's public order status doesn't change on a chargeback, so wire your own listener if you need to suspend/reinstate access.

The webhook route is named `vatly.webhook` — reach it with `route('vatly.webhook')`.

See [docs/Webhooks.md](docs/Webhooks.md) for signature verification, retries, and customising reactions.

## Testing

```bash
composer test
```

For the `order.paid` webhook flow, the package fetches the full Order from the Vatly API to populate the tax breakdown. The actions are encapsulated by the `Vatly` composition root (not individually bound in the container), so swap one via reflection on the singleton:

```php
use Mockery;
use ReflectionClass;
use Vatly\Fluent\Actions\GetOrder;
use Vatly\Fluent\Vatly;
use Vatly\Fluent\Webhooks\WebhookProcessor;

$action = Mockery::mock(GetOrder::class);
$action->shouldReceive('execute')->andReturn($yourFakeApiOrder);

$vatly = $this->app->make(Vatly::class);
$ref = (new ReflectionClass($vatly))->getProperty('getOrder');
$ref->setAccessible(true);
$ref->setValue($vatly, $action);

// Clear downstream caches that captured the previous action
foreach (['webhookEventFactory', 'webhookProcessor'] as $prop) {
    $r = (new ReflectionClass($vatly))->getProperty($prop);
    $r->setAccessible(true);
    $r->setValue($vatly, null);
}

$this->app->forgetInstance(WebhookProcessor::class);
```

See [`tests/Http/Controllers/VatlyInboundWebhookControllerTest.php`](tests/Http/Controllers/VatlyInboundWebhookControllerTest.php) for the helper used in this package's own tests.

## Under the hood

This package is a thin Laravel driver on top of [`vatly/vatly-fluent-php`](https://github.com/Vatly/vatly-fluent-php), which holds the contracts, composition root (`Vatly`), webhook pipeline, domain events, and the framework-agnostic operation wrappers (`Vatly\Fluent\SubscriptionHandle`, `Vatly\Fluent\OrderHandle`). The Laravel side supplies:

- Concrete Eloquent-backed impls of fluent's contracts (subscription / order / webhook-call repositories, customer-binding repository, models, config reader, event dispatcher)
- The `Billable` trait with Cashier-style shortcuts and static finders
- The HTTP route and controller for inbound webhooks
- Publishable migrations and configuration

The driver bindings live in `VatlyServiceProvider`: each fluent contract is bound to its Eloquent / Laravel impl, then `Vatly::class` is registered as a singleton built from a `Vatly\Fluent\Wiring` DTO. The new `CustomerBindingRepository` contract replaces the old `CustomerRepositoryInterface` — fluent never touches the host model directly; it only consults the binding repo for host-id ↔ vatly-id lookups. Every other fluent service (`Customers` helper, `WebhookProcessor`, actions, operation wrappers) resolves lazily through the singleton.

## License

MIT

# Vatly Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/vatly/vatly-laravel.svg?style=flat-square)](https://packagist.org/packages/vatly/vatly-laravel)
[![Tests](https://github.com/Vatly/vatly-laravel/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/Vatly/vatly-laravel/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/vatly/vatly-laravel.svg?style=flat-square)](https://packagist.org/packages/vatly/vatly-laravel)

> **Alpha release -- under active development. Expect breaking changes.**

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

- PHP 8.2+
- Laravel 11 or 12
- A Vatly API key ([vatly.com](https://vatly.com))

## Installation

```bash
composer require vatly/vatly-laravel:v0.3.0-alpha.1
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

   This adds a `vatly_id` column to your users table plus `vatly_subscriptions`, `vatly_orders`, and `vatly_webhook_calls` tables.

4. **Add the `Billable` trait to your User model:**

   ```php
   use Vatly\Fluent\Contracts\BillableInterface;
   use Vatly\Laravel\Billable;

   class User extends Authenticatable implements BillableInterface
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

// Or one-off checkouts with explicit items
$checkout = $user->checkout()->create(
    items: [['id' => 'plan_premium', 'quantity' => 1]],
    redirectUrlSuccess: 'https://example.com/success',
    redirectUrlCanceled: 'https://example.com/canceled',
);

// Subscription state
$user->subscribed();                          // bool, default type
$user->subscribed('team');                    // bool, custom type
$user->subscription()->active();
$user->subscription()->onGracePeriod();
$user->subscription()->cancelled();

// Swap plan
$user->subscription()->swap('default', 'plan_premium');

// Cancel at period end (Vatly decides immediate vs grace period)
$user->subscription()->cancel();
```

`$user->subscription()` returns a `Vatly\Fluent\SubscriptionHandle` — a thin wrapper around the local `Subscription` Eloquent model with the API-driven operations on it. Reach the underlying model via `$user->subscription()->model()` or query directly with `$user->subscriptions()->where(...)`.

For more explicit/namespaced access, `$user->vatlyBillable()` returns the framework-agnostic orchestrator: `$user->vatlyBillable()->subscribed('default')`, `$user->vatlyBillable()->createAsVatlyCustomer()`, etc.

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

- `Vatly\Fluent\Events\WebhookReceived`
- `Vatly\Fluent\Events\OrderPaid`
- `Vatly\Fluent\Events\SubscriptionStarted`
- `Vatly\Fluent\Events\SubscriptionCanceledImmediately`
- `Vatly\Fluent\Events\SubscriptionCanceledWithGracePeriod`
- `Vatly\Fluent\Events\LocalSubscriptionCreated`
- `Vatly\Fluent\Events\UnsupportedWebhookReceived`

See [docs/Webhooks.md](docs/Webhooks.md) for signature verification, retries, and customising reactions.

## Testing

```bash
composer test
```

## Under the hood

This package is the Laravel driver for [`vatly/vatly-fluent-php`](https://github.com/Vatly/vatly-fluent-php), which holds the framework-agnostic webhook pipeline and contracts shared across drivers. You don't need to interact with fluent directly — it's an implementation detail. If you're building an integration for a different framework, see fluent's [Driver Author Guide](https://github.com/Vatly/vatly-fluent-php/blob/main/CONTRIBUTING.md).

## License

MIT

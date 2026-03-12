# Vatly Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/vatly/vatly-laravel.svg?style=flat-square)](https://packagist.org/packages/vatly/vatly-laravel)
[![Tests](https://github.com/Vatly/vatly-laravel/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/Vatly/vatly-laravel/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/vatly/vatly-laravel.svg?style=flat-square)](https://packagist.org/packages/vatly/vatly-laravel)

> **Alpha release -- under active development. Expect breaking changes.**

Laravel integration for [Vatly](https://vatly.com) billing, inspired by Laravel Cashier. Provides Eloquent models, a `Billable` trait, checkout/subscription builders, webhook handling, and event listeners.

Built on top of [vatly/vatly-fluent-php](https://github.com/Vatly/vatly-fluent-php).

## Installation

```bash
composer require vatly/vatly-laravel:v0.3.0-alpha.1
```

Pin to an exact version. This is an alpha release and the API will change.

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12
- A Vatly API key ([vatly.com](https://vatly.com))

## Setup

1. Publish the config:

```bash
php artisan vendor:publish --tag=vatly-config
```

2. Add your API key to `.env`:

```
VATLY_API_KEY=test_xxxxxxxxxxxx
VATLY_WEBHOOK_SECRET=your-webhook-secret
```

3. Publish and run migrations:

```bash
php artisan vendor:publish --tag=vatly-migrations
php artisan vendor:publish --tag=vatly-billable-migrations
php artisan migrate
```

4. Add the `Billable` trait to your User model:

```php
use Vatly\Laravel\Contracts\BillableInterface;
use Vatly\Laravel\Billable;

class User extends Authenticatable implements BillableInterface
{
    use Billable;
}
```

## Usage

```php
// Create a checkout
$checkout = $user->newCheckout()
    ->withItems(collect(['subscription_plan_id']))
    ->create(
        redirectUrlSuccess: 'https://example.com/success',
        redirectUrlCanceled: 'https://example.com/canceled',
    );

// Swap subscription plan
$user->subscription('default')->swap('plan_premium');

// Cancel subscription
$user->subscription('default')->cancel();
```

## Webhooks

The package registers a webhook endpoint at `/vatly/webhook` automatically. Configure your webhook secret in the Vatly dashboard.

Events dispatched:
- `Vatly\Events\WebhookReceived`
- `Vatly\Events\SubscriptionStarted`
- `Vatly\Events\SubscriptionCanceledImmediately`
- `Vatly\Events\SubscriptionCanceledWithGracePeriod`

## Testing

```bash
composer test
```

## License

MIT

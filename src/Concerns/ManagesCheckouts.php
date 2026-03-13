<?php

declare(strict_types=1);

namespace Vatly\Laravel\Concerns;

use Vatly\Laravel\Builders\CheckoutBuilder;
use Vatly\Laravel\Builders\SubscriptionBuilder;
use Vatly\Laravel\VatlyApiActions\CreateVatlyCheckout;
use Vatly\Laravel\VatlyConfig;

trait ManagesCheckouts
{
    public function checkout(): CheckoutBuilder
    {
        return new CheckoutBuilder(
            owner: $this,
            createVatlyCheckout: app()->make(CreateVatlyCheckout::class),
        );
    }

    public function subscribe(): SubscriptionBuilder
    {
        return new SubscriptionBuilder(
            vatlyConfig: app()->make(VatlyConfig::class),
            owner: $this,
            checkoutBuilder: $this->checkout(),
        );
    }
}

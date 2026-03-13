<?php

declare(strict_types=1);

namespace Vatly\Laravel\Builders;

use Illuminate\Database\Eloquent\Model;
use Vatly\Laravel\VatlyApiActions\CreateVatlyCheckoutResponse;
use Vatly\Laravel\VatlyConfig;

class SubscriptionBuilder
{
    protected int $quantity = 1;

    protected string $planId;

    protected string $redirectUrlSuccess;

    protected string $redirectUrlCanceled;

    public function __construct(
        protected readonly VatlyConfig $vatlyConfig,
        protected Model $owner,
        protected CheckoutBuilder $checkoutBuilder,
    ) {
        $this->redirectUrlSuccess = $this->vatlyConfig->getDefaultRedirectUrlSuccess();
        $this->redirectUrlCanceled = $this->vatlyConfig->getDefaultRedirectUrlCanceled();
    }

    public function toPlan(string $planId): self
    {
        $this->planId = $planId;

        return $this;
    }

    public function withRedirectUrlSuccess(string $redirectUrlSuccess): self
    {
        $this->redirectUrlSuccess = $redirectUrlSuccess;

        return $this;
    }

    public function withRedirectUrlCanceled(string $redirectUrlCanceled): self
    {
        $this->redirectUrlCanceled = $redirectUrlCanceled;

        return $this;
    }

    public function withQuantity(int $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    /**
     * @param array<string, mixed> $checkoutOptions
     */
    public function create(array $checkoutOptions = []): CreateVatlyCheckoutResponse
    {
        return $this
            ->checkoutBuilder
            ->create(
                items: collect([$this->getSubscriptionPayload()]),
                redirectUrlSuccess: $this->redirectUrlSuccess,
                redirectUrlCanceled: $this->redirectUrlCanceled,
                payloadOverrides: $checkoutOptions,
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function getSubscriptionPayload(): array
    {
        return [
            'quantity' => $this->quantity,
            'id' => $this->planId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getCreateCheckoutPayload(): array
    {
        return $this->checkoutBuilder->payload();
    }

    public function getCheckoutBuilder(): CheckoutBuilder
    {
        return $this->checkoutBuilder;
    }
}

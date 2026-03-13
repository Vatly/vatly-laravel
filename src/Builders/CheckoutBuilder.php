<?php

declare(strict_types=1);

namespace Vatly\Laravel\Builders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Vatly\Laravel\Exceptions\IncompleteInformationException;
use Vatly\Laravel\VatlyApiActions\CreateVatlyCheckout;
use Vatly\Laravel\VatlyApiActions\CreateVatlyCheckoutResponse;

class CheckoutBuilder
{
    protected string $redirectUrlSuccess;

    protected string $redirectUrlCanceled;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $metadata = null;

    /**
     * @var Collection<int, mixed>
     */
    protected Collection $items;

    public function __construct(
        protected Model $owner,
        protected readonly CreateVatlyCheckout $createVatlyCheckout,
    ) {
        $this->items = new Collection;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function payload(array $overrides = [], bool $filtered = true): array
    {
        $payload = array_merge([
            'products' => $this->items->toArray(),
            'customerId' => $this->owner->vatlyId(),
            'redirectUrlSuccess' => $this->redirectUrlSuccess,
            'redirectUrlCanceled' => $this->redirectUrlCanceled,
            'metadata' => $this->metadata,
        ], $overrides);

        return $filtered ? array_filter($payload) : $payload;
    }

    /**
     * @param Collection<int, mixed> $items
     * @param array<string, mixed> $payloadOverrides
     */
    public function create(
        Collection $items,
        string $redirectUrlSuccess,
        string $redirectUrlCanceled,
        array $payloadOverrides = [],
    ): CreateVatlyCheckoutResponse {
        $this
            ->withItems($items)
            ->withRedirectUrlSuccess($redirectUrlSuccess)
            ->withRedirectUrlCanceled($redirectUrlCanceled);

        $payload = $this->payload(overrides: $payloadOverrides);

        throw_if(
            empty($payload['products']),
            IncompleteInformationException::noCheckoutItems()
        );

        return $this->createVatlyCheckout->execute(
            $payload,
        );
    }

    public function withRedirectUrlSuccess(string $url): self
    {
        $this->redirectUrlSuccess = $url;

        return $this;
    }

    public function withRedirectUrlCanceled(string $url): self
    {
        $this->redirectUrlCanceled = $url;

        return $this;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function withMetadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * @param Collection<int, mixed> $items
     */
    public function withItems(Collection $items): self
    {
        $items->each(fn ($item) => $this->items->add($item));

        return $this;
    }
}

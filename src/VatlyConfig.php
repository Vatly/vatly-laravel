<?php

declare(strict_types=1);

namespace Vatly\Laravel;

use Vatly\Fluent\Contracts\ConfigurationInterface;

class VatlyConfig implements ConfigurationInterface
{
    public function getApiKey(): string
    {
        return config('vatly.api_key', '');
    }

    public function getApiUrl(): string
    {
        return config('vatly.api_url', 'https://api.vatly.com');
    }

    public function getApiVersion(): string
    {
        return config('vatly.api_version', 'v1');
    }

    public function getWebhookSecret(): ?string
    {
        return config('vatly.webhook_secret');
    }

    /**
     * Testmode is inferred from the API key prefix.
     * Keys starting with 'test_' indicate testmode.
     */
    public function isTestmode(): bool
    {
        return str_starts_with($this->getApiKey(), 'test_');
    }

    public function getDefaultRedirectUrlSuccess(): string
    {
        return config('vatly.redirect_url_success') ?? config('app.url') ?? '/';
    }

    public function getDefaultRedirectUrlCanceled(): string
    {
        return config('vatly.redirect_url_canceled') ?? config('app.url') ?? '/';
    }

    public function getBillableModel(): string
    {
        return config('vatly.billable_model', \App\Models\User::class);
    }
}

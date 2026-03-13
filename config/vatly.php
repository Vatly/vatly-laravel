<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Vatly API Key
    |--------------------------------------------------------------------------
    |
    | Your Vatly API key for authenticating API requests.
    |
    */
    'api_key' => env('VATLY_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Vatly API URL
    |--------------------------------------------------------------------------
    |
    | The base URL for the Vatly API.
    |
    */
    'api_url' => env('VATLY_API_URL', 'https://api.vatly.com'),

    /*
    |--------------------------------------------------------------------------
    | Vatly API Version
    |--------------------------------------------------------------------------
    |
    | The API version to use.
    |
    */
    'api_version' => env('VATLY_API_VERSION', 'v1'),

    /*
    |--------------------------------------------------------------------------
    | Webhook Secret
    |--------------------------------------------------------------------------
    |
    | The secret used to verify webhook signatures from Vatly.
    |
    */
    'webhook_secret' => env('VATLY_WEBHOOK_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Billable Model
    |--------------------------------------------------------------------------
    |
    | The model class that represents a billable customer in your application.
    |
    */
    'billable_model' => env('VATLY_BILLABLE_MODEL', \App\Models\User::class),

    /*
    |--------------------------------------------------------------------------
    | Redirect URLs
    |--------------------------------------------------------------------------
    |
    | Default redirect URLs after checkout success/cancel.
    |
    */
    'redirect_url_success' => env('VATLY_REDIRECT_URL_SUCCESS'),
    'redirect_url_canceled' => env('VATLY_REDIRECT_URL_CANCELED'),
];

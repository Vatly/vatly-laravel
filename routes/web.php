<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vatly\Laravel\Http\Controllers\VatlyInboundWebhookController;

Route::post('webhooks/vatly', VatlyInboundWebhookController::class)
    ->name('webhook');

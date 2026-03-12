<?php

declare(strict_types=1);

namespace Vatly\Laravel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Vatly\Fluent\Exceptions\InvalidWebhookSignatureException;
use Vatly\Fluent\Webhooks\WebhookProcessor;

class VatlyInboundWebhookController
{
    public function __construct(
        private readonly WebhookProcessor $processor,
    ) {
        //
    }

    public function __invoke(Request $request): Response
    {
        if (config('app.debug')) {
            Log::info('Vatly Webhook request received!', $request->all());
        }

        try {
            $this->processor->handle(
                payload: (string) $request->getContent(),
                signature: $request->header('X-Vatly-Signature', ''),
            );
        } catch (InvalidWebhookSignatureException) {
            return response('', 403);
        }

        return response('', 201);
    }
}

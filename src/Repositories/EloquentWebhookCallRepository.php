<?php

declare(strict_types=1);

namespace Vatly\Laravel\Repositories;

use DateTimeInterface;
use Vatly\Fluent\Contracts\WebhookCallRepositoryInterface;
use Vatly\Laravel\Models\VatlyWebhookCall;

class EloquentWebhookCallRepository implements WebhookCallRepositoryInterface
{
    /**
     * @param array<string, mixed> $object
     */
    public function record(
        string $id,
        string $resource,
        string $eventName,
        string $entityType,
        string $entityId,
        bool $testmode,
        DateTimeInterface $createdAt,
        array $object,
        ?string $vatlyCustomerId = null,
    ): void {
        VatlyWebhookCall::create([
            'vatly_id' => $id,
            'resource' => $resource,
            'event_name' => $eventName,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'testmode' => $testmode,
            'vatly_created_at' => $createdAt,
            'object' => $object,
            'vatly_customer_id' => $vatlyCustomerId,
        ]);
    }

    public function cleanUp(int $days = 7): int
    {
        return VatlyWebhookCall::cleanUp($days);
    }
}

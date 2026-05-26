<?php

declare(strict_types=1);

namespace Vatly\Laravel\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $vatly_id
 * @property string $resource
 * @property string $event_name
 * @property string $entity_type
 * @property string $entity_id
 * @property ?string $vatly_customer_id
 * @property array<string, mixed> $object
 */
class VatlyWebhookCall extends Model
{
    public const DEFAULT_DAYS_TO_RETAIN = 7;

    protected $table = 'vatly_webhook_calls';

    protected $fillable = [
        'vatly_id',
        'resource',
        'event_name',
        'entity_type',
        'entity_id',
        'vatly_customer_id',
        'object',
    ];

    protected $casts = [
        'object' => 'array',
    ];

    public static function cleanUp(int $daysToRetain = self::DEFAULT_DAYS_TO_RETAIN): int
    {
        return static::where('created_at', '<', Carbon::now()->subDays($daysToRetain))->delete();
    }
}

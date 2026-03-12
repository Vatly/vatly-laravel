<?php

declare(strict_types=1);

namespace Vatly\Laravel\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $event_name
 * @property string $resource_id
 * @property string $resource_name
 * @property string $vatly_customer_id
 * @property array<string, mixed> $object
 * @property bool $testmode
 * @property \Carbon\Carbon $raised_at
 */
class VatlyWebhookCall extends Model
{
    public const int DEFAULT_DAYS_TO_RETAIN = 7;

    protected $table = 'vatly_webhook_calls';

    protected $fillable = [
        'event_name',
        'resource_id',
        'resource_name',
        'vatly_customer_id',
        'object',
        'raised_at',
        'testmode',
    ];

    protected $casts = [
        'object' => 'array',
        'raised_at' => 'datetime',
        'testmode' => 'boolean',
    ];

    public static function cleanUp(int $daysToRetain = self::DEFAULT_DAYS_TO_RETAIN): int
    {
        return static::where('created_at', '<', Carbon::now()->subDays($daysToRetain))->delete();
    }
}

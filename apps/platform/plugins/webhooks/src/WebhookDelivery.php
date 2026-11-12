<?php

declare(strict_types=1);

namespace Fahoba\Webhooks;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasZonedTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * One attempt series for one event — what the admin shows as the last result.
 *
 * @property int $webhook_id
 * @property string $event
 * @property string $status
 * @property int|null $response_code
 * @property string|null $error
 * @property int $attempts
 */
final class WebhookDelivery extends Model
{
    use BelongsToHousehold, HasZonedTimestamps;

    public const DELIVERED = 'delivered';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    /** Per webhook; older ones go once a new one is written. */
    public const KEEP = 50;

    protected $fillable = ['household_id', 'webhook_id', 'uuid', 'event', 'status', 'response_code', 'error', 'attempts'];
}

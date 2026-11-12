<?php

declare(strict_types=1);

namespace Fahoba\Webhooks;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasZonedTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * A target that hears about the chosen events.
 *
 * @property int $id
 * @property int $household_id
 * @property string $name
 * @property string $url
 * @property string|null $secret
 * @property list<string> $events
 * @property bool $active
 */
final class Webhook extends Model
{
    use BelongsToHousehold, HasZonedTimestamps;

    protected $fillable = ['household_id', 'name', 'url', 'events', 'active'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'active' => 'boolean',
        ];
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /** @return HasOne<WebhookDelivery, $this> */
    public function lastDelivery(): HasOne
    {
        return $this->hasOne(WebhookDelivery::class)->latestOfMany();
    }

    /** A new signing secret, returned once to show; the receiver needs it to check signatures. */
    public function renewSecret(): string
    {
        $secret = Str::random(40);
        $this->forceFill(['secret' => $secret])->save();

        return $secret;
    }
}

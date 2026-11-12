<?php

declare(strict_types=1);

namespace Fahoba\IcsFeed;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasZonedTimestamps;
use App\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One subscription address. The secret lives only in the address the parent
 * copied; the table keeps its hash, so a leaked database opens no calendar.
 *
 * @property int $id
 * @property int $household_id
 * @property int|null $person_id
 * @property string $name
 * @property string|null $token_hash
 * @property bool $include_closures
 */
final class IcsFeed extends Model
{
    use BelongsToHousehold, HasZonedTimestamps;

    protected $fillable = ['household_id', 'person_id', 'name', 'include_closures'];

    protected function casts(): array
    {
        return [
            'include_closures' => 'boolean',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** A new secret; the old address stops working. Returns the new address. */
    public function renew(): string
    {
        $token = Str::random(40);
        $this->forceFill(['token_hash' => self::hash($token)])->save();

        return route('ics-feed.show', ['token' => $token]);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}

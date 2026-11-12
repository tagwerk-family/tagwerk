<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasZonedTimestamps;
use Carbon\CarbonImmutable;
use Database\Factories\CalendarFactory;
use Fahoba\PluginApi\Calendar\ConnectedCalendar;
use Fahoba\PluginApi\Connections\ConnectedAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

final class Calendar extends Model implements ConnectedCalendar
{
    /** @use HasFactory<CalendarFactory> */
    use BelongsToHousehold, HasFactory, HasZonedTimestamps;

    protected $fillable = [
        'household_id', 'provider_account_id', 'external_id',
        'summary', 'color', 'timezone', 'is_primary', 'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
            'push_expires_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ProviderAccount, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class, 'provider_account_id');
    }

    /**
     * Who the calendar belongs to in Fahoba. None: a family calendar.
     *
     * @return BelongsToMany<Person, $this>
     */
    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'calendar_person')->withTimestamps();
    }

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /** For core code that needs the row behind a provider's calendar. */
    public static function of(ConnectedCalendar $calendar): self
    {
        return $calendar instanceof self
            ? $calendar
            : throw new LogicException('Expected a Calendar, got '.$calendar::class.'.');
    }

    public function calendarId(): int
    {
        return (int) $this->getKey();
    }

    public function externalId(): string
    {
        return (string) $this->external_id;
    }

    public function zoneName(): string
    {
        return $this->timezone ?? $this->household?->timezone ?? (string) config('app.timezone');
    }

    public function account(): ConnectedAccount
    {
        return $this->source;
    }

    public function knownExternalIds(CarbonImmutable $from, CarbonImmutable $to): array
    {
        // In UTC: bindings lose their offset, and PostgreSQL reads them as UTC.
        return $this->events()
            ->whereNotNull('external_id')
            ->where('ends_at', '>', $from->utc())
            ->where('starts_at', '<', $to->utc())
            ->pluck('external_id')
            ->all();
    }

    public function hasPushChannel(): bool
    {
        return $this->push_channel_id !== null;
    }

    /**
     * Google never renews channels by itself. What counts is the expiry named
     * in the watch response, not an assumed lifetime.
     */
    public function pushExpiresWithin(\DateInterval $lead): bool
    {
        return $this->push_expires_at === null
            || $this->push_expires_at->sub($lead)->isPast();
    }
}

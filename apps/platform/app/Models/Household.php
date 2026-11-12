<?php

declare(strict_types=1);

namespace App\Models;

use App\Areas\Areas;
use App\Enums\Backdrop;
use App\Enums\Feature;
use App\Enums\MealKind;
use App\Enums\ThemeMode;
use App\Services\Calendar\BuiltinCalendar;
use App\Services\HouseholdNotices;
use App\Services\ParentPin;
use App\Support\Locale;
use Database\Factories\HouseholdFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Household extends Model
{
    /** @use HasFactory<HouseholdFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'address', 'street', 'postal_code', 'city', 'country_code', 'timezone', 'holiday_region',
        'theme_mode', 'theme_light_at', 'theme_dark_at', 'backdrop', 'design',
        'latitude', 'longitude', 'sunrise_offset', 'sunset_offset',
        'summary_at', 'disabled_features', 'opted_in_features', 'photo_rule', 'locale', 'day_bonus', 'pantry_staples', 'meal_kinds',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'theme_mode' => ThemeMode::class,
            'backdrop' => Backdrop::class,
            'latitude' => 'float',
            'longitude' => 'float',
            'sunrise_offset' => 'integer',
            'sunset_offset' => 'integer',
            'summary_sent_on' => 'immutable_date',
            'disabled_features' => 'array',
            'opted_in_features' => 'array',
            'pantry_staples' => 'array',
            'meal_kinds' => 'array',
            'suspended_at' => 'datetime',
            'read_only_since' => 'datetime',
            'day_bonus' => 'integer',
        ];
    }

    /**
     * Households that are not suspended — background runs skip the others.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('suspended_at');
    }

    /**
     * Households that may write. Syncs skip the others: a sync writes, and
     * a read-only household should cost the server nothing.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWritable(Builder $query): void
    {
        $query->whereNull('read_only_since');
    }

    public function isReadOnly(): bool
    {
        return $this->read_only_since !== null;
    }

    /**
     * Whether an area is usable for this household.
     *
     * An area whose prerequisite is missing is off as well: recipes without a
     * meal plan would have no place where they are needed. A plugin's area
     * whose package is no longer installed is off. An `OptIn` area is off
     * until switched on (`opted_in_features`).
     */
    public function has(Feature|string $area): bool
    {
        $key = $area instanceof Feature ? $area->value : $area;
        $feature = Feature::tryFrom($key);

        $areas = app(Areas::class);

        if ($feature === null && ! in_array($key, $areas->keys(), true)) {
            return false;
        }

        $off = $areas->isOptIn($key)
            ? ! in_array($key, (array) ($this->opted_in_features ?? []), true)
            : in_array($key, (array) ($this->disabled_features ?? []), true);

        if ($off) {
            return false;
        }

        $prerequisite = $feature?->requires() ?? $areas->requires($key);

        return $prerequisite === null || $this->has($prerequisite);
    }

    /**
     * The enabled areas, for the wall and the phone.
     *
     * @return array<int, string>
     */
    public function features(): array
    {
        return array_values(array_filter(app(Areas::class)->keys(), fn (string $area): bool => $this->has($area)));
    }

    /** Never in a response — not even as a hash. */
    /**
     * Without a PIN nothing is protected.
     *
     * Deliberately this way round: a household whose parents set no PIN
     * should be able to use the wall without restrictions. A made-up default
     * PIN would be in every manual and therefore protect nothing. The PINs
     * themselves belong to the parents' accounts.
     */
    /**
     * Never put on the shopping list from a recipe. Without a choice of its
     * own, the defaults in the household's language.
     *
     * @return list<string>
     */
    public function pantryStaples(): array
    {
        return is_array($this->pantry_staples)
            ? array_values(array_filter(array_map(strval(...), $this->pantry_staples)))
            : self::defaultStaples((string) $this->locale);
    }

    /**
     * The meals the household cooks, in the day's order. Without a choice of
     * its own, all of them.
     *
     * @return list<MealKind>
     */
    public function mealKinds(): array
    {
        $chosen = (array) ($this->meal_kinds ?? []);
        $kinds = array_values(array_filter(MealKind::cases(), fn (MealKind $kind): bool => in_array($kind->value, $chosen, true)));

        return $kinds === [] ? MealKind::cases() : $kinds;
    }

    /** @return list<string> */
    public static function defaultStaples(string $locale): array
    {
        return Locale::using($locale, static fn (): array => [__('Salt'), __('Pepper'), __('Oil'), __('Water')]);
    }

    public function hasPin(): bool
    {
        return app(ParentPin::class)->required($this);
    }

    /** @return HasMany<Person, $this> */
    public function people(): HasMany
    {
        return $this->hasMany(Person::class);
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * The accounts that belong here, through their memberships.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships');
    }

    /**
     * Every household gets its built-in calendar.
     *
     * On the model and not in the admin panel so it also applies to
     * households created by a seeder or a test — Fahoba without a writable
     * calendar would be unusable in each of those cases.
     */
    protected static function booted(): void
    {
        self::created(function (self $household): void {
            app(BuiltinCalendar::class)->ensureFor($household);
        });

        // The notice says read-only or not; a minute's stale cache would contradict the 402.
        self::updated(function (self $household): void {
            if ($household->wasChanged('read_only_since')) {
                HouseholdNotices::forget((int) $household->id);
            }
        });
    }
}

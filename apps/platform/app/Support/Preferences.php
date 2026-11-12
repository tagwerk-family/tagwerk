<?php

declare(strict_types=1);

namespace App\Support;

use App\Areas\AreaDefinition;
use App\Areas\Areas;
use App\Enums\Backdrop;

/**
 * A device's preferences — and which values are allowed.
 *
 * One place for names, allowed values and defaults: wall, phone and admin
 * panel read the same list, and a new preference is one entry here.
 */
final class Preferences
{
    /**
     * Key → allowed values. The first value is the default.
     *
     * The start area is missing here: it comes from `Areas`, so a new area —
     * a plugin's too — does not have to be added in two places.
     *
     * @var array<string, array<int, string>>
     */
    private const FIXED = [

        /*
         * The calendar view on start. `day` shows one column per person.
         * Accounts start on `day` instead (see USER_DEFAULTS).
         */
        'calendar_view' => ['three', 'day', 'week', 'month', 'list'],

        /*
         * The task view. Day is the default: one column per person answers
         * the question asked in front of the wall without anyone having to
         * search for whom a row belongs to.
         */
        'task_view' => ['day', 'list'],

        /*
         * Done tasks in the task view. Hidden by default: what is left is
         * the question; the done ones stay one tap away.
         */
        'task_done' => ['hidden', 'shown'],

        /** The school band in the calendar. */
        'school_band' => ['off', 'on'],

        /** Top of the wall's menu: the mark, or today's weather in its place. */
        'menu_top' => ['logo', 'weather'],

        /*
         * The menu where the side bar does not fit: behind the burger, or
         * as a row of icons along the bottom, one tap from every area.
         */
        'phone_menu' => ['drawer', 'bottom'],

        /** Colourful emoji instead of line icons in the menu. */
        'colored_icons' => ['off', 'on'],

        /** Task and reward icons in colour; off draws them grey. */
        'colored_emoji' => ['on', 'off'],

        /** How every star is drawn: an outline or filled, plain or in gold. */
        'star_filled' => ['off', 'on'],
        'star_color' => ['off', 'on'],

        /*
         * Small movements: bouncing smileys, filling bars, tiles that give
         * way under a finger. On by default; the device's own "reduce motion"
         * switches them off regardless.
         */
        'animations' => ['on', 'off'],

        /*
         * Frosted glass over the household's gradient instead of plain
         * surfaces. Per device: blur costs a weak tablet more than a phone.
         */
        'look' => ['classic', 'glass'],

        /** Minutes without a touch before the wall rests. */
        'screensaver_idle' => ['5', '1', '2', '10', '15'],

        /** Seconds each area stays in the resting carousel. */
        'screensaver_interval' => ['30', '15', '60'],

        /*
         * The photo frame: how pictures are laid out, which ones come, how
         * long each stays and what lies over them. One picture, uncropped,
         * slowly zooming — what the frame always did — is the default.
         */
        'photo_style' => self::PHOTO_STYLES,
        'photo_source' => ['all', 'favorites', 'albums'],
        'photo_interval' => ['30', '10', '60', '300'],
        'photo_fit' => ['fit', 'fill'],
        'photo_motion' => ['on', 'off'],
        'photo_caption' => ['on', 'off'],
        'photo_clock' => ['off', 'on'],

        /** The frame at rest; `same` follows `photo_style`. */
        'photo_rest_style' => ['same', ...self::PHOTO_STYLES],
    ];

    /** Layouts of the photo frame, in the order the settings offer them. */
    public const PHOTO_STYLES = ['single', 'duo', 'mosaic', 'ambient', 'memories'];

    /**
     * What the resting wall may show: views that read well from a distance.
     * Lists and recipes are for working with, not for looking at.
     */
    public const SCREENSAVER_AREAS = ['calendar', 'tasks', 'timetable', 'photos', 'meals', 'points', 'dashboard'];

    /**
     * Where an account's default differs from the first allowed value. A
     * phone is narrow: one day fits, three are crowded.
     */
    public const HOUSEHOLD_BACKDROP = 'household';

    /** Fahoba's own design, whatever the household chose. */
    public const CORE_DESIGN = 'core';

    private const USER_DEFAULTS = ['calendar_view' => 'day'];

    /** Only a wall tablet rests; an account has nothing to set there. */
    public const DEVICE_ONLY = ['screensaver_idle', 'screensaver_interval', 'screensaver_dim', 'screensaver_dim_night', 'screensaver_areas', 'photo_rest_style'];

    /**
     * Keys that hold a list. Empty is the default: nothing re-sorted, nothing
     * hidden. The hidden ones are stored rather than the shown ones, so an
     * area added later appears by itself instead of being swallowed.
     *
     * A tablet's menu needs the parent PIN, an account's belongs to it.
     */
    public const WALL_MENU = ['area_order', 'hidden_areas'];

    /**
     * All preferences with their allowed values.
     *
     * The start area is deliberately a setting and not "last used": after a
     * power cut a wall in the hallway should show what it was set up with.
     *
     * @return array<string, array<int, string>>
     */
    public static function allowed(): array
    {
        return [
            'start_area' => self::wallModes(),
            ...self::FIXED,
            /*
             * The gradient behind the glass. `household` follows the
             * household's choice; a tablet in the children's room may pick
             * its own.
             */
            'backdrop' => [self::HOUSEHOLD_BACKDROP, ...array_column(Backdrop::cases(), 'value')],
            /*
             * Colours, font and corners from an installed design pack.
             * `household` follows the household, `core` is Fahoba's own.
             */
            'design' => [self::HOUSEHOLD_BACKDROP, self::CORE_DESIGN, ...app(Designs::class)->keys()],
            /*
             * How dark the resting wall gets, in percent; veil and Fully's
             * brightness follow it. Night is the household's dark theme.
             */
            'screensaver_dim' => self::darkening('50'),
            'screensaver_dim_night' => self::darkening('70'),
        ];
    }

    /** @return list<string> 0–90 in steps of five, the default first */
    private static function darkening(string $default): array
    {
        $steps = array_map(strval(...), range(0, 90, 5));

        return [$default, ...array_values(array_diff($steps, [$default]))];
    }

    /**
     * List preferences with the values their entries may take.
     *
     * @return array<string, array<int, string>>
     */
    public static function lists(): array
    {
        return [
            'area_order' => self::wallModes(),
            'hidden_areas' => self::wallModes(),

            // The carousel in this order; empty means no resting mode.
            'screensaver_areas' => self::SCREENSAVER_AREAS,
        ];
    }

    /**
     * Lists of ids. The last people filters: person ids, null for what
     * belongs to nobody, empty shows everyone. `photo_albums`: the albums
     * the frame draws from with `photo_source` `albums`.
     */
    public const FILTERS = ['task_people', 'calendar_people', 'photo_albums'];

    /**
     * An account keeps these per household: households switch off different
     * areas, and people ids belong to one household. The rest describes the
     * account and follows it everywhere.
     */
    public const PER_HOUSEHOLD = ['start_area', 'area_order', 'hidden_areas', 'task_people', 'calendar_people', 'photo_albums'];

    /** @return array<int, string> */
    private static function wallModes(): array
    {
        return array_values(array_map(
            static fn (AreaDefinition $area): string => $area->key,
            array_filter(app(Areas::class)->all(), static fn (AreaDefinition $area): bool => $area->inMenu),
        ));
    }

    /**
     * The defaults, overridden by what is stored.
     *
     * Unknown keys and values are dropped: an older wall writing a value
     * that no longer exists must not break anything.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array<string, string|array<int, string|int|null>>
     */
    public static function merged(?array $stored, bool $account = false): array
    {
        $values = [];

        foreach (self::allowed() as $key => $allowed) {
            $value = $stored[$key] ?? null;

            $values[$key] = is_string($value) && in_array($value, $allowed, true)
                ? $value
                : ($account ? self::USER_DEFAULTS[$key] ?? $allowed[0] : $allowed[0]);
        }

        foreach (self::lists() as $key => $allowed) {
            $value = $stored[$key] ?? [];

            $values[$key] = is_array($value)
                ? array_values(array_unique(array_filter($value, static fn (mixed $entry): bool => in_array($entry, $allowed, true))))
                : [];
        }

        foreach (self::FILTERS as $key) {
            $value = $stored[$key] ?? [];

            $values[$key] = is_array($value)
                ? array_values(array_unique(array_filter($value, static fn (mixed $entry): bool => $entry === null || is_int($entry)), SORT_REGULAR))
                : [];
        }

        return $values;
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(bool $account = false): array
    {
        $rules = [];

        foreach (self::allowed() as $key => $allowed) {
            $rules[$key] = ['sometimes', 'string', 'in:'.implode(',', $allowed)];
        }

        foreach (self::lists() as $key => $allowed) {
            $rules[$key] = ['sometimes', 'array'];
            $rules[$key.'.*'] = ['string', 'distinct', 'in:'.implode(',', $allowed)];
        }

        foreach (self::FILTERS as $key) {
            $rules[$key] = ['sometimes', 'array', 'max:50'];
            $rules[$key.'.*'] = ['nullable', 'integer', 'distinct'];
        }

        if ($account) {
            foreach (self::DEVICE_ONLY as $key) {
                $rules[$key] = ['prohibited'];
            }
        }

        return $rules;
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * An area of Fahoba that a household can switch off.
 *
 * Not every family wants everything: whoever does not hand out stars should
 * not have to look past the tile. Switched off also means switched off for
 * the API — otherwise it would only be a display matter, and a device with a
 * stale state would keep writing.
 *
 * Data survives switching off. Whoever turns stars back on half a year later
 * finds the state from back then.
 */
enum Feature: string
{
    /*
     * The order of the cases is the order of the wall's navigation: overview
     * first, then the course of the day.
     */
    case Dashboard = 'dashboard';
    case Calendar = 'calendar';
    case Tasks = 'tasks';
    case Lists = 'lists';
    case Points = 'points';
    case Meals = 'meals';
    case Recipes = 'recipes';
    case Timetable = 'timetable';
    case Photos = 'photos';

    public function label(): string
    {
        return match ($this) {
            self::Calendar => __('Calendar'),
            self::Tasks => __('Tasks'),
            self::Lists => __('Lists'),
            self::Points => __('Stars'),
            self::Meals => __('Meal plan'),
            self::Recipes => __('Recipes'),
            self::Timetable => __('Timetable'),
            self::Photos => __('Picture frame'),
            self::Dashboard => __('Dashboard'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Calendar => __('Events on the wall and the phone.'),
            self::Tasks => __('Recurring tasks and completions.'),
            self::Lists => __('Shopping and packing lists.'),
            self::Points => __('Stars, rewards and redemptions.'),
            self::Meals => __('Weekly meal plan.'),
            self::Recipes => __('Your own recipe book with ingredient lists.'),
            self::Timetable => __('School timetable per child, with holidays and cancellations.'),
            self::Photos => __('Your own photos as a changing picture on the wall.'),
            self::Dashboard => __('The home page of freely placed tiles.'),
        };
    }

    /** The wall view for this area — also the value a device stores as its start screen. */
    public function wallMode(): string
    {
        return $this->value;
    }

    /** The label in the wall's narrow navigation. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Dashboard => __('Home'),
            self::Meals => __('Meals'),
            self::Timetable => __('School'),
            self::Photos => __('Photos'),
            default => $this->label(),
        };
    }

    /** The icon name in the shared icon set of the frontends. */
    public function icon(): string
    {
        return match ($this) {
            self::Dashboard => 'dashboard',
            self::Calendar => 'calendar',
            self::Tasks => 'tasks',
            self::Lists => 'lists',
            self::Points => 'points',
            self::Meals => 'meals',
            self::Recipes => 'recipes',
            self::Timetable => 'school',
            self::Photos => 'photo',
        };
    }

    /**
     * Without a meal plan a recipe has no place where it is needed.
     *
     * The dependency lives here and not in the admin panel so that it also
     * holds when the setting is changed outside Filament.
     */
    public function requires(): ?self
    {
        return match ($this) {
            self::Recipes => self::Meals,
            default => null,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ListKind;
use App\Models\Checklist;
use App\Models\Person;
use App\Models\Reward;
use App\Models\Task;
use Carbon\CarbonImmutable;

/**
 * What a new household starts with, so neither wall nor phone open empty:
 * setup steps for the first parent, routines and rewards for children, and
 * a shopping list. All of it is ordinary data — change or delete at will.
 *
 * The routines are per person without a choice of people: there are no
 * children yet, and every child created later gets them on its own.
 */
final class HouseholdStarter
{
    /**
     * Stored as plain data, so written in the language active while filling.
     *
     * @return array<int, array{string, string, string}> icon, title, notes
     */
    private static function setup(): array
    {
        return [
            ['👋', __('Get to know Fahoba'), __('Click once through wall, phone and admin panel: tasks, lists, stars, meals and calendar.')],
            ['👥', __('Add family members'), __('In the admin panel under People — with colour and photo, children with the role “Child”.')],
            ['📱', __('Connect the tablet'), __('Create a device in the admin panel and enter the pairing code on the tablet.')],
            ['📲', __('Fahoba on your phone'), __('Scan the QR code on the admin panel dashboard and install the app.')],
            ['📅', __('Connect Google Calendar'), __('In the admin panel under Connections.')],
            ['🎒', __('Enter the timetable'), __('Set the bell schedule on the child, then tap the lessons in the weekly grid.')],
        ];
    }

    /** @return array<int, array{string, string, string, int, array<int, string>}> icon, title, rrule, stars, dayparts */
    private static function routines(): array
    {
        return [
            ['🪥', __('Brush teeth'), 'FREQ=DAILY', 1, ['morning', 'evening']],
            ['🛏️', __('Make the bed'), 'FREQ=DAILY', 1, ['morning']],
            ['🎒', __('Pack the school bag'), 'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR', 2, ['evening']],
            ['🧸', __('Tidy up the room'), 'FREQ=DAILY', 2, []],
            ['🍽️', __('Set the table'), 'FREQ=DAILY', 2, []],
            ['🌱', __('Water the plants'), 'FREQ=WEEKLY;BYDAY=WE,SA', 1, []],
        ];
    }

    /** @return array<int, array{string, string, int}> icon, name, cost */
    private static function rewards(): array
    {
        return [
            ['📺', __('30 minutes of screen time'), 10],
            ['🍦', __('Ice cream or sweets'), 15],
            ['🎲', __('Games night – I choose the game'), 20],
            ['🍕', __('Choose a favourite meal'), 25],
            ['🌙', __('Stay up 30 minutes longer'), 30],
            ['🎬', __('Cinema night or a trip'), 60],
        ];
    }

    /** Runs inside the new household's context. */
    public function fill(Person $parent, string $timezone): void
    {
        $today = CarbonImmutable::now($timezone)->toDateString();
        $position = 0;

        foreach (self::setup() as [$icon, $title, $notes]) {
            Task::create([
                'person_id' => $parent->id,
                'icon' => $icon,
                'title' => $title,
                'notes' => $notes,
                'due_on' => $today,
                'points' => 0,
                'position' => $position++,
            ]);
        }

        foreach (self::routines() as [$icon, $title, $rrule, $points, $dayparts]) {
            Task::create([
                'icon' => $icon,
                'title' => $title,
                'rrule' => $rrule,
                'per_person' => true,
                'dayparts' => $dayparts ?: null,
                'due_on' => $today,
                'points' => $points,
                'position' => $position++,
            ]);
        }

        foreach (self::rewards() as $index => [$icon, $name, $cost]) {
            Reward::create(['icon' => $icon, 'name' => $name, 'cost' => $cost, 'position' => $index]);
        }

        $shopping = Checklist::create([
            'name' => __('Shopping'),
            'kind' => ListKind::Shopping,
            'color' => '#059669',
            'position' => 0,
        ]);

        foreach ([__('Milk'), __('Bread'), __('Apples')] as $index => $title) {
            $shopping->items()->create(['title' => $title, 'position' => $index]);
        }
    }
}

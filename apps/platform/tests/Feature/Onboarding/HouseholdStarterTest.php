<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Enums\Daypart;
use App\Models\Checklist;
use App\Models\Household;
use App\Models\ListItem;
use App\Models\Reward;
use App\Models\Task;
use App\Services\HouseholdOnboarding;
use App\Support\HouseholdContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A new household opens with something to see: setup steps for the parent,
 * routines and rewards for children, and a shopping list.
 */
final class HouseholdStarterTest extends TestCase
{
    use RefreshDatabase;

    private function newHousehold(string $name = 'Familie Muster'): int
    {
        $user = app(HouseholdOnboarding::class)->create($name, 'Europe/Vienna', 'Alex', strtolower(str_replace(' ', '', $name)).'@example.test', 'long-enough-1');

        app(HouseholdContext::class)->setId((int) $user->memberships()->sole()->household_id);

        return (int) $user->memberships()->sole()->person_id;
    }

    public function test_the_parent_gets_setup_steps_without_stars(): void
    {
        $parent = $this->newHousehold();

        $setup = Task::query()->where('person_id', $parent)->get();

        $this->assertContains('Tablet verbinden', $setup->pluck('title')->all());
        $this->assertContains('Family Home Base kennenlernen', $setup->pluck('title')->all());
        $this->assertTrue($setup->every(fn (Task $task): bool => $task->points === 0 && $task->rrule === null));
    }

    /** No children exist yet: the routines stand for every child to come. */
    public function test_children_find_routines_and_rewards(): void
    {
        $this->newHousehold();

        $routines = Task::query()->where('per_person', true)->get();

        $this->assertSame(['morning', 'evening'], $routines->firstWhere('title', 'Zähne putzen')?->dayparts);
        $this->assertTrue($routines->every(fn (Task $task): bool => $task->rrule !== null && $task->points > 0 && $task->active && $task->per_person_ids === null));
        $this->assertSame(6, Reward::query()->where('active', true)->count());
    }

    /**
     * Wall and phone draw icons from public/emoji; one the set lacks would
     * show as the system's emoji, the only plain glyph among pictures.
     */
    public function test_every_starter_icon_has_a_picture(): void
    {
        $this->newHousehold();

        $icons = [
            ...Task::query()->pluck('icon')->all(),
            ...Reward::query()->pluck('icon')->all(),
            ...array_map(fn (Daypart $part): string => $part->icon(), Daypart::cases()),
        ];

        foreach (array_filter($icons) as $icon) {
            $codes = array_map(fn (string $character): string => dechex(mb_ord($character)), mb_str_split($icon));
            $hex = implode('-', array_diff($codes, ['fe0f']));

            $this->assertFileExists(public_path("emoji/flat/{$hex}.svg"), $icon);
            $this->assertFileExists(public_path("emoji/mono/{$hex}.svg"), $icon);
        }
    }

    public function test_a_shopping_list_shows_how_lists_work(): void
    {
        $this->newHousehold();

        $this->assertSame(['Einkauf'], Checklist::query()->pluck('name')->all());
        $this->assertSame(3, ListItem::query()->count());
    }

    public function test_every_household_gets_its_own_start(): void
    {
        $this->newHousehold('Familie Eins');
        $this->newHousehold('Familie Zwei');

        foreach (Household::query()->pluck('id') as $id) {
            $this->assertSame(6, Reward::query()->withoutGlobalScopes()->where('household_id', $id)->count());
        }
    }
}

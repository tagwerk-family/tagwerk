<?php

declare(strict_types=1);

namespace Tests\Feature\Lists;

use App\Models\Checklist;
use App\Models\Household;
use App\Support\HouseholdContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClearDoneItemsTest extends TestCase
{
    use RefreshDatabase;

    public function test_items_done_for_twelve_hours_go_in_every_household(): void
    {
        $lists = Household::factory()->count(2)->create()->map(function (Household $household): Checklist {
            return app(HouseholdContext::class)->forId((int) $household->id, function () use ($household): Checklist {
                $list = Checklist::factory()->create(['household_id' => $household->id, 'list_provider' => null]);
                $list->items()->create(['title' => 'Gestern gekauft', 'done_at' => now()->subHours(13)]);
                $list->items()->create(['title' => 'Eben gekauft', 'done_at' => now()->subHour()]);
                $list->items()->create(['title' => 'Noch offen']);

                return $list;
            });
        });

        $this->artisan('fahoba:clear-done-items')->assertSuccessful();

        foreach ($lists as $list) {
            $left = app(HouseholdContext::class)->forId(
                (int) $list->household_id,
                fn (): array => $list->items()->orderBy('id')->pluck('title')->all(),
            );

            // Still visible the same evening, open items are never touched.
            $this->assertSame(['Eben gekauft', 'Noch offen'], $left);
        }
    }
}

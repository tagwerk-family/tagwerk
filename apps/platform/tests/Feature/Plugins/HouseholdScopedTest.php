<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Models\Household;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Households\CurrentHousehold;
use Fahoba\PluginApi\Households\HouseholdScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A plugin's own table gets the same rule as the core's: no context, no rows. */
final class HouseholdScopedTest extends TestCase
{
    use RefreshDatabase;

    private Household $a;

    private Household $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Household::factory()->create(['name' => 'Huber', 'timezone' => 'Europe/Vienna', 'locale' => 'de']);
        $this->b = Household::factory()->create();

        PluginReward::withoutHouseholdScope()->insert([
            ['household_id' => $this->a->id, 'name' => 'Eis', 'cost' => 5],
            ['household_id' => $this->b->id, 'name' => 'Kino', 'cost' => 9],
        ]);
    }

    public function test_without_a_context_nothing_is_visible(): void
    {
        $this->assertSame(0, PluginReward::query()->count());
    }

    public function test_with_a_context_only_that_households_rows(): void
    {
        app(HouseholdContext::class)->setId((int) $this->a->id);

        $this->assertSame(['Eis'], PluginReward::query()->pluck('name')->all());
    }

    public function test_creating_fills_the_household(): void
    {
        app(HouseholdContext::class)->setId((int) $this->b->id);

        $reward = PluginReward::query()->create(['name' => 'Zoo', 'cost' => 3]);

        $this->assertSame((int) $this->b->id, (int) $reward->household_id);
    }

    public function test_background_work_sees_every_household(): void
    {
        $this->assertSame(2, PluginReward::withoutHouseholdScope()->count());
    }

    public function test_the_current_household_describes_itself(): void
    {
        $current = app(CurrentHousehold::class);
        $this->assertNull($current->id());
        $this->assertNull($current->name());

        app(HouseholdContext::class)->setId((int) $this->a->id);

        $this->assertSame((int) $this->a->id, $current->id());
        $this->assertSame('Huber', $current->name());
        $this->assertSame('Europe/Vienna', $current->timezone());
        $this->assertSame('de', $current->locale());
    }
}

final class PluginReward extends Model
{
    use HouseholdScoped;

    protected $table = 'rewards';

    protected $fillable = ['household_id', 'name', 'cost'];
}

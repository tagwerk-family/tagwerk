<?php

declare(strict_types=1);

namespace Tests\Feature\Statistics;

use App\Enums\PersonRole;
use App\Filament\Pages\Statistics;
use App\Filament\Statistics\CompletionsTimeline;
use App\Filament\Statistics\ReliabilityChart;
use App\Filament\Statistics\StarsChart;
use App\Filament\Statistics\StatisticsOverview;
use App\Filament\Statistics\TaskMatrix;
use App\Filament\Statistics\WeekdayChart;
use App\Models\Household;
use App\Models\Person;
use App\Models\Task;
use App\Models\TaskCompletion;
use App\Models\User;
use App\Support\HouseholdContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Parents open the statistics, narrow them and take the rows along. */
final class StatisticsPageTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Person $child;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-16 18:00', 'Europe/Vienna'));

        $this->household = Household::factory()->create(['timezone' => 'Europe/Vienna']);
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));
        app(HouseholdContext::class)->setId($this->household->id);

        $this->child = Person::factory()->for($this->household)->create(['name' => 'Ben', 'role' => PersonRole::Child, 'color' => '#2563eb']);
        $this->task = Task::factory()->for($this->household)->create(['title' => 'Zähne putzen', 'icon' => '🪥']);

        foreach (['2026-09-14', '2026-09-15'] as $day) {
            TaskCompletion::factory()->create([
                'household_id' => $this->household->id,
                'task_id' => $this->task->id,
                'person_id' => $this->child->id,
                'occurred_on' => $day,
                'completed_at' => CarbonImmutable::parse($day.' 07:30', 'Europe/Vienna'),
            ]);
        }
    }

    public function test_the_page_opens_for_a_parent(): void
    {
        $this->get('/admin/statistics')->assertSuccessful()->assertSee(__('Statistics'));
    }

    public function test_every_widget_renders(): void
    {
        foreach ([StatisticsOverview::class, CompletionsTimeline::class, ReliabilityChart::class, WeekdayChart::class, StarsChart::class] as $widget) {
            Livewire::test($widget, ['pageFilters' => ['period' => '28']])->assertSuccessful();
        }
    }

    public function test_the_matrix_names_task_person_and_count(): void
    {
        Livewire::test(TaskMatrix::class, ['pageFilters' => ['period' => '28']])
            ->assertSee('Zähne putzen')
            ->assertSee('Ben')
            ->assertSeeHtml('--share: 90%');
    }

    public function test_a_filter_for_another_person_empties_the_matrix(): void
    {
        $other = Person::factory()->for($this->household)->create(['name' => 'Mia', 'role' => PersonRole::Child]);

        Livewire::test(TaskMatrix::class, ['pageFilters' => ['period' => '28', 'people' => [(string) $other->id]]])
            ->assertDontSee('Zähne putzen')
            ->assertSee(__('Nothing was done in this period.'));
    }

    public function test_the_export_downloads_every_completion(): void
    {
        Livewire::test(Statistics::class)
            ->set('filters.period', '28')
            ->callAction('export')
            ->assertFileDownloaded('tagwerk-'.__('statistics').'-2026-08-20-2026-09-16.csv');
    }

    public function test_the_export_rows(): void
    {
        $component = Livewire::test(Statistics::class)->set('filters.period', '28')->callAction('export');
        $csv = base64_decode((string) data_get($component->effects, 'download.content'), true);

        $this->assertIsString($csv);
        $lines = array_values(array_filter(explode("\n", $csv)));
        $this->assertCount(3, $lines);
        $this->assertStringStartsWith("\u{FEFF}", $lines[0]);
        $this->assertStringContainsString('2026-09-14;', $lines[1]);
        $this->assertStringContainsString(';"Zähne putzen";Ben;', $lines[1]);
    }

    public function test_a_household_without_context_cannot_open_it(): void
    {
        app(HouseholdContext::class)->setId(null);

        $this->assertFalse(Statistics::canAccess());
    }
}

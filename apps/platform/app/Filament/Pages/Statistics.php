<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Daypart;
use App\Filament\NavigationGroup;
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
use App\Services\Statistics\StatisticsPeriod;
use App\Services\Statistics\TaskStatistics;
use App\Support\HouseholdContext;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tagwerk\PluginApi\Households\AvailableWhileReadOnly;
use UnitEnum;

/**
 * Who did what and how often. Reads only, so it stays open while the
 * household is read-only; the filters live in the address, so a view can
 * be bookmarked or sent to the other parent.
 */
class Statistics extends Page implements AvailableWhileReadOnly
{
    use HasFiltersForm;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Everyday;

    protected static ?string $slug = 'statistics';

    protected static ?int $navigationSort = 90;

    public static function canAccess(): bool
    {
        return app(HouseholdContext::class)->hasId();
    }

    public static function getNavigationLabel(): string
    {
        return __('Statistics');
    }

    public function getTitle(): string
    {
        return __('Statistics');
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('period')
                ->label(__('Time span'))
                ->options([
                    '28' => __('Last 4 weeks'),
                    '91' => __('Last 3 months'),
                    '365' => __('Last 12 months'),
                    'year' => __('This year'),
                    'custom' => __('Choose dates'),
                ])
                ->default('28')
                ->selectablePlaceholder(false),
            DatePicker::make('from')
                ->label(__('From'))
                ->native(false)
                ->visible(fn (Get $get): bool => $get('period') === 'custom'),
            DatePicker::make('until')
                ->label(__('Until'))
                ->native(false)
                ->visible(fn (Get $get): bool => $get('period') === 'custom'),
            Select::make('people')
                ->label(__('People'))
                ->multiple()
                ->placeholder(__('Everyone'))
                ->options(fn (): array => Person::query()->ordered()->pluck('name', 'id')->all()),
            Select::make('task')
                ->label(__('Task'))
                ->placeholder(__('All tasks'))
                ->searchable()
                ->options(fn (): array => Task::withTrashed()->orderBy('title')->pluck('title', 'id')->all()),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('filtersForm'),
            Grid::make(['default' => 1, 'lg' => 2])
                ->schema(fn (): array => $this->getWidgetsSchemaComponents([
                    StatisticsOverview::class,
                    CompletionsTimeline::class,
                    ReliabilityChart::class,
                    WeekdayChart::class,
                    TaskMatrix::class,
                    StarsChart::class,
                ])),
        ]);
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('Download as CSV'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->export()),
        ];
    }

    /**
     * Every completion of the period as a row, for a spreadsheet. A
     * semicolon and a BOM, because that is what Excel opens correctly with
     * German settings — the family's likeliest tool.
     */
    private function export(): StreamedResponse
    {
        $household = Household::query()->findOrFail(app(HouseholdContext::class)->id());
        $period = StatisticsPeriod::fromFilters($this->filters, CarbonImmutable::now($household->timezone));
        $statistics = app(TaskStatistics::class);
        $names = Person::query()->pluck('name', 'id');
        $file = 'tagwerk-'.__('statistics').'-'.$period->from->toDateString().'-'.$period->until->toDateString().'.csv';

        return response()->streamDownload(function () use ($statistics, $period, $names): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, [__('Date'), __('Weekday'), __('Time of day'), __('Task'), __('Done by'), __('For'), __('Stars')], ';', escape: '');

            $statistics->completions($period)
                ->with(['task', 'pointEntry'])
                ->orderBy('occurred_on')
                ->orderBy('completed_at')
                ->chunk(500, function ($completions) use ($out, $names): void {
                    foreach ($completions as $completion) {
                        /** @var TaskCompletion $completion */
                        $day = CarbonImmutable::parse($completion->occurred_on->toDateString());
                        $daypart = $completion->daypart instanceof Daypart ? $completion->daypart : Daypart::tryFrom((string) $completion->daypart);

                        fputcsv($out, [
                            $day->toDateString(),
                            $day->isoFormat('dddd'),
                            $daypart?->label() ?? '',
                            (string) $completion->task?->title,
                            $names[$completion->person_id] ?? __('Without a name'),
                            $completion->for_person_id === null ? '' : ($names[$completion->for_person_id] ?? ''),
                            (int) ($completion->pointEntry->points ?? 0),
                        ], ';', escape: '');
                    }
                });

            fclose($out);
        }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}

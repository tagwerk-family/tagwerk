<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Tables;

use App\Integrations\ProviderRegistry;
use App\Models\Person;
use App\Models\Task;
use App\Services\RecurrenceRule;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TasksTable
{
    public static function configure(Table $table): Table
    {
        // The household's people once per render, not once per row.
        $people = null;
        $household = function () use (&$people): Collection {
            return $people ??= Person::ordered()->get();
        };

        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('Task'))
                    // With the symbol in front: that shows at a glance which
                    // task still appears as plain text on the wall.
                    ->formatStateUsing(fn (Task $record): string => trim($record->icon.' '.$record->title))
                    ->weight(FontWeight::Medium)
                    ->description(fn (Task $record): ?string => $record->notes)
                    ->wrap()
                    ->searchable()
                    ->sortable(),

                /*
                 * Only for imported tasks: for a household without a
                 * connection, a column full of "Fahoba" would say nothing.
                 */
                TextColumn::make('provider')
                    ->label(__('Source'))
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (Task $record): ?string => $record->isImported()
                        ? app(ProviderRegistry::class)->forAccount($record->account)->label()
                        : null),
                TextColumn::make('rrule')
                    ->label(__('Repeats'))
                    ->state(fn (Task $record): string => RecurrenceRule::describeRule($record->rrule, $record->due_on))
                    ->badge()
                    ->icon(fn (Task $record): Heroicon => $record->isRoutine() ? Heroicon::OutlinedArrowPath : Heroicon::OutlinedCalendar)
                    ->color(fn (Task $record): string => $record->isRoutine() ? 'primary' : 'gray'),
                TextColumn::make('due_on')
                    ->label(__('Due or from'))
                    ->date()
                    ->sortable(),
                // Faces instead of a name: a task for every child stays one
                // row wide, and the family recognises itself faster.
                ImageColumn::make('responsible')
                    ->label(__('Assigned to'))
                    ->state(fn (Task $record): array => $record->responsible($household())
                        ->map(fn (Person $person): string => $person->face())
                        ->all())
                    ->circular()
                    ->stacked()
                    ->imageSize(28)
                    ->tooltip(fn (Task $record): string => $record->responsible($household())->pluck('name')->join(', '))
                    ->placeholder(__('Everyone')),
                TextColumn::make('points')
                    ->label(__('Stars'))
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                ToggleColumn::make('active')
                    ->label(__('Active')),
            ])
            // The order here is the order on the wall.
            ->defaultSort('position')
            ->reorderable('position')
            ->filters([
                TernaryFilter::make('routine')
                    ->label(__('Repeats'))
                    ->placeholder(__('All tasks'))
                    ->trueLabel(__('Recurring only'))
                    ->falseLabel(__('One-off only'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('rrule')->where('rrule', '!=', ''),
                        false: fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->whereNull('rrule')->orWhere('rrule', '')),
                    ),
                TernaryFilter::make('active')
                    ->label(__('Active')),
                // Deleted tasks keep their stars and can come back from here.
                TrashedFilter::make(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentCheck)
            ->emptyStateHeading(__('No tasks yet'))
            ->emptyStateDescription(__('Routines like “Brush teeth” or one-off tasks like “Change to winter tyres” then appear on the wall.'))
            ->recordActions([
                EditAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->using(static function (Collection $records): void {
                            $records->each(static fn (Task $task) => $task->retire());
                        }),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}

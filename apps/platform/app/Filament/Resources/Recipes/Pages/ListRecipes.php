<?php

namespace App\Filament\Resources\Recipes\Pages;

use App\Filament\Resources\Recipes\RecipeResource;
use App\Services\Recipes\RecipeImport;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListRecipes extends ListRecords
{
    protected static string $resource = RecipeResource::class;

    /** @return array<int, Action|ActionGroup|CreateAction> */
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('link')
                    ->label(__('From a link'))
                    ->icon('heroicon-o-link')
                    ->schema([
                        TextInput::make('url')
                            ->label(__('Address of the recipe page'))
                            ->url()
                            ->required()
                            ->maxLength(2048),
                    ])
                    ->action(function (array $data, RecipeImport $import): void {
                        try {
                            $recipe = $import->fromLink((string) $data['url']);
                        } catch (ValidationException $error) {
                            Notification::make()->title($error->getMessage())->danger()->send();

                            return;
                        }

                        $this->redirect(RecipeResource::getUrl('edit', ['record' => $recipe]));
                    }),
                Action::make('import')
                    ->label(__('Import collection'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->schema([
                        FileUpload::make('file')
                            ->label(__('Collection (ZIP)'))
                            ->disk('local')
                            ->directory('recipe-imports')
                            ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])
                            ->required(),
                    ])
                    ->action(function (array $data, RecipeImport $import): void {
                        $path = (string) $data['file'];

                        try {
                            $result = $import->import(Storage::disk('local')->path($path));
                            Notification::make()
                                ->title(__(':imported recipes imported, :skipped skipped.', $result))
                                ->success()
                                ->send();
                        } catch (ValidationException $error) {
                            Notification::make()->title($error->getMessage())->danger()->send();
                        } finally {
                            Storage::disk('local')->delete($path);
                        }
                    }),
                Action::make('export')
                    ->label(__('Export collection'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn (RecipeImport $import): BinaryFileResponse => response()
                        ->download($import->export(), 'fahoba-recipes.zip', ['Content-Type' => 'application/zip'])
                        ->deleteFileAfterSend()),
            ])
                ->label(__('Collection'))
                ->icon('heroicon-o-ellipsis-vertical')
                ->button()
                ->color('gray'),
            CreateAction::make(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Integrations\Plugins\Extensions as InstalledExtensions;
use App\Integrations\Plugins\PluginCatalog;
use App\Integrations\ProviderContracts;
use App\Models\User;
use BackedEnum;
use Fahoba\PluginApi\Plugins\Extension;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Operations → Extensions: what is installed on the server, and the switch
 * and settings of every extension. Only the operator sees it — an
 * extension changes the server for every household.
 *
 * Providers, widgets and the other plugin areas are listed for reference;
 * they have no switch here, a household connects them itself.
 */
class Extensions extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Operations;

    protected static ?string $slug = 'operator/extensions';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Extensions');
    }

    public function getTitle(): string
    {
        return __('Extensions');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_operator === true;
    }

    public function mount(): void
    {
        $extensions = app(InstalledExtensions::class);

        $this->form->fill(collect($extensions->installed())
            ->mapWithKeys(fn (Extension $extension, string $key): array => [self::field($key) => [
                'enabled' => $extensions->enabled($key),
                'settings' => $extensions->settings($key),
            ]])
            ->all());
    }

    public function form(Schema $schema): Schema
    {
        $sections = collect(app(InstalledExtensions::class)->installed())
            ->sortBy(fn (Extension $extension): string => $extension->category()->value.$extension->label())
            ->map(fn (Extension $extension, string $key): Section => Section::make($extension->label())
                ->description($extension->description())
                ->aside()
                ->statePath(self::field($key))
                ->schema([
                    Text::make($extension->category()->label().' · '.$key),
                    Toggle::make('enabled')->label(__('Switched on')),
                    Section::make()
                        ->statePath('settings')
                        ->schema($extension->settingsSchema())
                        ->visible(fn (Get $get): bool => $get('enabled') === true && $extension->settingsSchema() !== []),
                ]))
            ->values()
            ->all();

        return $schema
            ->components($sections === [] ? [Text::make(__('No extension is installed.'))] : $sections)
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label(__('Save'))->submit('save')])]),
            Section::make(__('Other plugins'))
                ->description(__('Installed means offered: each household connects them itself.'))
                ->schema([Text::make($this->otherPlugins())]),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $extensions = app(InstalledExtensions::class);
        $user = auth()->user();
        assert($user instanceof User);

        foreach ($extensions->installed() as $key => $extension) {
            $enabled = (bool) ($state[self::field($key)]['enabled'] ?? false);
            $settings = (array) ($state[self::field($key)]['settings'] ?? []);

            if (($refusal = $extension->refuse($enabled, $settings, $user)) !== null) {
                Notification::make()->title($extension->label())->body($refusal)->danger()->send();

                return;
            }
        }

        foreach ($extensions->installed() as $key => $extension) {
            $extensions->save(
                $key,
                (bool) ($state[self::field($key)]['enabled'] ?? false),
                (array) ($state[self::field($key)]['settings'] ?? []),
            );
        }

        Notification::make()->title(__('Saved'))->success()->send();
    }

    /** Livewire paths split on dots; plugin keys carry a slash. */
    private static function field(string $key): string
    {
        return str_replace(['/', '-'], '_', $key);
    }

    private function otherPlugins(): string
    {
        $catalog = app(PluginCatalog::class);
        $lines = [];

        foreach ([...array_keys(ProviderContracts::BASE), 'areas', 'widgets', 'photo_rules', 'csp', 'notices'] as $area) {
            foreach (array_keys($catalog->offered($area)) as $key) {
                $lines[] = "{$key} ({$area})";
            }
        }

        return $lines === [] ? __('None.') : implode(' · ', array_unique($lines));
    }
}

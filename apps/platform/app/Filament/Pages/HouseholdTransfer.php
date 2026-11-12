<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Jobs\BuildHouseholdArchiveJob;
use App\Jobs\ImportHouseholdArchiveJob;
use App\Models\Household;
use App\Models\User;
use App\Notifications\HouseholdArchiveReady;
use App\Support\HouseholdContext;
use App\Support\OutgoingMail;
use App\Support\ReadOnlyAdmin;
use BackedEnum;
use Fahoba\PluginApi\Households\AvailableWhileReadOnly;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * The family's own copy of everything (GDPR Art. 20), and the way to take
 * the household to another Fahoba server — or to bring one here.
 * Open while read-only: the data stays the family's either way.
 */
class HouseholdTransfer extends Page implements AvailableWhileReadOnly
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Setup;

    protected static ?string $slug = 'export';

    protected static ?int $navigationSort = 90;

    public static function canAccess(): bool
    {
        return app(HouseholdContext::class)->hasId();
    }

    public static function getNavigationLabel(): string
    {
        return __('Export & move');
    }

    public function getTitle(): string
    {
        return __('Export & move');
    }

    public function content(Schema $schema): Schema
    {
        $archive = Cache::get(BuildHouseholdArchiveJob::cacheKey(self::householdId()));
        $import = Cache::get(ImportHouseholdArchiveJob::cacheKey((int) self::user()?->id));
        $hosted = (string) config('fahoba.hosted_url');

        return $schema->components([
            Section::make(__('Export'))
                ->description(__('Everything of this household in one file: calendars, tasks, stars, lists, recipes, photos. Another Family Home Base server imports it. Paired tablets do not travel; connections only with their passwords.'))
                ->schema([
                    Text::make(__('This export contains passwords in plain text. Do not forward it; delete it after the move.'))
                        ->color('danger')
                        ->weight('bold')
                        ->visible(is_array($archive) && ($archive['secrets'] ?? false)),
                    Text::make(is_array($archive)
                        ? __('Ready since :at; the link works for :days days.', ['at' => $archive['at'], 'days' => BuildHouseholdArchiveJob::DAYS])
                        : (OutgoingMail::enabled() ? __('Building the file can take a while with many photos; a mail brings the link.') : __('Building the file can take a while with many photos; reload this page.'))),
                    Actions::make([
                        Action::make('build')
                            ->label(__('Prepare export'))
                            ->icon('heroicon-o-archive-box-arrow-down')
                            ->modalHeading(__('Prepare export'))
                            ->modalSubmitActionLabel(__('Prepare export'))
                            ->schema([
                                Toggle::make('secrets')
                                    ->label(__('Include passwords in plain text'))
                                    ->helperText(__('Only for a move to another server: connections (Bring, CalDAV, Tandoor …), webhooks and dashboard tiles keep their passwords and keys there. Google keeps working only if the new server uses the same Google client; otherwise it asks to connect again. Anyone who gets hold of the file can read everything. Do not forward it; delete it after the move.'))
                                    ->live(),
                                Checkbox::make('understood')
                                    ->label(__('I understand that the file then contains passwords in plain text.'))
                                    ->visible(fn (Get $get): bool => (bool) $get('secrets'))
                                    ->accepted(fn (Get $get): bool => (bool) $get('secrets')),
                            ])
                            ->action(function (array $data): void {
                                BuildHouseholdArchiveJob::dispatch(self::householdId(), (int) self::user()?->id, (bool) ($data['secrets'] ?? false));
                                Notification::make()->success()->title(__('The export is being prepared.'))->send();
                            }),
                        Action::make('download')
                            ->label(__('Download'))
                            ->icon('heroicon-o-arrow-down-tray')
                            ->color('gray')
                            ->visible(is_array($archive))
                            ->url(fn (): ?string => is_array($archive) && ($household = Household::query()->find(self::householdId())) !== null
                                ? HouseholdArchiveReady::url($household, $archive['file'])
                                : null),
                    ]),
                    // One line, only on self-hosted servers that name the service.
                    Text::make(fn () => $hosted === '' ? '' : new HtmlString(
                        e(__('Rather not run a server?')).' <a class="underline" href="'.e($hosted).'">'.e(__('Move to Family Home Base Cloud')).'</a>'
                    ))->visible($hosted !== ''),
                ]),
            Section::make(__('Move a household here'))
                ->description(__('Upload the export of another Family Home Base server. It arrives as a new household next to this one; you need the same address there and here. Tablets pair again, connections are set up again.'))
                ->visible(! app(ReadOnlyAdmin::class)->applies())
                ->schema([
                    Text::make(match (true) {
                        ! is_array($import) => '',
                        $import['ok'] => __(':household has moved in. Switch to it in the menu at the top.', ['household' => $import['household']]),
                        default => __('The import failed: :message', ['message' => $import['message']]),
                    })->visible(is_array($import)),
                    Actions::make([
                        Action::make('import')
                            ->label(__('Upload export'))
                            ->icon('heroicon-o-arrow-up-tray')
                            ->schema([
                                FileUpload::make('file')
                                    ->label(__('Export (ZIP)'))
                                    ->disk('local')
                                    ->directory('imports')
                                    ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])
                                    ->maxSize((int) config('fahoba.import_max_mb', 12) * 1024)
                                    ->helperText(__('Up to :mb MB here; whoever runs this server imports bigger ones by command.', ['mb' => (int) config('fahoba.import_max_mb', 12)]))
                                    ->required(),
                            ])
                            ->action(function (array $data): void {
                                Cache::forget(ImportHouseholdArchiveJob::cacheKey((int) self::user()?->id));
                                ImportHouseholdArchiveJob::dispatch((string) $data['file'], (int) self::user()?->id);
                                Notification::make()->success()->title(__('The household is moving in.'))->send();
                            }),
                    ]),
                ]),
        ]);
    }

    private static function householdId(): int
    {
        return (int) app(HouseholdContext::class)->id();
    }

    private static function user(): ?User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : null;
    }
}

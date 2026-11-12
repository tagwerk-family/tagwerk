<?php

namespace App\Providers\Filament;

use App\Auth\ActiveHousehold;
use App\Filament\Auth\Login;
use App\Filament\NavigationGroup;
use App\Filament\Pages\Auth\EditProfile;
use App\Http\Middleware\AdminFromAppCookie;
use App\Http\Middleware\ApplyLocale;
use App\Http\Middleware\RedirectReadOnlyAdmin;
use App\Http\Middleware\ResolveHousehold;
use App\Http\Middleware\RestrictToAllowedNetworks;
use App\Integrations\Plugins\Extensions;
use App\Integrations\Plugins\PluginCatalog;
use App\Models\Household;
use App\Models\Membership;
use App\Models\User;
use App\Services\SupportAccessService;
use App\Support\HouseholdContext;
use App\Support\LocalDates;
use App\Support\OutgoingMail;
use Filament\Forms\Components\DateTimePicker;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        // Installed extensions set themselves up here, on or off: the routes
        // are cached at container start, the switch applies per request.
        foreach (app(Extensions::class)->installed() as $extension) {
            $extension->configurePanel($panel);
        }

        $panel = $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->emailChangeVerification(fn (): bool => OutgoingMail::enabled())
            ->profile(EditProfile::class, isSimple: false)
            // The bell: an account's stored messages (`HouseholdMessage`), polled.
            ->databaseNotifications();

        // Plugins' resources and pages, each hiding itself per household. After
        // id(): registering resources reads the component cache, keyed by the id.
        foreach (app(PluginCatalog::class)->offered('panel') as $class) {
            app($class)->configurePanel($panel);
        }

        // Built once at boot, like the cached routes: changing FAHOBA_MAIL needs a restart.
        if (OutgoingMail::enabled()) {
            $panel->passwordReset();
        }

        return FahobaBrand::apply($panel)
            ->navigationGroups(NavigationGroup::class)
            // Saved means done: back to the list instead of staying in the form.
            ->resourceCreatePageRedirect('index')
            ->resourceEditPageRedirect('index')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            // Persistent, so Livewire requests from the panel are held too.
            ->middleware([RestrictToAllowedNetworks::class], isPersistent: true)
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                // Signed in on the phone: no second sign-in for the admin.
                AdminFromAppCookie::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Persistent, so Livewire updates (saving a form) know the
            // household too — otherwise household_id is missing.
            ->renderHook(PanelsRenderHook::BODY_START, fn (): string => self::supportBanner())
            ->renderHook(PanelsRenderHook::BODY_START, fn (): string => self::householdNotice())
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): string => self::supportSwitcher())
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): string => self::householdSwitcher())
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): string => view('filament.admin.to-app')->render())
            ->authMiddleware([
                Authenticate::class,
                ResolveHousehold::class,
                RedirectReadOnlyAdmin::class,
                ApplyLocale::class,
            ], isPersistent: true);
    }

    /**
     * Dates as each language writes them. Closures, because the panel boots
     * before the request's language is known.
     */
    public function boot(): void
    {
        $date = fn (): string => LocalDates::phpDate();
        $dateTime = fn (): string => LocalDates::phpDateTime();

        Table::configureUsing(fn (Table $table): Table => $table
            ->defaultDateDisplayFormat($date)
            ->defaultDateTimeDisplayFormat($dateTime));

        DateTimePicker::configureUsing(fn (DateTimePicker $picker): DateTimePicker => $picker
            ->defaultDateDisplayFormat($date));
    }

    private static function supportBanner(): string
    {
        $household = Household::query()->find(app(SupportAccessService::class)->current(self::user()));

        return $household === null ? '' : view('filament.admin.support-banner', ['household' => $household])->render();
    }

    private static function householdNotice(): string
    {
        return Blade::render('@livewire(\\App\\Livewire\\HouseholdNoticeBar::class)');
    }

    /** Only the operator switches; everyone else has exactly one household. */
    private static function supportSwitcher(): string
    {
        $user = self::user();

        if ($user?->is_operator !== true) {
            return '';
        }

        return view('filament.admin.support-switcher', [
            'households' => Household::query()->orderBy('name')->pluck('name', 'id')->all(),
            'current' => app(SupportAccessService::class)->current($user) ?? $user->defaultMembership()?->household_id,
            'ownHousehold' => $user->defaultMembership()?->household_id,
        ])->render();
    }

    /** For someone who manages more than one household of their own. */
    private static function householdSwitcher(): string
    {
        $user = self::user();

        if ($user === null || $user->is_operator === true) {
            return '';
        }

        $managed = app(ActiveHousehold::class)->managed($user);

        if (count($managed) < 2) {
            return '';
        }

        return view('filament.admin.household-switcher', [
            'households' => collect($managed)->mapWithKeys(fn (Membership $membership): array => [
                (int) $membership->household_id => (string) $membership->household?->name,
            ])->all(),
            'current' => app(HouseholdContext::class)->id(),
        ])->render();
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}

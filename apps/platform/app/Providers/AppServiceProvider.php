<?php

namespace App\Providers;

use App\Auth\AdminLogoutResponse;
use App\Events\DomainEvents;
use App\Integrations\Plugins\CoreOutbound;
use App\Integrations\ProviderRegistry;
use App\Jobs\AnnounceHouseholdUpdateJob;
use App\Models\Checklist;
use App\Models\Event as CalendarEvent;
use App\Models\ListItem;
use App\Models\MealPlanEntry;
use App\Models\PersonalAccessToken;
use App\Models\PointEntry;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskCompletion;
use App\Models\User;
use App\Security\ContentSecurityPolicy;
use App\Support\Abilities;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Dashboard\ImagePolicy;
use Fahoba\PluginApi\Households\CurrentHousehold;
use Fahoba\PluginApi\Http\Outbound;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(HouseholdContext::class);
        $this->app->alias(HouseholdContext::class, CurrentHousehold::class);
        $this->app->bind(ImagePolicy::class, ContentSecurityPolicy::class);
        $this->app->bind(Outbound::class, CoreOutbound::class);

        // One registry per request (and per queued job): it memoizes created
        // providers, and those must not carry state across requests.
        $this->app->scoped(ProviderRegistry::class);
        $this->app->scoped(Abilities::class);

        $this->app->bind(LogoutResponse::class, AdminLogoutResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Using a reset or invitation link proves the address, so it counts as
         * verified — that also ends an invitation still open. Here rather than
         * in the controller: Filament's reset in /admin fires the same event.
         */
        Event::listen(PasswordReset::class, function (PasswordReset $event): void {
            if ($event->user instanceof User && $event->user->email_verified_at === null) {
                $event->user->forceFill(['email_verified_at' => now()])->save();
            }
        });

        /*
         * A limit for every outbound call, including plugins that set none.
         * Guzzle otherwise waits forever — and a hanging weather service
         * would freeze the wall.
         */
        Http::globalOptions(['timeout' => 20, 'connect_timeout' => 5]);

        Sanctum::usePersonalAccessTokenModel(
            PersonalAccessToken::class,
        );

        /*
         * A limiter of its own for PIN entry instead of "throttle:10,1": the
         * default keys by getAuthIdentifier(), and on the wall the
         * authenticated model is a Device — a model with a Sanctum token but
         * without Authenticatable. The key is therefore the device id.
         */
        RateLimiter::for('pin', fn (Request $request): Limit => Limit::perMinute(10)
            ->by((string) ($request->user()?->getKey() ?? $request->ip())));

        // Every link makes the server fetch a foreign page and its photo.
        RateLimiter::for('recipe-link', fn (Request $request): Limit => Limit::perMinute(10)
            ->by((string) ($request->user()?->getKey() ?? $request->ip())));

        /*
         * Login: throttled by email AND IP. Throttled by IP alone, an
         * attacker would lock out the whole family sharing one connection;
         * by email alone, trying many addresses would be unlimited.
         */
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(5)
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));

        /*
         * Pairing is public: a tablet has no login yet. A code has 40 bits
         * and lives 15 minutes, so guessing is hopeless anyway — the limit
         * keeps it that way and keeps the endpoint from being a free
         * database probe. Only misses count: setting up several tablets in a
         * row is no guessing.
         */
        RateLimiter::for('pair', fn (Request $request): Limit => Limit::perMinute(10)
            ->by((string) $request->ip())
            ->after(fn (Response $response): bool => ! $response->isSuccessful()));

        // The ticket allows five tries itself; this keeps fresh tickets from
        // adding up to a guessing run.
        RateLimiter::for('second-factor', fn (Request $request): Limit => Limit::perMinute(10)
            ->by((string) $request->ip()));

        // Every tap on a plugin tile's button runs plugin code that may call
        // a service; keyed by device or account, like the PIN.
        RateLimiter::for('widget-action', fn (Request $request): Limit => Limit::perMinute(30)
            ->by((string) ($request->user()?->getKey() ?? $request->ip())));

        // Asked once per start of the app, by address: nobody is signed in yet.
        RateLimiter::for('language', fn (Request $request): Limit => Limit::perMinute(60)
            ->by((string) $request->ip()));

        // A page with a broken policy reports every blocked load; a few per
        // minute and address tell the story.
        RateLimiter::for('csp', fn (Request $request): Limit => Limit::perMinute(20)
            ->by((string) $request->ip()));

        $this->announceChanges();
        DomainEvents::register();
    }

    /**
     * Changes to domain data are announced to the devices.
     *
     * Through model events and not at the write sites: there are a dozen of
     * those by now, spread across controllers, services and the Google sync.
     * A forgotten one would only show as a tablet staying stale until the
     * next poll — so hardly at all.
     */
    private function announceChanges(): void
    {
        $areas = [
            CalendarEvent::class => 'agenda',
            Task::class => 'tasks',
            TaskCompletion::class => 'tasks',
            TaskAssignment::class => 'tasks',
            Checklist::class => 'lists',
            ListItem::class => 'lists',
            PointEntry::class => 'points',
            Reward::class => 'points',
            RewardRedemption::class => 'points',
            MealPlanEntry::class => 'meals',
        ];

        foreach ($areas as $model => $area) {
            foreach (['saved', 'deleted'] as $event) {
                $model::$event(function (Model $record) use ($area): void {
                    if ($record->household_id !== null) {
                        // A moment's delay gathers a burst into one announcement.
                        AnnounceHouseholdUpdateJob::dispatch((int) $record->household_id, $area)->delay(2);
                    }
                });
            }
        }
    }
}

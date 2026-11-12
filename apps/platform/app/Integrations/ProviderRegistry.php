<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Enums\ProviderArea;
use App\Exceptions\UnknownProvider;
use App\Integrations\Plugins\PluginCatalog;
use App\Models\ProviderAccount;
use Fahoba\PluginApi\Calendar\CalendarProvider;
use Fahoba\PluginApi\Connections\UsesCredentials;
use Fahoba\PluginApi\Connections\UsesOAuth;
use Fahoba\PluginApi\Geocoding\Geocoder;
use Fahoba\PluginApi\Holidays\HolidaySource;
use Fahoba\PluginApi\Lists\ListProvider;
use Fahoba\PluginApi\Photos\PhotoSource;
use Fahoba\PluginApi\Recipes\RecipeProvider;
use Fahoba\PluginApi\SchoolHolidays\SchoolHolidaySource;
use Fahoba\PluginApi\Weather\WeatherSource;
use Illuminate\Contracts\Container\Container;

/**
 * Finds the provider for a key.
 *
 * One method per domain area instead of a generic one: only that way does the
 * call site show which contract comes back — and the domain services can ask
 * for capabilities without checking the return value.
 *
 * Built-in providers live in `config/fahoba.php`, installed plugins come in
 * through the `PluginCatalog`. A provider is only instantiated when someone
 * asks for it, and then only once per request: a plugin with an expensive
 * constructor should not slow down every request that does not need it.
 */
final class ProviderRegistry
{
    /** @var array<string, object> */
    private array $instances = [];

    public function __construct(
        private readonly Container $container,
        private readonly PluginCatalog $plugins,
    ) {}

    public function calendarProvider(string $key): CalendarProvider
    {
        /** @var CalendarProvider */
        return $this->resolve('calendar', $key);
    }

    /**
     * All providers of an area.
     *
     * @return array<string, object>
     */
    public function providers(ProviderArea $area): array
    {
        return $this->all($area->value);
    }

    /**
     * The provider that requires an authorization in an area.
     *
     * A provider without an authorization has no business on the connection
     * routes: the built-in calendar, for instance, comes with the household
     * and is never connected.
     */
    public function oauthProvider(ProviderArea $area, string $key): UsesOAuth
    {
        $provider = $this->resolve($area->value, $key);

        return $provider instanceof UsesOAuth
            ? $provider
            : throw new UnknownProvider($area->value, $key);
    }

    /**
     * All providers of an area that can be connected.
     *
     * Through an authorization or through credentials — for the wizard that
     * is the same process with two different middle parts. Built-in providers
     * are missing here: they come with the household and there is nothing to
     * connect.
     *
     * @return array<string, object>
     */
    public function connectableProviders(ProviderArea $area): array
    {
        return array_filter(
            $this->all($area->value),
            static fn (object $provider): bool => $provider instanceof UsesOAuth
                || $provider instanceof UsesCredentials,
        );
    }

    /**
     * The provider behind a connection — in the area it applies to.
     */
    public function forAccount(ProviderAccount $account): object
    {
        return $this->resolve($account->area->value, (string) $account->provider);
    }

    public function calendarFor(ProviderAccount $account): CalendarProvider
    {
        /** @var CalendarProvider */
        return $this->forAccount($account);
    }

    /** @return array<string, CalendarProvider> */
    public function calendarProviders(): array
    {
        /** @var array<string, CalendarProvider> */
        return $this->all('calendar');
    }

    /**
     * The keys of the calendar providers that bring a capability.
     *
     * That way the background runs filter in the query already instead of
     * queueing a job per calendar that finds nothing to do.
     *
     * @param  class-string  $capability
     * @return array<int, string>
     */
    public function calendarKeysWith(string $capability): array
    {
        $keys = [];

        foreach ($this->calendarProviders() as $key => $provider) {
            if ($provider instanceof $capability) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public function listProvider(string $key): ListProvider
    {
        /** @var ListProvider */
        return $this->resolve('lists', $key);
    }

    /** @return array<string, ListProvider> */
    public function listProviders(): array
    {
        /** @var array<string, ListProvider> */
        return $this->all('lists');
    }

    public function recipeProvider(string $key): RecipeProvider
    {
        /** @var RecipeProvider */
        return $this->resolve('recipes', $key);
    }

    /**
     * The recipe sources that are actually configured.
     *
     * Several instead of one: the built-in book is always there, and whoever
     * also maintains Tandoor should not have to choose which of the two
     * disappears. Which source a planned meal belongs to is stored on the
     * entry.
     *
     * @return array<string, RecipeProvider>
     */
    public function recipeProviders(): array
    {
        return array_filter(
            $this->all('recipes'),
            static fn (object $provider): bool => $provider instanceof RecipeProvider
                && $provider->isConfigured(),
        );
    }

    /**
     * All photo sources, configured or not.
     *
     * Unlike recipes there is no filtering here: whether a source has pictures
     * depends on the household's account — which the registry does not know.
     * The photo library decides that.
     *
     * @return array<string, PhotoSource>
     */
    public function photoSources(): array
    {
        /** @var array<string, PhotoSource> */
        return $this->all('photos');
    }

    /**
     * The weather source that is configured.
     *
     * As with the geocoder: a household asks for the weather in one place,
     * and offering a choice would be a question without any benefit.
     */
    public function weatherSource(): ?WeatherSource
    {
        foreach ($this->all('weather') as $provider) {
            if ($provider instanceof WeatherSource && $provider->isConfigured()) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * The first holiday source that knows the region.
     *
     * Plugins come first: whoever adds a more precise source for their country
     * wants it asked before Yasumi.
     */
    public function holidaySource(string $region): ?HolidaySource
    {
        foreach ($this->all('holidays') as $provider) {
            if ($provider instanceof HolidaySource && $provider->supports($region)) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * All sources for holiday suggestions.
     *
     * Several at once: the built-in one computes from the region, a connected
     * ICS calendar delivers what the school publishes — the list shows both,
     * and only what someone ticks gets created.
     *
     * @return array<string, SchoolHolidaySource>
     */
    public function schoolHolidaySources(): array
    {
        /** @var array<string, SchoolHolidaySource> */
        return $this->all('school_holidays');
    }

    /**
     * The geocoder that is configured.
     *
     * As with the recipe provider: a household searches addresses in one
     * place, and offering a choice would be a question without any benefit.
     */
    public function geocoder(): ?Geocoder
    {
        foreach ($this->all('geocoding') as $provider) {
            if ($provider instanceof Geocoder && $provider->isConfigured()) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * All providers of an area, built-in and installed.
     *
     * Public for the contract tests; domain logic uses the named methods
     * above so the contract is visible at the call site.
     *
     * @return array<string, object>
     */
    public function all(string $family): array
    {
        $providers = [];

        foreach (array_keys($this->family($family)) as $key) {
            $providers[(string) $key] = $this->resolve($family, (string) $key);
        }

        return $providers;
    }

    private function resolve(string $family, string $key): object
    {
        $class = $this->family($family)[$key] ?? null;

        if (! is_string($class)) {
            throw new UnknownProvider($family, $key);
        }

        return $this->instances["{$family}:{$key}"] ??= $this->container->make($class);
    }

    /**
     * Built-in ones last: with the same key the core would win. In practice
     * that does not happen, because plugin keys carry a vendor.
     *
     * @return array<string, mixed>
     */
    private function family(string $family): array
    {
        return [
            ...$this->plugins->offered($family),
            ...(array) config("fahoba.providers.{$family}", []),
        ];
    }
}

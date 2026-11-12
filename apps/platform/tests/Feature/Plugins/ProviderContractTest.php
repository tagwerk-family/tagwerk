<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Integrations\Plugins\PluginCatalog;
use App\Integrations\ProviderContracts;
use App\Integrations\ProviderRegistry;
use Fahoba\PluginApi\Connections\DescribesSetup;
use Fahoba\PluginApi\Connections\UsesCredentials;
use Fahoba\PluginApi\Connections\UsesOAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Every registered provider against the rules that apply to all of them.
 *
 * Runs through the registry, not a fixed list: a new provider — builtin or
 * from a plugin — is covered without anyone adding it here.
 */
final class ProviderContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Areas that manage without a builtin provider. Lists live in Fahoba
     * anyway; their providers only additionally push outwards.
     */
    private const WITHOUT_BUILTIN = ['lists'];

    /** @return array<string, array<string, object>> */
    private function byArea(ProviderRegistry $registry): array
    {
        $areas = [];

        foreach (array_keys((array) config('fahoba.providers')) as $area) {
            $areas[$area] = $registry->all($area);
        }

        return $areas;
    }

    public function test_every_area_has_a_contract(): void
    {
        foreach (array_keys((array) config('fahoba.providers')) as $area) {
            $this->assertArrayHasKey($area, ProviderContracts::BASE, "Area {$area} without contract.");
        }
    }

    public function test_every_provider_fulfils_the_contract_of_its_area(): void
    {
        foreach ($this->byArea(app(ProviderRegistry::class)) as $area => $providers) {
            foreach ($providers as $key => $provider) {
                $this->assertInstanceOf(ProviderContracts::BASE[$area], $provider, "{$area}/{$key}");
            }
        }
    }

    public function test_the_key_in_the_provider_matches_the_entry(): void
    {
        foreach ($this->byArea(app(ProviderRegistry::class)) as $area => $providers) {
            foreach ($providers as $key => $provider) {
                if (method_exists($provider, 'key')) {
                    $this->assertSame($key, $provider->key(), "{$area}/{$key}");
                }

                $this->assertNotSame('', trim($provider->label()), "{$area}/{$key} without name");
            }
        }
    }

    /**
     * A provider is constructed on every request that touches its area.
     * Network or database access in the constructor would slow them all down.
     */
    public function test_no_provider_calls_out_when_constructed(): void
    {
        Http::preventStrayRequests();
        DB::enableQueryLog();

        $fresh = new ProviderRegistry(app(), app(PluginCatalog::class));
        $this->byArea($fresh);

        $this->assertSame([], DB::getQueryLog());
    }

    public function test_whoever_offers_a_connection_describes_it_completely(): void
    {
        foreach ($this->byArea(app(ProviderRegistry::class)) as $area => $providers) {
            foreach ($providers as $key => $provider) {
                $name = "{$area}/{$key}";

                if ($provider instanceof UsesOAuth) {
                    $this->assertNotSame([], $provider->scopes(), "{$name}: OAuth without scopes");
                }

                // No fields is fine only for a connection that asks nothing (Koch-Wiki).
                if ($provider instanceof UsesCredentials && $provider->credentialFields() === []) {
                    $this->assertNull($provider->verifyCredentials([]), "{$name}: credentials without fields");
                }

                if (($provider instanceof UsesOAuth || $provider instanceof UsesCredentials)
                    && ! $provider instanceof DescribesSetup) {
                    $this->fail("{$name}: connectable, but without a description for the wizard");
                }

                if ($provider instanceof DescribesSetup) {
                    $this->assertNotSame('', trim($provider->icon()), "{$name}: without icon");
                    $this->assertNotSame('', trim($provider->description()), "{$name}: without description");
                    $this->assertContainsOnlyString($provider->setupNotes(), $name);
                }
            }
        }
    }

    /** The guiding rule: every area works without an external service. */
    public function test_every_area_has_a_provider_without_external_account(): void
    {
        foreach ($this->byArea(app(ProviderRegistry::class)) as $area => $providers) {
            if (in_array($area, self::WITHOUT_BUILTIN, true)) {
                continue;
            }

            $withoutAccount = array_filter(
                $providers,
                static fn (object $provider): bool => ! $provider instanceof UsesOAuth
                    && ! $provider instanceof UsesCredentials,
            );

            $this->assertNotSame([], $withoutAccount, "Area {$area} needs a provider without an external account.");
        }
    }
}

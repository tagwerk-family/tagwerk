<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Integrations\Plugins\PluginCatalog;
use App\Integrations\Plugins\PluginLoader;
use App\Integrations\Plugins\PluginManifest;
use Fahoba\PluginApi\Plugins\LoadReport;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\Plugins\FailsInBoot;
use Tests\Fixtures\Plugins\FailsInRegister;
use Tests\Fixtures\Plugins\GoodPlugin;
use Tests\Fixtures\Plugins\NotAPlugin;
use Tests\TestCase;

/** A broken plugin is left out and logged; the app keeps running. */
final class PluginLoaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/plugin-manifest-'.uniqid());
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_a_working_plugin_offers_what_it_brings(): void
    {
        $this->load(['acme/good' => GoodPlugin::class]);

        $this->assertFalse(app(LoadReport::class)->failed('acme/good'));
        $this->assertArrayHasKey('acme/good', app(PluginCatalog::class)->offered('photo_rules'));
    }

    public function test_a_missing_provider_class_is_left_out(): void
    {
        $this->load(['acme/missing' => 'Acme\\Missing\\Provider']);

        $this->assertTrue(app(LoadReport::class)->failed('acme/missing'));
        $this->assertStringContainsString('Acme\\Missing\\Provider', app(LoadReport::class)->failures()['acme/missing']);
    }

    public function test_a_provider_that_is_no_plugin_is_left_out(): void
    {
        $this->load(['acme/laravel' => NotAPlugin::class]);

        $this->assertStringContainsString('must extend', app(LoadReport::class)->failures()['acme/laravel'] ?? '');
    }

    public function test_an_error_while_registering_withdraws_the_plugin(): void
    {
        $this->load(['acme/half' => FailsInRegister::class]);

        $this->assertSame('Missing configuration.', app(LoadReport::class)->failures()['acme/half'] ?? null);
        $this->assertArrayNotHasKey('acme/half', app(PluginCatalog::class)->offered('photo_rules'));
    }

    public function test_an_error_while_booting_withdraws_what_was_already_offered(): void
    {
        $this->load(['acme/late' => FailsInBoot::class]);

        $this->assertSame('Cannot reach the service.', app(LoadReport::class)->failures()['acme/late'] ?? null);
        $this->assertArrayNotHasKey('acme/late', app(PluginCatalog::class)->offered('photo_rules'));
    }

    /** Laravel's package discovery registers a provider still named in `extra.laravel`. */
    public function test_a_plugin_registered_without_the_loader_is_left_out(): void
    {
        $this->app->register(new GoodPlugin($this->app));

        $this->assertStringContainsString('not loaded by Fahoba', app(LoadReport::class)->failures()[GoodPlugin::class] ?? '');
        $this->assertArrayNotHasKey('acme/good', app(PluginCatalog::class)->offered('photo_rules'));
    }

    public function test_the_bundled_plugins_are_loaded_from_composer(): void
    {
        $names = array_column(app(PluginManifest::class)->packages(), 'name');

        $this->assertContains('fahoba/bring', $names);
        $this->assertNotContains('laravel/framework', $names);
        $this->assertSame([], app(LoadReport::class)->failures());
        $this->assertArrayHasKey('fahoba/bring', app(PluginCatalog::class)->offered('lists'));
    }

    public function test_the_manifest_lists_packages_with_a_fahoba_provider(): void
    {
        $installed = $this->directory.'/installed.json';
        $this->writeInstalled($installed, ['acme/a' => GoodPlugin::class]);
        $manifest = new PluginManifest($installed, $this->directory.'/cache.php');

        $this->assertSame([[
            'name' => 'acme/a',
            'version' => '1.2.3',
            'license' => ['MIT'],
            'provider' => GoodPlugin::class,
            'frontend' => false,
            'locales' => null,
            'complete_for' => null,
            'csp' => [],
        ]], $manifest->packages());
    }

    public function test_the_manifest_reads_the_hosts_a_plugin_declares(): void
    {
        $installed = $this->directory.'/installed.json';
        file_put_contents($installed, json_encode(['packages' => [[
            'name' => 'acme/map',
            'version' => '1.0.0',
            'extra' => ['fahoba' => ['provider' => GoodPlugin::class, 'csp' => ['img-src' => ['https://tiles.example.org']]]],
        ]]]));

        $packages = (new PluginManifest($installed, $this->directory.'/cache.php'))->packages();

        $this->assertSame(['img-src' => ['https://tiles.example.org']], $packages[0]['csp']);
    }

    /** A malformed entry is the plugin's mistake: it is left out, Fahoba keeps running. */
    public function test_a_malformed_csp_entry_leaves_only_that_plugin_out(): void
    {
        $installed = $this->directory.'/installed.json';
        file_put_contents($installed, json_encode(['packages' => [
            ['name' => 'acme/odd', 'version' => '1.0.0', 'extra' => ['fahoba' => ['provider' => GoodPlugin::class, 'csp' => ['img-src' => [['https://a.example.org']]]]]],
            ['name' => 'acme/flat', 'version' => '1.0.0', 'extra' => ['fahoba' => ['provider' => GoodPlugin::class, 'csp' => 'https://a.example.org']]],
        ]]));

        $packages = (new PluginManifest($installed, $this->directory.'/cache.php'))->packages();
        app(PluginLoader::class)->load($this->app, $packages);

        $this->assertStringContainsString('extra.fahoba.csp', app(LoadReport::class)->failures()['acme/odd'] ?? '');
        $this->assertStringContainsString('extra.fahoba.csp', app(LoadReport::class)->failures()['acme/flat'] ?? '');
    }

    /** A cache written by an older release lacks fields; it is read again, not trusted. */
    public function test_a_cache_of_an_older_shape_is_rebuilt(): void
    {
        $installed = $this->directory.'/installed.json';
        $cache = $this->directory.'/cache.php';
        $this->writeInstalled($installed, ['acme/a' => GoodPlugin::class]);
        file_put_contents($cache, '<?php return '.var_export(['modified' => filemtime($installed), 'packages' => [['name' => 'acme/a']]], true).';');

        $this->assertSame([], (new PluginManifest($installed, $cache))->packages()[0]['csp']);
    }

    public function test_the_manifest_is_rebuilt_when_composer_changed_something(): void
    {
        $installed = $this->directory.'/installed.json';
        $cache = $this->directory.'/cache.php';
        $this->writeInstalled($installed, ['acme/a' => GoodPlugin::class]);
        (new PluginManifest($installed, $cache))->packages();

        $this->writeInstalled($installed, ['acme/a' => GoodPlugin::class, 'acme/b' => GoodPlugin::class]);
        touch($installed, time() + 5);
        clearstatcache();

        $this->assertSame(['acme/a', 'acme/b'], array_column((new PluginManifest($installed, $cache))->packages(), 'name'));
    }

    /** @param array<string, string> $providers package => provider class */
    private function load(array $providers): void
    {
        $packages = [];

        foreach ($providers as $name => $provider) {
            $packages[] = ['name' => $name, 'version' => '1.0.0', 'license' => ['MIT'], 'provider' => $provider, 'frontend' => false];
        }

        app(PluginLoader::class)->load($this->app, $packages);
    }

    /** @param array<string, string> $providers */
    private function writeInstalled(string $path, array $providers): void
    {
        $packages = [['name' => 'laravel/framework', 'version' => '13.0.0', 'license' => ['MIT']]];

        foreach ($providers as $name => $provider) {
            $packages[] = ['name' => $name, 'version' => '1.2.3', 'license' => ['MIT'], 'extra' => ['fahoba' => ['provider' => $provider]]];
        }

        file_put_contents($path, json_encode(['packages' => $packages]));
    }
}

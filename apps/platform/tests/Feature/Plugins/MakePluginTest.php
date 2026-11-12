<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Integrations\Plugins\PluginCatalog;
use App\Integrations\Plugins\PluginLoader;
use App\Support\Designs;
use App\Support\Locale;
use Composer\Autoload\ClassLoader;
use Fahoba\PluginApi\Plugins\LoadReport;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** `fahoba:make-plugin` writes a package that loads as it is. */
final class MakePluginTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/make-plugin-'.uniqid());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_a_widget_plugin_loads_and_offers_its_widget(): void
    {
        $this->generate('acme/waste', ['--widget' => true]);

        $this->assertFileExists($this->directory.'/src/WastePlugin.php');
        $this->assertFileExists($this->directory.'/tests/WasteTest.php');
        $this->assertArrayHasKey('acme/waste', app(PluginCatalog::class)->offered('widgets'));
    }

    /** Every provider area: the stub implements the area's base contract. */
    public function test_a_provider_plugin_loads_for_every_area(): void
    {
        foreach (['calendar', 'lists', 'tasks', 'recipes', 'photos', 'geocoding', 'weather', 'holidays', 'school_holidays'] as $index => $area) {
            $name = "acme/source-{$index}";
            $this->directory = storage_path("framework/testing/make-plugin-{$index}-".uniqid());

            $this->generate($name, ['--provider' => $area]);

            $this->assertArrayHasKey($name, app(PluginCatalog::class)->offered($area), $area);
            $this->assertInert(app(app(PluginCatalog::class)->offered($area)[$name]), $area);
            File::deleteDirectory($this->directory);
        }
    }

    public function test_an_area_plugin_brings_a_route(): void
    {
        $this->generate('acme/board', ['--area' => true]);

        $this->assertArrayHasKey('acme/board', app(PluginCatalog::class)->offered('areas'));
        $this->assertFileExists($this->directory.'/routes/api.php');
    }

    public function test_a_language_plugin_starts_from_the_english_reference(): void
    {
        $this->generate('acme/french', ['--language' => 'fr']);

        $this->assertArrayHasKey('acme/french', app(PluginCatalog::class)->offered('languages'));
        $this->assertSame('Français', Locale::supported()['fr']);
        $this->assertFileExists($this->directory.'/lang/fr/validation.php');

        $composer = json_decode((string) file_get_contents($this->directory.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('MIT', $composer['license']);
        $this->assertArrayHasKey('complete-for', $composer['extra']['fahoba']['language']);
    }

    public function test_a_theme_plugin_passes_the_checks_it_ships_with(): void
    {
        $this->generate('acme/violet', ['--theme' => true]);

        $this->assertArrayHasKey('acme/violet', app(PluginCatalog::class)->offered('themes'));
        $this->assertNotNull(app(Designs::class)->get('acme/violet'));

        $composer = json_decode((string) file_get_contents($this->directory.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('MIT', $composer['license']);
    }

    public function test_names_and_kinds_are_checked(): void
    {
        $this->artisan('fahoba:make-plugin', ['name' => 'acme/german', '--language' => 'de', '--path' => $this->directory])->assertFailed();
        $this->artisan('fahoba:make-plugin', ['name' => 'acme/french', '--language' => 'french', '--path' => $this->directory])->assertFailed();
        $this->artisan('fahoba:make-plugin', ['name' => 'Waste', '--widget' => true, '--path' => $this->directory])->assertFailed();
        $this->artisan('fahoba:make-plugin', ['name' => 'fahoba/waste', '--widget' => true, '--path' => $this->directory])->assertFailed();
        $this->artisan('fahoba:make-plugin', ['name' => 'acme/waste', '--widget' => true, '--area' => true, '--path' => $this->directory])->assertFailed();
        $this->artisan('fahoba:make-plugin', ['name' => 'acme/waste', '--provider' => 'pets', '--path' => $this->directory])->assertFailed();

        $this->assertDirectoryDoesNotExist($this->directory);
    }

    /**
     * Installed means enabled: a fresh stub must answer “not ready” where
     * Fahoba asks every installed provider, never throw there.
     */
    private function assertInert(object $provider, string $area): void
    {
        foreach (['isConfigured', 'supports', 'isAvailable'] as $method) {
            if (method_exists($provider, $method)) {
                $arguments = array_map(
                    static fn (\ReflectionParameter $parameter): ?string => $parameter->allowsNull() ? null : 'AT-9',
                    (new \ReflectionMethod($provider, $method))->getParameters(),
                );

                $this->assertFalse($provider->{$method}(...$arguments), "{$area}: {$method}");
            }
        }
    }

    /**
     * What an author gets runs in its own repository: its tests pass with
     * only the API on the autoloader, CI and agent notes come along.
     */
    public function test_the_generated_package_tests_itself(): void
    {
        foreach (['acme/waste' => ['--widget' => true], 'acme/board' => ['--area' => true], 'acme/cal' => ['--provider' => 'calendar'], 'acme/french' => ['--language' => 'fr'], 'acme/violet' => ['--theme' => true]] as $name => $options) {
            $this->directory = storage_path('framework/testing/make-plugin-own-'.uniqid());
            $this->artisan('fahoba:make-plugin', ['name' => $name, '--path' => $this->directory, ...$options])->assertSuccessful();

            foreach (['.github/workflows/tests.yml', 'phpunit.xml.dist', 'AGENTS.md', '.gitignore'] as $file) {
                $this->assertFileExists($this->directory.'/'.$file, "{$name}: {$file}");
            }

            $composer = json_decode((string) file_get_contents($this->directory.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
            $psr4 = '';
            foreach ([...$composer['autoload']['psr-4'], ...$composer['autoload-dev']['psr-4']] as $namespace => $path) {
                $psr4 .= '$loader->addPsr4('.var_export($namespace, true).', __DIR__.'.var_export('/'.$path, true).');';
            }
            file_put_contents($this->directory.'/bootstrap.php', '<?php $loader = require '.var_export(base_path('vendor/autoload.php'), true).';'.$psr4);

            exec(sprintf(
                'cd %s && %s --bootstrap bootstrap.php --configuration phpunit.xml.dist 2>&1',
                escapeshellarg($this->directory),
                escapeshellarg(base_path('vendor/bin/phpunit')),
            ), $output, $code);

            $this->assertSame(0, $code, $name.":\n".implode("\n", $output));
            $this->assertStringContainsString('OK (2 tests', implode("\n", $output), $name);
            File::deleteDirectory($this->directory);
        }
    }

    /**
     * `templates/plugin` becomes the GitHub template repository on release.
     * It is the generator's output, so it never drifts from it. To refresh:
     * delete it and run `fahoba:make-plugin acme/example --widget --path=templates/plugin`.
     */
    public function test_the_template_repository_is_what_the_generator_writes(): void
    {
        $this->artisan('fahoba:make-plugin', ['name' => 'acme/example', '--widget' => true, '--path' => $this->directory])->assertSuccessful();

        $files = fn (string $root): array => collect(File::allFiles($root, hidden: true))
            ->mapWithKeys(fn (\SplFileInfo $file): array => [str_replace($root.'/', '', $file->getPathname()) => File::get($file->getPathname())])
            ->sortKeys()
            ->all();

        $this->assertSame($files($this->directory), $files(base_path('templates/plugin')));
    }

    public function test_a_segment_must_start_with_a_letter(): void
    {
        $this->artisan('fahoba:make-plugin', ['name' => 'acme/2fa', '--widget' => true, '--path' => $this->directory])->assertFailed();
    }

    /** @param  array<string, mixed>  $options */
    private function generate(string $name, array $options): void
    {
        $this->artisan('fahoba:make-plugin', ['name' => $name, '--path' => $this->directory, ...$options])
            ->expectsOutputToContain("composer require {$name}")
            ->assertSuccessful();

        $composer = json_decode((string) file_get_contents($this->directory.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('^0.1', $composer['require']['fahoba/plugin-api']);

        $loader = new ClassLoader;
        foreach ($composer['autoload']['psr-4'] as $namespace => $path) {
            $loader->addPsr4($namespace, $this->directory.'/'.$path);
        }
        $loader->register();

        app(PluginLoader::class)->load($this->app, [[
            'name' => $name,
            'provider' => $composer['extra']['fahoba']['provider'],
        ]]);

        $this->assertSame([], app(LoadReport::class)->failures());
        $this->assertSame(0, $this->lint());
    }

    /** Every generated PHP file parses. */
    private function lint(): int
    {
        $failures = 0;

        foreach (File::allFiles($this->directory) as $file) {
            if ($file->getExtension() === 'php') {
                exec('php -l '.escapeshellarg($file->getPathname()).' 2>&1', $output, $code);
                $failures += $code === 0 ? 0 : 1;
            }
        }

        return $failures;
    }
}

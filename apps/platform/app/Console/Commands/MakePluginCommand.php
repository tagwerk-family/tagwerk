<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Integrations\ProviderContracts;
use Fahoba\PluginApi\Languages\Coverage;
use Fahoba\PluginApi\Languages\Reference;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Locale;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use UnitEnum;

/**
 * Writes a plugin package that loads as it is: composer.json with
 * `extra.fahoba`, the plugin class, one widget, provider or area, a PHPUnit
 * test and a README. A provider's methods come from its area's contract, so
 * the stub never drifts from the API.
 */
final class MakePluginCommand extends Command
{
    protected $signature = 'fahoba:make-plugin
        {name : vendor/name, e.g. acme/waste}
        {--widget : A dashboard widget}
        {--provider= : A provider for an area: '.'calendar, lists, tasks, recipes, photos, geocoding, weather, holidays, school_holidays}
        {--area : An area with a menu switch and an API route}
        {--language= : A language pack for this ISO 639-1 code, e.g. fr}
        {--theme : A design pack: colours, font and corners}
        {--path= : Where to write it (default: plugins/<name>)}';

    protected $description = 'Writes a new Fahoba plugin package';

    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if (preg_match('#^[a-z]([a-z0-9-]*[a-z0-9])?/[a-z]([a-z0-9-]*[a-z0-9])?$#', $name) !== 1) {
            $this->error('The name is vendor/name in lower case, each part starting with a letter, e.g. acme/waste.');

            return self::FAILURE;
        }

        if (str_starts_with($name, 'fahoba/')) {
            $this->error('The vendor fahoba/ is reserved for the bundled plugins. Use your own.');

            return self::FAILURE;
        }

        $provider = $this->option('provider');
        $language = $this->option('language');
        $kinds = array_filter([$this->option('widget'), $provider !== null, $this->option('area'), $language !== null, $this->option('theme')]);

        if (count($kinds) !== 1) {
            $this->error('Choose exactly one of --widget, --provider=<area>, --area, --language=<code> or --theme.');

            return self::FAILURE;
        }

        if ($language !== null && (preg_match('/^[a-z]{2}$/', $language) !== 1 || in_array($language, Coverage::BUILT_IN, true))) {
            $this->error('The language is a two-letter ISO 639-1 code other than '.implode(' and ', Coverage::BUILT_IN).', e.g. fr.');

            return self::FAILURE;
        }

        if ($provider !== null && ! isset(ProviderContracts::BASE[$provider])) {
            $this->error('Unknown area '.$provider.'. Known: '.implode(', ', array_keys(ProviderContracts::BASE)).'.');

            return self::FAILURE;
        }

        $path = (string) ($this->option('path') ?: base_path('plugins/'.Str::after($name, '/')));

        if (File::exists($path)) {
            $this->error("{$path} exists already.");

            return self::FAILURE;
        }

        [$vendor, $package] = explode('/', $name);
        $namespace = Str::studly($vendor).'\\'.Str::studly($package);
        $class = Str::studly($package);

        [$area, $offered, $files, $boot] = match (true) {
            $this->option('widget') => ['widgets', "{$class}Widget", ["src/{$class}Widget.php" => $this->stub('widget')], ''],
            $language !== null => ['languages', "{$class}Language", [
                "src/{$class}Language.php" => str_replace(
                    ['{{ code }}', '{{ language }}'],
                    [$language, $this->languageName($language)],
                    $this->stub('language'),
                ),
                ...$this->texts($language),
            ], ''],
            $this->option('theme') => ['themes', "{$class}Theme", [
                "src/{$class}Theme.php" => $this->stub('theme'),
                'resources/theme.css' => $this->stub('theme-css'),
            ], ''],
            $this->option('area') => ['areas', "{$class}Area", [
                "src/{$class}Area.php" => $this->stub('area'),
                'routes/api.php' => $this->stub('routes'),
            ], "\n    protected function bootPlugin(): void\n    {\n        PluginRoutes::api(self::KEY, fn () => require __DIR__.'/../routes/api.php');\n    }\n"],
            default => [$provider, $class.Str::studly($provider), [
                "src/{$class}".Str::studly($provider).'.php' => $this->provider(ProviderContracts::BASE[$provider], $class.Str::studly($provider)),
            ], ''],
        };

        $files["src/{$class}Plugin.php"] = str_replace(
            ['{{ uses }}', '{{ boot }}', '{{ area }}', '{{ offered }}'],
            [$boot === '' ? '' : "use Fahoba\\PluginApi\\Plugins\\PluginRoutes;\n", $boot, $area, $offered],
            $this->stub('plugin'),
        );
        $files["tests/{$class}Test.php"] = str_replace(['{{ checks }}', '{{ tested }}'], [$this->checks($area), $offered], $this->stub('test'));
        $files['README.md'] = $this->stub('readme');
        $files['AGENTS.md'] = $this->stub('agents');
        $files['phpunit.xml.dist'] = $this->stub('phpunit');
        $files['.gitignore'] = $this->stub('gitignore');
        $files['.github/workflows/tests.yml'] = $this->stub('ci');
        $files['composer.json'] = $this->composer($name, $namespace, $class, $language !== null, $this->option('theme'));

        foreach ($files as $file => $content) {
            File::ensureDirectoryExists(dirname($path.'/'.$file));
            File::put($path.'/'.$file, str_replace(
                ['{{ namespace }}', '{{ class }}', '{{ name }}', '{{ title }}'],
                [$namespace, $class, $name, Str::headline($package)],
                $content,
            ));
        }

        $this->info("Written to {$path}.");
        $this->line("Next: composer require {$name}");

        return self::SUCCESS;
    }

    /** A widget, a language and a design get the testkit's full check; anything else names itself. */
    private function checks(string $area): string
    {
        $test = match ($area) {
            'widgets' => "    public function test_the_widget_works(): void\n    {\n        \$this->assertWidgetWorks(new {{ tested }});\n    }\n",
            'languages' => "    public function test_every_text_is_translated(): void\n    {\n        \$this->assertLanguagePackComplete(new {{ tested }});\n    }\n",
            'themes' => "    public function test_the_design_is_valid(): void\n    {\n        \$this->assertThemeValid(new {{ tested }});\n    }\n",
            default => "    public function test_it_names_itself(): void\n    {\n        \$this->assertNotSame('', (new {{ tested }})->label());\n    }\n",
        };

        return "\n".$test;
    }

    /**
     * The English reference as the pack's starting texts: translators change
     * values, the keys stay.
     *
     * @return array<string, string>
     */
    private function texts(string $code): array
    {
        $reference = Reference::load();
        $files = [
            "lang/{$code}.json" => json_encode($reference['backend'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
            "frontend/{$code}.json" => json_encode(Arr::undot($reference['frontend']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
        ];

        foreach ($reference['groups'] as $group => $texts) {
            $files["lang/{$code}/{$group}.php"] = "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export(Arr::undot($texts), true).";\n";
        }

        return $files;
    }

    /** The language's name in itself, from ICU; the code where ICU has none. */
    private function languageName(string $code): string
    {
        $name = Locale::getDisplayLanguage($code, $code);

        return $name === '' || $name === $code ? $code : mb_convert_case($name, MB_CASE_TITLE);
    }

    private function stub(string $name): string
    {
        return File::get(base_path("stubs/plugin/{$name}.stub"));
    }

    private function composer(string $name, string $namespace, string $class, bool $language = false, bool $theme = false): string
    {
        $fahoba = ['provider' => $namespace.'\\'.$class.'Plugin'];

        if ($language) {
            // Shown next to the running version under Operations → Plugins.
            $fahoba['language'] = ['complete-for' => (string) config('app.version')];
        }

        return json_encode([
            'name' => $name,
            'description' => match (true) {
                $language => 'A language for Fahoba',
                $theme => 'A design for Fahoba',
                default => 'A Fahoba plugin',
            },
            'type' => 'library',
            'version' => '0.1.0',
            // A pack is texts or tokens only, MIT keeps it open; otherwise left for the author (README).
            'license' => $language || $theme ? 'MIT' : 'proprietary',
            'require' => ['php' => '^8.5', 'fahoba/plugin-api' => '^0.1'],
            'require-dev' => ['phpunit/phpunit' => '^12.0'],
            'autoload' => ['psr-4' => [$namespace.'\\' => 'src/']],
            'autoload-dev' => ['psr-4' => [$namespace.'\\Tests\\' => 'tests/']],
            'extra' => ['fahoba' => $fahoba],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    }

    /**
     * A class implementing the contract: key and label answered, every other
     * method throws until it is written — never a silent empty answer.
     *
     * @param  class-string  $contract
     */
    private function provider(string $contract, string $class): string
    {
        $methods = array_map(function (ReflectionMethod $method): string {
            $body = match ($method->getName()) {
                'key' => '        return {{ class }}Plugin::KEY;',
                'label' => "        return '{{ title }}';",
                // Installed means enabled: Fahoba asks these of every
                // provider, so a stub answers “not ready” instead of throwing.
                'isConfigured', 'supports', 'isAvailable' => "        // Not ready: Fahoba does not call the rest yet.\n        return false;",
                default => "        throw new \\LogicException('Not written yet: '.__METHOD__);",
            };

            return sprintf(
                "    public %sfunction %s(%s)%s\n    {\n%s\n    }",
                $method->isStatic() ? 'static ' : '',
                $method->getName(),
                implode(', ', array_map($this->parameter(...), $method->getParameters())),
                $method->hasReturnType() ? ': '.$this->type($method->getReturnType()) : '',
                $body,
            );
        }, (new ReflectionClass($contract))->getMethods());

        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace {{ namespace }};\n\n"
            ."final class {$class} implements \\{$contract}\n{\n"
            ."    // Nothing in the constructor: Fahoba creates providers on demand, and\n"
            ."    // a constructor must touch neither database nor network.\n\n"
            .implode("\n\n", $methods)."\n}\n";
    }

    private function parameter(ReflectionParameter $parameter): string
    {
        $text = ($parameter->hasType() ? $this->type($parameter->getType()).' ' : '')
            .($parameter->isPassedByReference() ? '&' : '')
            .($parameter->isVariadic() ? '...' : '')
            .'$'.$parameter->getName();

        if (! $parameter->isDefaultValueAvailable()) {
            return $text;
        }

        $default = $parameter->getDefaultValue();

        return $text.' = '.match (true) {
            $default instanceof UnitEnum => '\\'.$default::class.'::'.$default->name,
            $default === [] => '[]',
            $default === null => 'null',
            default => var_export($default, true),
        };
    }

    private function type(?ReflectionType $type): string
    {
        return match (true) {
            $type instanceof ReflectionNamedType => ($type->allowsNull() && ! in_array($type->getName(), ['mixed', 'null'], true) ? '?' : '')
                .($type->isBuiltin() ? $type->getName() : '\\'.$type->getName()),
            $type instanceof ReflectionUnionType => implode('|', array_map($this->type(...), $type->getTypes())),
            $type instanceof ReflectionIntersectionType => implode('&', array_map($this->type(...), $type->getTypes())),
            default => '',
        };
    }
}

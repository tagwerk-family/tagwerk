<?php

declare(strict_types=1);

namespace App\Support;

use App\Integrations\Plugins\PluginCatalog;
use Fahoba\PluginApi\Languages\Coverage;
use Fahoba\PluginApi\Languages\LanguagePack;
use Fahoba\PluginApi\Plugins\LoadReport;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * The languages installed packages bring, checked once they are asked for.
 *
 * The code is only known from the object, so the check runs here and not
 * when the package offers its class. A pack that breaks a rule is withdrawn
 * like any other plugin; the built-in languages stay the core's.
 */
#[Singleton]
final class Languages
{
    private ?string $checked = null;

    /** @var array<string, LanguagePack> */
    private array $packs = [];

    /** @var array<string, string> code => package */
    private array $packages = [];

    public function __construct(
        private readonly PluginCatalog $catalog,
        private readonly LoadReport $report,
        private readonly Container $container,
    ) {}

    /** @return array<string, LanguagePack> code => pack, ordered by code */
    public function packs(): array
    {
        $offered = $this->catalog->offered('languages');
        $signature = json_encode($offered).':'.count($this->report->failures());

        if ($this->checked === $signature) {
            return $this->packs;
        }

        $packs = [];
        $packages = [];

        foreach ($offered as $key => $class) {
            $package = $this->catalog->packageOf('languages', $key) ?? PluginCatalog::unowned($key);

            try {
                /** @var LanguagePack $pack */
                $pack = $this->container->make($class);
                $problem = $this->problem($pack, $packs);
            } catch (Throwable $exception) {
                $this->report->fail($package, $exception);

                continue;
            }

            if ($problem !== null) {
                $this->report->fail($package, $problem);

                continue;
            }

            $packs[$pack->code()] = $pack;
            $packages[$pack->code()] = $package;
        }

        ksort($packs);

        $this->packs = $packs;
        $this->packages = $packages;
        $this->checked = json_encode($this->catalog->offered('languages')).':'.count($this->report->failures());

        return $packs;
    }

    public function pack(string $code): ?LanguagePack
    {
        return $this->packs()[$code] ?? null;
    }

    public function packageOf(string $code): ?string
    {
        $this->packs();

        return $this->packages[$code] ?? null;
    }

    /** @param array<string, LanguagePack> $accepted */
    private function problem(LanguagePack $pack, array $accepted): ?string
    {
        return Coverage::problem($pack)
            ?? (isset($accepted[$pack->code()]) ? "Language {$pack->code()} is already offered by another package." : null);
    }
}

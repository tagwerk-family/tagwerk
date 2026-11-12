<?php

declare(strict_types=1);

namespace App\Integrations\Plugins;

use App\Integrations\ProviderContracts;
use Fahoba\PluginApi\Areas\Area;
use Fahoba\PluginApi\Dashboard\DataWidget;
use Fahoba\PluginApi\Households\HouseholdNotice;
use Fahoba\PluginApi\Languages\LanguagePack;
use Fahoba\PluginApi\Photos\PhotoRule;
use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\ConfiguresPanel;
use Fahoba\PluginApi\Plugins\ExportRules;
use Fahoba\PluginApi\Plugins\Extension;
use Fahoba\PluginApi\Plugins\LoadReport;
use Fahoba\PluginApi\Security\ContributesToCsp;
use Fahoba\PluginApi\Themes\Theme;
use Illuminate\Container\Attributes\Singleton;

/**
 * What installed packages offer, checked — the core's view of the plugin
 * API's `Catalog`.
 *
 * Installed means enabled: there is no second switch. Plugin keys carry a
 * vendor (`acme/nextcloud`); built-in keys never do, so no package can claim
 * a built-in key such as `google` and take over its connections and tokens.
 * `fahoba/` belongs to the first-party packages in `fahoba.first_party`.
 *
 * An offer that breaks a rule withdraws everything its package offered and
 * is reported to `LoadReport` — the app keeps running without it. Checked
 * again whenever something new was recorded or failed.
 */
#[Singleton]
final class PluginCatalog
{
    private const KEY_PATTERN = '#^[a-z0-9][a-z0-9-]*/[a-z0-9][a-z0-9-]*$#';

    /** Areas besides the providers that a package can contribute to. */
    private const EXTRA_AREAS = [
        'widgets' => DataWidget::class,
        'photo_rules' => PhotoRule::class,
        'csp' => ContributesToCsp::class,
        'extensions' => Extension::class,
        'areas' => Area::class,
        'export' => ExportRules::class,
        'panel' => ConfiguresPanel::class,
        'languages' => LanguagePack::class,
        'notices' => HouseholdNotice::class,
        'themes' => Theme::class,
    ];

    private string $checked = '';

    /** @var array<string, array<string, class-string>> */
    private array $offers = [];

    /** @var array<string, array<string, ?string>> area => key => package */
    private array $owners = [];

    public function __construct(
        private readonly Catalog $catalog,
        private readonly LoadReport $report,
    ) {}

    /**
     * The plugin classes of one area, keyed by plugin key.
     *
     * @return array<string, class-string>
     */
    public function offered(string $area): array
    {
        $this->check();

        return $this->offers[$area] ?? [];
    }

    /** The package that offered a key; null for an offer made outside a plugin. */
    public function packageOf(string $area, string $key): ?string
    {
        $this->check();

        return $this->owners[$area][$key] ?? null;
    }

    /**
     * Where the failure of an offer made outside a plugin is reported — never
     * the key itself, which names the package that owns it: reporting there
     * would withdraw the legitimate owner.
     */
    public static function unowned(string $key): string
    {
        return "{$key} (offered outside a plugin)";
    }

    private function check(): void
    {
        if ($this->checked === $this->signature()) {
            return;
        }

        $offers = [];
        $owners = [];

        foreach ($this->catalog->recorded() as $offer) {
            $problem = $this->problem($offer, $offers);

            if ($problem !== null) {
                $this->report->fail($offer['package'] ?? self::unowned($offer['key']), $problem);

                continue;
            }

            $offers[$offer['area']][$offer['key']] = $offer['class'];
            $owners[$offer['area']][$offer['key']] = $offer['package'];
        }

        foreach ($owners as $area => $keys) {
            foreach ($keys as $key => $package) {
                if ($package !== null && $this->report->failed($package)) {
                    unset($offers[$area][$key], $owners[$area][$key]);
                }
            }
        }

        $this->offers = $offers;
        $this->owners = $owners;
        $this->checked = $this->signature();
    }

    /**
     * @param  array{package: ?string, area: string, key: string, class: string}  $offer
     * @param  array<string, array<string, class-string>>  $accepted
     */
    private function problem(array $offer, array $accepted): ?string
    {
        ['package' => $package, 'area' => $area, 'key' => $key, 'class' => $class] = $offer;

        $contract = ProviderContracts::BASE[$area] ?? self::EXTRA_AREAS[$area] ?? null;

        return match (true) {
            $contract === null => "Unknown area {$area} for {$key}.",
            preg_match(self::KEY_PATTERN, $key) !== 1 => "Plugin key {$key} must look like vendor/name.",
            str_starts_with($key, 'fahoba/') && ! in_array($package, (array) config('fahoba.first_party', []), true) => "Plugin key {$key} is reserved for Fahoba's own packages.",
            isset($accepted[$area][$key]) => "Plugin key {$key} is already taken in area {$area}.",
            ! class_exists($class) => "Class {$class} for {$key} does not exist.",
            ! is_a($class, $contract, true) => "{$class} does not implement {$contract}.",
            default => null,
        };
    }

    private function signature(): string
    {
        return count($this->catalog->recorded()).':'.count($this->report->failures());
    }
}

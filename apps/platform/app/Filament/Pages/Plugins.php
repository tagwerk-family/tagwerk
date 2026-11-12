<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Integrations\Plugins\PluginManifest;
use App\Models\User;
use App\Support\Languages;
use App\Support\PackTexts;
use BackedEnum;
use Fahoba\PluginApi\Languages\LanguagePack;
use Fahoba\PluginApi\Plugins\LoadReport;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Operations → Plugins: every installed package with a Fahoba provider,
 * and whether it loaded. A broken plugin is skipped, not fatal — this is
 * where the operator learns why.
 */
class Plugins extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Operations;

    protected static ?string $slug = 'operator/plugins';

    protected string $view = 'filament.pages.plugins';

    public static function getNavigationLabel(): string
    {
        return __('Plugins');
    }

    public function getTitle(): string
    {
        return __('Plugins');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_operator === true;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $failures = app(LoadReport::class)->failures();
        $packages = app(PluginManifest::class)->packages();
        $completeFor = array_column($packages, 'complete_for', 'name');

        return [
            'running' => (string) config('app.version'),
            'languages' => array_values(array_map(function (LanguagePack $pack) use ($completeFor): array {
                $coverage = app(PackTexts::class)->coverage($pack);
                $package = (string) app(Languages::class)->packageOf($pack->code());
                $open = count($coverage['missing']) + count($coverage['broken']);

                return [
                    'code' => $pack->code(),
                    'name' => $pack->name(),
                    'package' => $package,
                    'percent' => $coverage['total'] === 0 ? 100 : (int) floor(100 * ($coverage['total'] - $open) / $coverage['total']),
                    'missing' => $coverage['missing'],
                    'broken' => $coverage['broken'],
                    'complete_for' => $completeFor[$package] ?? null,
                ];
            }, app(Languages::class)->packs())),
            'plugins' => array_map(fn (array $package): array => [
                'name' => $package['name'],
                'version' => $package['version'],
                'license' => implode(', ', $package['license']),
                'reason' => $failures[$package['name']] ?? null,
            ], $packages),
        ];
    }
}

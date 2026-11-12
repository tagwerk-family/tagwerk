<?php

declare(strict_types=1);

namespace App\Services\Photos;

use App\Integrations\Plugins\PluginCatalog;
use Fahoba\PluginApi\Photos\PhotoRule;

/**
 * The rules a household can choose for its photo frame: the built-in ones
 * from `fahoba.photo_rules`, then those plugins offer.
 */
final class PhotoRules
{
    public function __construct(private readonly PluginCatalog $plugins) {}

    /** @return array<string, PhotoRule> */
    public function all(): array
    {
        /** @var array<string, class-string<PhotoRule>> $classes */
        $classes = [...config('fahoba.photo_rules', []), ...$this->plugins->offered('photo_rules')];

        return array_map(static fn (string $class): PhotoRule => app($class), $classes);
    }

    /** Null for no rule — and for one whose plugin has since been removed. */
    public function find(?string $key): ?PhotoRule
    {
        return $key === null ? null : ($this->all()[$key] ?? null);
    }

    /** @return array<string, string> key => label, for a select */
    public function options(): array
    {
        return array_map(static fn (PhotoRule $rule): string => $rule->label(), $this->all());
    }
}

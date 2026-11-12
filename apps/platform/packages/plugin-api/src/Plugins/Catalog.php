<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Plugins;

use Closure;
use Illuminate\Container\Attributes\Singleton;

/**
 * Where a plugin offers what it brings:
 *
 *     $catalog->offer('widgets', 'acme/waste', WasteWidget::class);
 *
 * Offering only records the class — nothing is built until someone needs
 * it, and Fahoba checks the offers itself. An invalid one does not stop the
 * app: the offering package is left out and the log says why.
 *
 * A singleton by attribute, not by a binding in a service provider: plugins
 * register before the application, and a binding made there would come too
 * late — every offer would land in a throwaway instance.
 */
#[Singleton]
final class Catalog
{
    private ?string $package = null;

    /** @var list<array{package: ?string, area: string, key: string, class: string}> */
    private array $recorded = [];

    public function offer(string $area, string $key, string $class): void
    {
        $this->recorded[] = ['package' => $this->package, 'area' => $area, 'key' => $key, 'class' => $class];
    }

    /**
     * Runs `$register` with `$package` as the one offering — so a broken offer
     * withdraws exactly that package. `Plugin` does this for every plugin.
     *
     * @template T
     *
     * @param  Closure(): T  $register
     * @return T
     */
    public function during(string $package, Closure $register): mixed
    {
        $previous = $this->package;
        $this->package = $package;

        try {
            return $register();
        } finally {
            $this->package = $previous;
        }
    }

    /** @return list<array{package: ?string, area: string, key: string, class: string}> */
    public function recorded(): array
    {
        return $this->recorded;
    }
}

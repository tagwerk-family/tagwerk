<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Security;

/**
 * A plugin that loads something from a foreign host in the browser — the
 * photos of a recipe catalog, the tiles of a map.
 *
 * Offered as `offer('csp', 'vendor/name', Class)`. Without it the
 * Content Security Policy blocks the host, and a removed package takes its
 * hosts with it.
 */
interface ContributesToCsp
{
    /**
     * Hosts per directive, e.g. `['img-src' => ['https://www.example.org']]`.
     * Only `img-src`, `media-src`, `connect-src` and `font-src`; only
     * `https://` hosts, at most one leading `*.`.
     *
     * @return array<string, list<string>>
     */
    public function sources(): array;
}

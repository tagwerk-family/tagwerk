<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Themes;

/**
 * A design for wall and phone: colours, fonts and corners, nothing else.
 *
 * Data, not behaviour: one stylesheet that sets Fahoba's design tokens
 * (`Tokens`), checked by `Stylesheet` before anyone sees it. The
 * components stay the core's, so a design survives every release that
 * keeps the tokens.
 *
 * Offered as `$catalog->offer('themes', 'vendor/name', MyTheme::class)`.
 */
interface Theme
{
    /** What the settings show: `Nordlicht`. */
    public function name(): string;

    /**
     * Absolute path to the stylesheet. Fonts and pictures it names with
     * `url()` lie in the same directory.
     */
    public function stylesheet(): string;
}

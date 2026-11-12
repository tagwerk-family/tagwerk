<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Plugins;

use Filament\Panel;

/**
 * A plugin's resources and pages in the admin: `offer('panel', 'vendor/name',
 * Class)`. Called once while the panel is built, before any request — the
 * panel's routes are cached at container start. Whether a page shows is the
 * page's own question (`BelongsToArea`), per request.
 */
interface ConfiguresPanel
{
    public function configurePanel(Panel $panel): void;
}

<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Dashboard;

/**
 * A data widget a household configures per tile: a place, a feed, an API
 * key. `content()` and `act()` receive the values as `$settings`, secrets
 * decrypted. A widget without this interface shows no form.
 */
interface HasSettings
{
    /** @return list<SettingField> */
    public function settings(): array;
}

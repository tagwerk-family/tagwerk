<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Dashboard;

/**
 * A data widget whose buttons (`WidgetContent::actions()`) do something.
 *
 * Anyone who sees the tile may tap — on a tablet in the hallway that is
 * everyone passing by. An action that changes something a parent should
 * decide is `guarded`: on a tablet it then asks for the parent PIN first.
 */
interface HandlesActions
{
    /**
     * Runs the action and returns the tile's new content.
     *
     * @param  array<string, mixed>  $settings  The tile's settings.
     */
    public function act(WidgetContext $context, array $settings, string $action): WidgetContent;

    public function guarded(string $action): bool;
}

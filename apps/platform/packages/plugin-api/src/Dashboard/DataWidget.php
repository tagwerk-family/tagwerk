<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Dashboard;

/**
 * A widget that only delivers data — the wall renders it.
 *
 * For plugins: a package ships no Vue and does not have to rebuild the wall.
 * It states what it wants to show (metric, list or image), and the wall
 * displays that in its own look.
 */
interface DataWidget
{
    public function label(): string;

    public function description(): string;

    /** The key of the area the widget needs; null means always available. */
    public function area(): ?string;

    /**
     * The content for one tile. Asked at most once per minute and tile; slow
     * sources cache longer themselves.
     *
     * @param  array<string, mixed>  $settings  The tile's settings.
     */
    public function content(WidgetContext $context, array $settings): WidgetContent;
}

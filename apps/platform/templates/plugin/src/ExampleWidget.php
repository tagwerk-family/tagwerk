<?php

declare(strict_types=1);

namespace Acme\Example;

use Fahoba\PluginApi\Dashboard\DataWidget;
use Fahoba\PluginApi\Dashboard\WidgetContent;
use Fahoba\PluginApi\Dashboard\WidgetContext;

/**
 * A dashboard tile. It returns data — metric, list, table, progress, pairs,
 * image or buttons — and the wall draws it. Asked at most once a minute per
 * tile; slower sources cache longer themselves.
 */
final class ExampleWidget implements DataWidget
{
    public function label(): string
    {
        return 'Example';
    }

    public function description(): string
    {
        return 'What this tile shows.';
    }

    /** The key of an area the tile needs, e.g. 'meals'; null means always. */
    public function area(): ?string
    {
        return null;
    }

    public function content(WidgetContext $context, array $settings): WidgetContent
    {
        return WidgetContent::metric('42', 'Hello from acme/example');
    }
}

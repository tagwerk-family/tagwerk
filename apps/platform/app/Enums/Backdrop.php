<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The gradient behind the glass look, chosen per household.
 *
 * Presets rather than free colours: text sits on translucent glass above
 * it, and only a gradient that was checked in light and dark keeps it
 * readable. The colours live in the layer's `fahoba.css`.
 */
enum Backdrop: string
{
    case Dawn = 'dawn';
    case Lagoon = 'lagoon';
    case Meadow = 'meadow';
    case Dusk = 'dusk';
    case Sand = 'sand';
    case Slate = 'slate';

    public function label(): string
    {
        return match ($this) {
            self::Dawn => __('Dawn'),
            self::Lagoon => __('Lagoon'),
            self::Meadow => __('Meadow'),
            self::Dusk => __('Dusk'),
            self::Sand => __('Sand'),
            self::Slate => __('Slate'),
        };
    }
}

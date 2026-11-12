<?php

declare(strict_types=1);

namespace App\Dashboard;

use Fahoba\PluginApi\Dashboard\SettingField;

/**
 * What a widget says about itself.
 *
 * Without Vue and without Filament: the wall has one component per key, the
 * admin panel needs none. A new widget is therefore an entry here and a
 * component there — nothing else.
 */
final readonly class WidgetDefinition
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        /** The area the widget needs; null means always available. */
        public ?string $area,
        public int $w,
        public int $h,
        /** From a plugin: the wall fetches the content and renders it itself. */
        public bool $generic = false,
        /** @var list<SettingField> The plugin's settings form; empty means none. */
        public array $settings = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'feature' => $this->area,
            'w' => $this->w,
            'h' => $this->h,
            'generic' => $this->generic,
            'settings' => array_map(static fn (SettingField $field): array => $field->toArray(), $this->settings),
        ];
    }
}

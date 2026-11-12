<?php

declare(strict_types=1);

namespace Fahoba\ExampleArea;

use Fahoba\PluginApi\Dashboard\DataWidget;
use Fahoba\PluginApi\Dashboard\HandlesActions;
use Fahoba\PluginApi\Dashboard\HasSettings;
use Fahoba\PluginApi\Dashboard\SettingField;
use Fahoba\PluginApi\Dashboard\WidgetContent;
use Fahoba\PluginApi\Dashboard\WidgetContext;
use Illuminate\Support\Facades\Cache;

/**
 * A dashboard tile with settings and buttons: counts up per household.
 * Resetting is guarded — on a tablet it asks for the parent PIN.
 */
final class ExampleCounter implements DataWidget, HandlesActions, HasSettings
{
    public const KEY = 'fahoba/example-counter';

    public function label(): string
    {
        return __('Counter');
    }

    public function description(): string
    {
        return __('Counts taps, to show buttons and settings.');
    }

    public function area(): ?string
    {
        return ExampleArea::KEY;
    }

    public function settings(): array
    {
        return [
            SettingField::text('name', __('Name')),
            SettingField::select('step', __('Step'), ['1' => '1', '5' => '5', '10' => '10']),
        ];
    }

    public function content(WidgetContext $context, array $settings): WidgetContent
    {
        $step = (int) ($settings['step'] ?? 1);

        return WidgetContent::actions(
            [['name' => 'add', 'label' => __('Add :step', ['step' => $step])], ['name' => 'reset', 'label' => __('Reset')]],
            ($settings['name'] ?? __('Counter')).': '.Cache::get($this->key($context), 0),
        );
    }

    public function act(WidgetContext $context, array $settings, string $action): WidgetContent
    {
        match ($action) {
            'add' => Cache::forever($this->key($context), Cache::get($this->key($context), 0) + (int) ($settings['step'] ?? 1)),
            'reset' => Cache::forget($this->key($context)),
            default => null,
        };

        return $this->content($context, $settings);
    }

    public function guarded(string $action): bool
    {
        return $action === 'reset';
    }

    private function key(WidgetContext $context): string
    {
        return 'example-counter:'.$context->householdId;
    }
}

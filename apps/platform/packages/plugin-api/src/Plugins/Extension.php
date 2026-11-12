<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Plugins;

use Filament\Forms\Components\Field;
use Filament\Panel;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A plugin that changes the server itself, not one household's data — a
 * second sign-in factor, say. Offered as `offer('extensions', 'vendor/name',
 * Class)`.
 *
 * Unlike providers and widgets, installed does not mean on: the operator
 * switches it on under Operations → Extensions, where its settings live
 * too. Off is the default.
 */
interface Extension
{
    public function label(): string;

    public function description(): string;

    public function category(): ExtensionCategory;

    /**
     * Fields for the settings, state relative to the extension's settings.
     *
     * @return array<Component|Field>
     */
    public function settingsSchema(): array;

    /**
     * Refuses a combination before it is stored, as a sentence for the
     * operator — a required second factor the operator has not set up
     * would lock them out. Null when the settings may be saved.
     *
     * @param  array<string, mixed>  $settings
     */
    public function refuse(bool $enabled, array $settings, Authenticatable $by): ?string;

    /**
     * Called once while the admin panel is built, before any request and
     * whether the extension is on or not: the panel's routes are cached at
     * container start. Whatever is configured here asks
     * Extensions::enabled() at request time.
     */
    public function configurePanel(Panel $panel): void;
}

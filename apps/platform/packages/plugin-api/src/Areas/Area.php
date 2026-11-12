<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Areas;

/**
 * An area a plugin brings: `offer('areas', 'vendor/name', Class)`.
 *
 * It behaves like a built-in `Feature`: on for every household until someone
 * switches it off, and switched off means gone from the menu, the admin and
 * the API (`feature:vendor/name`). Its data stays.
 *
 * The constructor must do nothing — areas are built on every request that
 * lists them.
 */
interface Area
{
    /** The name in the household's list of areas and the view's title. */
    public function label(): string;

    /** The name in the narrow navigation. */
    public function shortLabel(): string;

    /** One sentence in the household's list of areas. */
    public function description(): string;

    /** A name from the frontends' icon set; an unknown one shows a puzzle piece. */
    public function icon(): string;

    /**
     * Whether the area has a view of its own in the menu. An area without one
     * (webhooks, say) is only a switch in the household's list.
     */
    public function inMenu(): bool;
}

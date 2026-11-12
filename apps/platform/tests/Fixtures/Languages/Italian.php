<?php

declare(strict_types=1);

namespace Tests\Fixtures\Languages;

use Fahoba\PluginApi\Languages\LanguagePack;

/**
 * A deliberately incomplete pack: a few texts, one with a broken
 * placeholder, so fallback and coverage have something to show.
 */
class Italian implements LanguagePack
{
    public function code(): string
    {
        return 'it';
    }

    public function name(): string
    {
        return 'Italiano';
    }

    public function intlTag(): string
    {
        return 'it-IT';
    }

    public function backendPath(): string
    {
        return __DIR__.'/it/backend';
    }

    public function frontendMessages(): string
    {
        return __DIR__.'/it/frontend.json';
    }
}

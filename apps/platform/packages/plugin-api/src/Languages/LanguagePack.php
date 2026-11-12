<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Languages;

/**
 * A language beyond German and English.
 *
 * Data, not behaviour: the texts for the server (Laravel's layout) and for
 * wall and phone (vue-i18n's nested keys), plus the tag `Intl` formats with.
 * Whatever the pack does not translate shows in English.
 *
 * Offered as `$catalog->offer('languages', 'vendor/name', Pack::class)`.
 */
interface LanguagePack
{
    /** ISO 639-1, lower case, never `de` or `en`: `fr`. */
    public function code(): string;

    /** The language's name in itself, so someone who cannot read the current one still finds theirs: `Français`. */
    public function name(): string;

    /** BCP 47 for `Intl`, Carbon and holiday names; starts with the code: `fr-FR`. */
    public function intlTag(): string;

    /**
     * Directory in Laravel's layout: `<code>.json` (the English text is the
     * key) and `<code>/<group>.php` for groups such as `validation`.
     */
    public function backendPath(): string;

    /** Path to the JSON file for wall and phone, nested keys as in the core's `en.json`. */
    public function frontendMessages(): string;
}

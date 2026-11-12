<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Device;
use App\Models\Household;
use App\Models\User;
use Closure;
use Fahoba\PluginApi\Languages\LanguagePack;
use Illuminate\Support\Facades\App;

/**
 * Which language someone sees.
 *
 * A chain, not a single source: the account's or tablet's own choice, then
 * the household's, then the server default. The operator belongs to no
 * household and ends at the default unless they chose one.
 */
final class Locale
{
    /**
     * Code → name, each in its own language: someone who cannot read the
     * current one still finds theirs. Packages add more (`supported()`).
     */
    private const BUILT_IN = [
        'de' => 'Deutsch',
        'en' => 'English',
    ];

    /** The tag `Intl` and Carbon format with — German as in Austria, English as in the US. */
    private const BUILT_IN_TAGS = [
        'de' => 'de-AT',
        'en' => 'en-US',
    ];

    /**
     * Built-in languages first, then those of installed packs by code.
     *
     * @return array<string, string>
     */
    public static function supported(): array
    {
        $packs = array_map(static fn (LanguagePack $pack): string => $pack->name(), app(Languages::class)->packs());

        return [...self::BUILT_IN, ...$packs];
    }

    /**
     * What wall and phone need to switch to a language without a mixed
     * frame: the code, the tag `Intl` formats with, and where the texts of
     * a pack are (`null` for the bundled German and English).
     *
     * @return array{code: string, intl: string, messages: ?string}
     */
    public static function describe(string $code): array
    {
        $served = app(PackTexts::class)->served($code);

        return [
            'code' => $code,
            'intl' => self::intlTag($code),
            'messages' => $served === null ? null : "/api/language/{$code}/{$served['hash']}.json",
        ];
    }

    /** BCP 47 for a supported code; an unknown one formats as the server default. */
    public static function intlTag(string $code): string
    {
        return self::BUILT_IN_TAGS[$code]
            ?? app(Languages::class)->pack($code)?->intlTag()
            ?? self::BUILT_IN_TAGS[(string) config('app.locale')]
            ?? 'de-AT';
    }

    public static function for(User|Device|null $actor): string
    {
        $own = $actor?->locale;

        if (is_string($own) && self::isSupported($own)) {
            return $own;
        }

        // Household has no scope, so this works without a context too. An
        // account follows the household it looks at.
        $householdId = $actor instanceof User ? $actor->membership()?->household_id : $actor?->household_id;
        $household = $householdId === null ? null : Household::query()->find($householdId);

        if ($household !== null && self::isSupported($household->locale)) {
            return $household->locale;
        }

        return (string) config('app.locale');
    }

    /**
     * Builds something once per language: for messages that reach several
     * people, each of whom may read a different one.
     *
     * @template T
     *
     * @param  Closure(): T  $build
     * @return array<string, T>
     */
    public static function inEvery(Closure $build): array
    {
        $built = [];

        foreach (array_keys(self::supported()) as $locale) {
            $built[$locale] = self::using($locale, $build);
        }

        return $built;
    }

    /**
     * Runs something in a given language and restores the previous one,
     * also when it throws.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function using(string $locale, Closure $callback): mixed
    {
        $previous = App::getLocale();
        App::setLocale($locale);

        try {
            return $callback();
        } finally {
            App::setLocale($previous);
        }
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && array_key_exists($locale, self::supported());
    }
}

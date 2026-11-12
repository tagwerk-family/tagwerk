<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Languages;

/**
 * How far a language pack covers the reference texts.
 *
 * Plain arithmetic on flat key → text maps, shared by Fahoba (which falls
 * back to English for what is missing or broken) and the testkit (which
 * tells a translator before Fahoba does).
 *
 * A text whose placeholders differ from the reference counts as broken: it
 * would show `{count}` or swallow a name, so Fahoba shows English instead.
 * Plural forms are not compared — their number differs between languages.
 */
final class Coverage
{
    /** Laravel's `:name` (backend) */
    public const BACKEND = 'backend';

    /** vue-i18n's `{name}` (wall and phone) */
    public const FRONTEND = 'frontend';

    /** Languages Fahoba brings itself; a pack never replaces them. */
    public const BUILT_IN = ['de', 'en'];

    private const CODE = '/^[a-z]{2}$/';

    private const TAG = '/^[a-z]{2}(-[A-Z][a-z]{3})?(-[A-Z]{2}|-\d{3})?$/';

    /** What makes Fahoba refuse the pack, or null. */
    public static function problem(LanguagePack $pack): ?string
    {
        $code = $pack->code();
        $tag = $pack->intlTag();

        return match (true) {
            preg_match(self::CODE, $code) !== 1 => "Language code {$code} must be two lower-case letters (ISO 639-1).",
            in_array($code, self::BUILT_IN, true) => "Language pack offers the built-in code {$code}.",
            trim($pack->name()) === '' => "Language pack {$code} has no name.",
            preg_match(self::TAG, $tag) !== 1 || ! str_starts_with($tag, $code) => "Language tag {$tag} must be BCP 47 and start with {$code}.",
            ! is_dir($pack->backendPath()) => "Language pack {$code}: backend path {$pack->backendPath()} is no directory.",
            ! is_file($pack->frontendMessages()) => "Language pack {$code}: frontend file {$pack->frontendMessages()} does not exist.",
            default => null,
        };
    }

    /**
     * Missing and broken texts against the reference: backend keys as they
     * are, PHP groups prefixed with the group, wall and phone with `app.`.
     *
     * @param  array{backend: array<string, string>, groups: array<string, array<string, string>>, frontend: array<string, string>}  $reference
     * @return array{total: int, missing: list<string>, broken: list<string>}
     */
    public static function of(LanguagePack $pack, array $reference): array
    {
        $results = [self::compare($reference['backend'], self::backendTexts($pack, '*'), self::BACKEND)];
        $total = count($reference['backend']) + count($reference['frontend']);

        foreach ($reference['groups'] as $group => $texts) {
            $results[] = self::prefixed("{$group}.", self::compare($texts, self::flatten(self::backendTexts($pack, $group)), self::BACKEND));
            $total += count($texts);
        }

        $results[] = self::prefixed('app.', self::compare($reference['frontend'], self::flatten(self::frontendTexts($pack)), self::FRONTEND));

        return [
            'total' => $total,
            'missing' => array_merge(...array_column($results, 'missing')),
            'broken' => array_merge(...array_column($results, 'broken')),
        ];
    }

    /**
     * The pack's file for a Laravel group: `*` is `<code>.json`, any other
     * group `<code>/<group>.php`.
     *
     * @return array<array-key, mixed>
     */
    public static function backendTexts(LanguagePack $pack, string $group): array
    {
        $code = $pack->code();

        return $group === '*'
            ? self::read($pack->backendPath()."/{$code}.json")
            : self::read($pack->backendPath()."/{$code}/{$group}.php");
    }

    /** @return array<array-key, mixed> */
    public static function frontendTexts(LanguagePack $pack): array
    {
        return self::read($pack->frontendMessages());
    }

    /**
     * A JSON or PHP file as an array; nothing for a missing or malformed one.
     *
     * @return array<array-key, mixed>
     */
    public static function read(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        $content = str_ends_with($file, '.php') ? require $file : json_decode((string) file_get_contents($file), true);

        return is_array($content) ? $content : [];
    }

    /**
     * @param  array<string, string>  $reference
     * @param  array<string, mixed>  $pack
     * @return array{missing: list<string>, broken: list<string>}
     */
    public static function compare(array $reference, array $pack, string $style): array
    {
        $missing = [];
        $broken = [];

        foreach ($reference as $key => $text) {
            $translated = $pack[$key] ?? null;

            if (! is_string($translated) || trim($translated) === '') {
                $missing[] = (string) $key;
            } elseif (self::placeholders($translated, $style) !== self::placeholders($text, $style)) {
                $broken[] = (string) $key;
            }
        }

        return ['missing' => $missing, 'broken' => $broken];
    }

    /**
     * The keys of $pack that must not be shown: placeholders differ from the
     * reference, or the value is no text. Keys the reference does not know
     * are judged against themselves for the backend (the English text is the
     * key) and left alone for the frontend.
     *
     * @param  array<string, string>  $reference
     * @param  array<string, mixed>  $pack
     * @return list<string>
     */
    public static function broken(array $reference, array $pack, string $style): array
    {
        $broken = [];

        foreach ($pack as $key => $translated) {
            $key = (string) $key;
            $text = $reference[$key] ?? ($style === self::BACKEND ? $key : null);

            if (! is_string($translated)) {
                $broken[] = $key;
            } elseif ($text !== null && self::placeholders($translated, $style) !== self::placeholders($text, $style)) {
                $broken[] = $key;
            }
        }

        return $broken;
    }

    /**
     * Nested messages as dot keys.
     *
     * @param  array<array-key, mixed>  $nested
     * @return array<string, mixed>
     */
    public static function flatten(array $nested, string $prefix = ''): array
    {
        $flat = [];

        foreach ($nested as $key => $value) {
            if (is_array($value) && $value !== []) {
                $flat = [...$flat, ...self::flatten($value, "{$prefix}{$key}.")];
            } else {
                $flat[$prefix.$key] = $value;
            }
        }

        return $flat;
    }

    /**
     * @param  array{missing: list<string>, broken: list<string>}  $result
     * @return array{missing: list<string>, broken: list<string>}
     */
    private static function prefixed(string $prefix, array $result): array
    {
        return array_map(static fn (array $keys): array => array_map(static fn (string $key): string => $prefix.$key, $keys), $result);
    }

    /** @return list<string> sorted, unique */
    public static function placeholders(string $text, string $style): array
    {
        $pattern = $style === self::BACKEND ? '/:([A-Za-z_][A-Za-z0-9_]*)/' : '/\{\s*([A-Za-z_][\w.]*)\s*\}/';

        preg_match_all($pattern, $text, $matches);

        $names = array_values(array_unique(array_map(strtolower(...), $matches[1])));
        sort($names);

        return $names;
    }
}

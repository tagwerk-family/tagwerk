<?php

declare(strict_types=1);

namespace App\Support;

use Fahoba\PluginApi\Languages\Coverage;

/**
 * The English texts a language pack translates, as files for
 * `fahoba/plugin-api`.
 *
 * Backend: the keys of `lang/de.json` (the English text is the key, so the
 * German file lists every one) and the groups in `lang/de/`, with Laravel's
 * English where it has one. Frontend: the shared `en.json`, flat.
 * `LanguageReferenceTest` fails when the shipped files are stale.
 */
final class LanguageReference
{
    /** @return array<string, string> file name → JSON */
    public static function build(): array
    {
        $backend = array_keys(self::json(lang_path('de.json')));

        $groups = [];

        foreach (glob(lang_path('de/*.php')) ?: [] as $file) {
            $group = basename($file, '.php');
            $german = Coverage::flatten(require $file);
            $english = Coverage::flatten(app('translation.loader')->load('en', $group));

            // Empty lists such as `validation.custom` hold no text to translate.
            foreach (array_filter($german, is_string(...)) as $key => $text) {
                $groups[$group][$key] = is_string($english[$key] ?? null) ? $english[$key] : $text;
            }
        }

        ksort($groups);

        $frontend = Coverage::flatten(self::json(base_path('frontend/packages/shared/i18n/locales/en.json')));

        return [
            'backend.json' => self::encode($backend),
            'groups.json' => self::encode($groups),
            'frontend.json' => self::encode($frontend),
        ];
    }

    /** @return array<array-key, mixed> */
    private static function json(string $file): array
    {
        return (array) json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<array-key, mixed> $data */
    private static function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }
}

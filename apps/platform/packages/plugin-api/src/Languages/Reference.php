<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Languages;

/**
 * The English texts a language pack translates, shipped with this package
 * so Fahoba and a translator's own tests measure against the same ones.
 * Fahoba writes them (`php artisan fahoba:language-reference`).
 */
final class Reference
{
    public static function path(): string
    {
        return dirname(__DIR__, 2).'/resources/reference';
    }

    /**
     * `backend`: Laravel's JSON texts (key = English text), `groups`: PHP
     * groups such as `validation` with dot keys, `frontend`: wall and phone
     * with dot keys.
     *
     * @return array{backend: array<string, string>, groups: array<string, array<string, string>>, frontend: array<string, string>}
     */
    public static function load(): array
    {
        $keys = array_values(array_filter(Coverage::read(self::path().'/backend.json'), is_string(...)));

        return [
            'backend' => array_combine($keys, $keys),
            'groups' => Coverage::read(self::path().'/groups.json'),
            'frontend' => Coverage::read(self::path().'/frontend.json'),
        ];
    }
}

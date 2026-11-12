<?php

declare(strict_types=1);

namespace App\Support\Recipes;

use DateInterval;
use Fahoba\PluginApi\Recipes\Ingredient;
use Throwable;

/**
 * A schema.org `Recipe` from a web page's JSON-LD, as recipe attributes.
 *
 * Most recipe sites carry it for search engines, so one parser covers them
 * all. Everything is text: HTML in the fields is stripped, the instructions
 * become a numbered Markdown list.
 */
final class LinkedRecipe
{
    /**
     * @return array<string, mixed>|null attributes for `RecipeInput::rules()`, plus `image`
     */
    public static function fromHtml(string $html, string $url): ?array
    {
        preg_match_all('#<script[^>]+type\s*=\s*["\']?application/ld\+json["\']?[^>]*>(.*?)</script>#is', $html, $blocks);

        foreach ($blocks[1] as $block) {
            $data = json_decode(trim($block), true);
            $recipe = is_array($data) ? self::find($data) : null;

            if ($recipe !== null) {
                return self::attributes($recipe, $url);
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $node
     * @return array<string, mixed>|null
     */
    private static function find(array $node): ?array
    {
        $type = $node['@type'] ?? null;

        if ($type === 'Recipe' || (is_array($type) && in_array('Recipe', $type, true))) {
            return $node;
        }

        foreach ($node as $child) {
            if (is_array($child) && ($found = self::find($child)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $recipe
     * @return array<string, mixed>|null
     */
    private static function attributes(array $recipe, string $url): ?array
    {
        $title = self::cut(self::text($recipe['name'] ?? null), 255);

        if ($title === '') {
            return null;
        }

        $author = self::name($recipe['author'] ?? null);
        $ingredients = [];

        foreach (array_slice(self::strings($recipe['recipeIngredient'] ?? $recipe['ingredients'] ?? []), 0, 150) as $line) {
            $ingredient = Ingredient::parse($line);

            if ($ingredient !== null) {
                $ingredients[] = [
                    ...$ingredient->toArray(),
                    'name' => self::cut($ingredient->name, 255),
                    'unit' => $ingredient->unit === null ? null : self::cut($ingredient->unit, 32),
                    'note' => $ingredient->note === null ? null : self::cut($ingredient->note, 255),
                ];
            }
        }

        $categories = [];

        foreach ([...self::strings($recipe['recipeCategory'] ?? []), ...self::strings($recipe['recipeCuisine'] ?? [])] as $value) {
            foreach (explode(',', $value) as $category) {
                $category = self::cut(trim($category), 60);

                if ($category !== '' && ! in_array($category, $categories, true)) {
                    $categories[] = $category;
                }
            }
        }

        $intro = self::cut(self::text($recipe['description'] ?? null), 5000);
        $steps = self::cut(self::steps($recipe['recipeInstructions'] ?? null), 30000);

        return [
            'title' => $title,
            'intro' => $intro === '' ? null : $intro,
            'steps' => $steps === '' ? null : $steps,
            'servings' => self::servings($recipe['recipeYield'] ?? null),
            'minutes' => self::minutes($recipe['totalTime'] ?? null)
                ?? self::sum(self::minutes($recipe['prepTime'] ?? null), self::minutes($recipe['cookTime'] ?? null)),
            'categories' => array_slice($categories, 0, 12),
            'vegetarian' => self::vegetarian($recipe['suitableForDiet'] ?? null),
            'ingredients' => $ingredients,
            'source_url' => $url,
            'license' => array_filter(['url' => $url, 'author' => $author]),
            'image' => self::image($recipe['image'] ?? null),
        ];
    }

    /** Plain strings, HowToSteps and HowToSections with headings. */
    private static function steps(mixed $instructions): string
    {
        if (is_string($instructions)) {
            $lines = array_values(array_filter(array_map(
                self::text(...),
                preg_split('/\R+|<br\s*\/?>|<\/p>|<\/li>/i', $instructions) ?: [],
            )));

            return self::numbered($lines);
        }

        if (! is_array($instructions)) {
            return '';
        }

        $parts = [];
        $lines = [];

        foreach (array_is_list($instructions) ? $instructions : [$instructions] as $step) {
            if (is_array($step) && ($step['@type'] ?? null) === 'HowToSection') {
                if ($lines !== []) {
                    $parts[] = self::numbered($lines);
                    $lines = [];
                }

                $heading = self::text($step['name'] ?? null);
                $inner = self::steps($step['itemListElement'] ?? []);
                $parts[] = trim(($heading === '' ? '' : "### {$heading}\n\n").$inner);

                continue;
            }

            $text = self::text(is_array($step) ? ($step['text'] ?? $step['name'] ?? null) : $step);

            if ($text !== '') {
                $lines[] = $text;
            }
        }

        if ($lines !== []) {
            $parts[] = self::numbered($lines);
        }

        return implode("\n\n", array_filter($parts));
    }

    /** @param  list<string>  $lines */
    private static function numbered(array $lines): string
    {
        return implode("\n", array_map(
            static fn (int $index, string $line): string => ($index + 1).'. '.$line,
            array_keys($lines),
            $lines,
        ));
    }

    private static function servings(mixed $yield): ?int
    {
        foreach (is_array($yield) ? $yield : [$yield] as $value) {
            if ((is_string($value) || is_int($value) || is_float($value)) && preg_match('/\d+/', (string) $value, $match) === 1) {
                $count = (int) $match[0];

                return $count >= 1 && $count <= 50 ? $count : null;
            }
        }

        return null;
    }

    /** ISO 8601 durations: "PT1H30M", "P0DT45M". */
    private static function minutes(mixed $duration): ?int
    {
        if (! is_string($duration) || $duration === '') {
            return null;
        }

        try {
            $interval = new DateInterval(strtoupper(trim($duration)));
        } catch (Throwable) {
            return null;
        }

        $minutes = $interval->d * 1440 + $interval->h * 60 + $interval->i;

        return $minutes >= 1 && $minutes <= 2880 ? $minutes : null;
    }

    private static function sum(?int $a, ?int $b): ?int
    {
        return $a === null && $b === null ? null : min(2880, ($a ?? 0) + ($b ?? 0));
    }

    private static function vegetarian(mixed $diet): ?bool
    {
        foreach (self::strings($diet) as $value) {
            if (preg_match('/(Vegetarian|Vegan)Diet$/', $value) === 1) {
                return true;
            }
        }

        return null;
    }

    private static function image(mixed $image): ?string
    {
        if (is_array($image)) {
            $image = array_is_list($image) ? ($image[0] ?? null) : ($image['url'] ?? null);

            if (is_array($image)) {
                $image = $image['url'] ?? null;
            }
        }

        return is_string($image) && preg_match('#^https?://#i', $image) === 1 ? $image : null;
    }

    private static function name(mixed $author): ?string
    {
        if (is_array($author)) {
            $author = array_is_list($author) ? ($author[0] ?? null) : $author;
            $author = is_array($author) ? ($author['name'] ?? null) : $author;
        }

        $name = self::cut(self::text($author), 255);

        return $name === '' ? null : $name;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? self::text($item) : '',
            $values,
        )));
    }

    private static function text(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function cut(string $text, int $length): string
    {
        return mb_substr($text, 0, $length);
    }
}

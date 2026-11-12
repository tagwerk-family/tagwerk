<?php

declare(strict_types=1);

namespace App\Support\Recipes;

use Fahoba\PluginApi\Recipes\Ingredient;
use Illuminate\Validation\Rule;

/**
 * The fields of an own recipe as they arrive — from the device form, a
 * collection or a link — and how they become columns.
 *
 * Ingredients come either structured (`ingredients`) or as typed lines
 * (`ingredient_text`, see `Ingredient::parseList()`); the form on a phone is
 * quicker with lines than with a row of fields per ingredient.
 */
final class RecipeInput
{
    public const DIFFICULTIES = ['easy', 'medium', 'hard'];

    /** @return array<string, mixed> */
    public static function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'max:255'],
            'intro' => ['nullable', 'string', 'max:5000'],
            'steps' => ['nullable', 'string', 'max:30000'],
            'servings' => ['nullable', 'integer', 'min:1', 'max:50'],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:2880'],
            'difficulty' => ['nullable', Rule::in(self::DIFFICULTIES)],
            'categories' => ['nullable', 'array', 'max:12'],
            'categories.*' => ['nullable', 'string', 'max:60'],
            'vegetarian' => ['nullable', 'boolean'],
            'ingredient_text' => ['nullable', 'string', 'max:20000'],
            'ingredients' => ['nullable', 'array', 'max:150'],
            'ingredients.*.name' => ['required', 'string', 'max:255'],
            'ingredients.*.amount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'ingredients.*.amount_max' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'ingredients.*.unit' => ['nullable', 'string', 'max:32'],
            'ingredients.*.note' => ['nullable', 'string', 'max:255'],
            'ingredients.*.group' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Validated input to model attributes; only what was sent.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function attributes(array $data): array
    {
        $attributes = array_intersect_key($data, array_flip([
            'title', 'intro', 'steps', 'minutes', 'difficulty', 'vegetarian',
        ]));

        if (array_key_exists('servings', $data)) {
            $attributes['servings'] = (int) ($data['servings'] ?? 4);
        }

        if (array_key_exists('categories', $data)) {
            $attributes['categories'] = array_values(array_unique(array_filter(
                array_map(static fn (mixed $category): string => trim((string) $category), (array) ($data['categories'] ?? [])),
                static fn (string $category): bool => $category !== '',
            )));
        }

        if (array_key_exists('ingredient_text', $data)) {
            $attributes['ingredients'] = array_map(
                static fn (Ingredient $ingredient): array => $ingredient->toArray(),
                Ingredient::parseList((string) ($data['ingredient_text'] ?? '')),
            );
        } elseif (array_key_exists('ingredients', $data)) {
            $attributes['ingredients'] = array_map(
                static fn (array $entry): array => Ingredient::fromArray($entry)->toArray(),
                array_values(array_filter((array) ($data['ingredients'] ?? []), is_array(...))),
            );
        }

        return $attributes;
    }
}

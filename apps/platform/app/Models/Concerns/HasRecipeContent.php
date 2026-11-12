<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Fahoba\PluginApi\Recipes\Ingredient;
use Fahoba\PluginApi\Recipes\RecipeDetail;

/**
 * What an own recipe and a catalog recipe have in common: the columns, and
 * how they become a card, a detail and a shopping list.
 *
 * @property string $title
 * @property array<int, mixed>|null $ingredients
 * @property string|null $steps
 * @property string|null $intro
 * @property int $servings
 * @property int|null $minutes
 * @property string|null $difficulty
 * @property list<string>|null $categories
 * @property bool|null $vegetarian
 * @property string|null $source_url
 * @property array<string, string|null>|null $license
 */
trait HasRecipeContent
{
    /** @return array{small: string, large: string}|null */
    abstract public function photoUrls(): ?array;

    /** @return list<array{id: string, url: string}> */
    abstract public function pageUrls(): array;

    /** @return array<string, string> */
    protected function recipeCasts(): array
    {
        return [
            'ingredients' => 'array',
            'categories' => 'array',
            'license' => 'array',
            'vegetarian' => 'boolean',
            'servings' => 'integer',
            'minutes' => 'integer',
        ];
    }

    /** @return list<Ingredient> */
    public function ingredientObjects(): array
    {
        $list = [];

        foreach ((array) ($this->ingredients ?? []) as $entry) {
            if (is_array($entry) && trim((string) ($entry['name'] ?? '')) !== '') {
                $list[] = Ingredient::fromArray($entry);
            }
        }

        return $list;
    }

    /**
     * The ingredients in the shape the provider contract requires.
     *
     * @return array<int, array{name: string, spec: string|null}>
     */
    public function ingredientList(): array
    {
        return array_map(
            static fn (Ingredient $ingredient): array => ['name' => $ingredient->name, 'spec' => $ingredient->spec()],
            $this->ingredientObjects(),
        );
    }

    /** @return array{id: string, name: string, minutes: int|null, difficulty: string|null, vegetarian: bool|null, photo: string|null} */
    public function card(): array
    {
        return [
            'id' => (string) $this->getKey(),
            'name' => $this->title,
            'minutes' => $this->minutes,
            'difficulty' => $this->difficulty,
            'vegetarian' => $this->vegetarian,
            'photo' => $this->photoUrls()['small'] ?? null,
        ];
    }

    public function detail(): RecipeDetail
    {
        return new RecipeDetail(
            id: (string) $this->getKey(),
            title: $this->title,
            ingredients: $this->ingredientObjects(),
            steps: $this->steps,
            intro: $this->intro,
            servings: max(1, (int) $this->servings),
            minutes: $this->minutes,
            difficulty: $this->difficulty,
            categories: array_values(array_filter((array) ($this->categories ?? []), is_string(...))),
            vegetarian: $this->vegetarian,
            photo: $this->photoUrls(),
            sourceUrl: $this->source_url,
            license: $this->license,
            pages: $this->pageUrls(),
        );
    }
}

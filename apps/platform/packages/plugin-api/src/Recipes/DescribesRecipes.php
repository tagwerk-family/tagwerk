<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Recipes;

/**
 * A recipe source that knows more than a name and ingredients: steps, time,
 * servings, a photo — and can be browsed with filters.
 *
 * Without it a source still works in the meal plan; the recipe book then
 * shows its recipes by name only and leaves it out when a filter is set.
 */
interface DescribesRecipes
{
    /**
     * Cards for the grid: `{id, name, minutes, difficulty, vegetarian, photo}`.
     *
     * @return list<array{id: string, name: string, minutes: int|null, difficulty: string|null, vegetarian: bool|null, photo: string|null}>
     */
    public function browse(RecipeQuery $query): array;

    public function recipe(string $recipeId): ?RecipeDetail;

    /** @return list<string> */
    public function categories(): array;
}

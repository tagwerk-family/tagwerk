<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Recipes;

/**
 * A recipe collection the meal plan draws from.
 *
 * Read-only: recipes are maintained where they live. Fahoba takes over a
 * name and an ingredient list, nothing else.
 *
 * IDs are strings, not numbers: Tandoor counts, Mealie and Paprika use slugs.
 * An integer in the contract would have ruled out every other provider.
 */
interface RecipeProvider
{
    public function key(): string;

    public function label(): string;

    public function isConfigured(): bool;

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function search(string $term = '', int $limit = 25): array;

    /**
     * @return array<int, array{name: string, spec: string|null}>
     */
    public function ingredients(string $recipeId): array;
}

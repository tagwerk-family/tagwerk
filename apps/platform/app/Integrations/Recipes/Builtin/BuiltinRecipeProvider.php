<?php

declare(strict_types=1);

namespace App\Integrations\Recipes\Builtin;

use App\Models\Recipe;
use App\Support\Recipes\RecipeSearch;
use Fahoba\PluginApi\Recipes\DescribesRecipes;
use Fahoba\PluginApi\Recipes\RecipeDetail;
use Fahoba\PluginApi\Recipes\RecipeProvider;
use Fahoba\PluginApi\Recipes\RecipeQuery;

/**
 * The recipe book Fahoba keeps itself.
 *
 * Always configured and without any third-party service: as with the
 * built-in calendar, this is the default, not the fallback. A household can
 * keep its recipes here and never touch a foreign service.
 */
final class BuiltinRecipeProvider implements DescribesRecipes, RecipeProvider
{
    public const KEY = 'builtin';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Family Home Base';
    }

    /** A book of our own needs no credentials. */
    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function search(string $term = '', int $limit = 25): array
    {
        return array_map(
            static fn (array $card): array => ['id' => $card['id'], 'name' => $card['name']],
            $this->browse(new RecipeQuery(term: $term, limit: $limit)),
        );
    }

    /**
     * @return array<int, array{name: string, spec: string|null}>
     */
    public function ingredients(string $recipeId): array
    {
        return $this->find($recipeId)?->ingredientList() ?? [];
    }

    public function browse(RecipeQuery $query): array
    {
        return RecipeSearch::apply(Recipe::query(), $query)
            ->get()
            ->map(static fn (Recipe $recipe): array => $recipe->card())
            ->values()
            ->all();
    }

    public function recipe(string $recipeId): ?RecipeDetail
    {
        return $this->find($recipeId)?->detail();
    }

    public function categories(): array
    {
        return RecipeSearch::categories(Recipe::query());
    }

    private function find(string $recipeId): ?Recipe
    {
        return ctype_digit($recipeId) ? Recipe::query()->find($recipeId) : null;
    }
}

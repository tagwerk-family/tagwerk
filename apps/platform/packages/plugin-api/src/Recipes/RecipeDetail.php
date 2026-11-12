<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Recipes;

/**
 * A whole recipe as the cooking view shows it.
 *
 * Text is Markdown (`intro`, `steps`), never HTML — a provider converts its
 * own format. `license` names who must be credited: `{text, author, url}`.
 */
final readonly class RecipeDetail
{
    /**
     * @param  list<Ingredient>  $ingredients
     * @param  list<string>  $categories
     * @param  array{small: string, large: string}|null  $photo
     * @param  array{text?: string|null, author?: string|null, url?: string|null}|null  $license
     * @param  list<array{id: string, url: string}>  $pages  photos of the recipe itself
     */
    public function __construct(
        public string $id,
        public string $title,
        public array $ingredients,
        public ?string $steps = null,
        public ?string $intro = null,
        public int $servings = 4,
        public ?int $minutes = null,
        public ?string $difficulty = null,
        public array $categories = [],
        public ?bool $vegetarian = null,
        public ?array $photo = null,
        public ?string $sourceUrl = null,
        public ?array $license = null,
        public array $pages = [],
    ) {}
}

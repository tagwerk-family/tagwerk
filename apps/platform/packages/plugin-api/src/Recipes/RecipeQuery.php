<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Recipes;

/**
 * What someone looks for in the recipe book.
 *
 * `ids` narrows to known recipes — favourites and the cooking history are
 * Fahoba's, and the provider only fills in what they look like.
 */
final readonly class RecipeQuery
{
    /** @param  list<string>|null  $ids */
    public function __construct(
        public string $term = '',
        public ?int $maxMinutes = null,
        public bool $vegetarian = false,
        public ?string $difficulty = null,
        public ?string $category = null,
        public ?array $ids = null,
        public int $limit = 60,
        public int $offset = 0,
        /** A shuffled order that stays the same for the same seed — "Andere" asks with a new one. */
        public ?int $seed = null,
    ) {}

    /** Whether anything beyond the search term narrows the result. */
    public function filters(): bool
    {
        return $this->maxMinutes !== null || $this->vegetarian || $this->difficulty !== null || $this->category !== null;
    }
}

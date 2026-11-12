<?php

declare(strict_types=1);

namespace App\Services\Recipes;

use App\Models\Household;
use App\Services\Lists\IngredientTransfer;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Recipes\DescribesRecipes;
use Fahoba\PluginApi\Recipes\Ingredient;
use Fahoba\PluginApi\Recipes\RecipeProvider;

/**
 * From recipes to the shopping list: scaled to who eats, without what is
 * always at home, equal ingredients summed.
 */
final class RecipeShopping
{
    public function __construct(
        private readonly IngredientTransfer $transfer,
        private readonly HouseholdContext $context,
    ) {}

    /**
     * A recipe's ingredients for `$servings` portions. A source that only
     * knows ingredients does not say for how many — its amounts stay.
     *
     * @return list<Ingredient>
     */
    public function ingredients(RecipeProvider $source, string $id, ?float $servings = null): array
    {
        if ($source instanceof DescribesRecipes) {
            $detail = $source->recipe($id);

            if ($detail === null) {
                return [];
            }

            $factor = $servings === null ? 1.0 : $servings / max(1, $detail->servings);

            return array_map(static fn (Ingredient $ingredient): Ingredient => $ingredient->scaled($factor), $detail->ingredients);
        }

        return array_values(array_map(
            static fn (array $item): Ingredient => Ingredient::parse(trim(($item['spec'] ?? '').' '.$item['name']))
                ?? new Ingredient($item['name']),
            $source->ingredients($id),
        ));
    }

    /**
     * @param  list<Ingredient>  $ingredients
     * @return array{list_id: int|null, added: int}
     */
    public function put(array $ingredients): array
    {
        $items = $this->combine($ingredients, $this->staples());

        // Everything was salt and pepper: nothing to buy is not an error.
        if ($items === [] && $ingredients !== []) {
            return ['list_id' => null, 'added' => 0];
        }

        return $this->transfer->add($items);
    }

    /**
     * Summed by name and unit; one line per name, so that "500 g Mehl" and
     * "2 EL Mehl" become "500 g + 2 EL" instead of the second being dropped
     * as already on the list.
     *
     * @param  list<Ingredient>  $ingredients
     * @param  list<string>  $staples
     * @return list<array{name: string, spec: string|null}>
     */
    public function combine(array $ingredients, array $staples): array
    {
        /** @var array<string, array<string, Ingredient>> $byName */
        $byName = [];

        foreach ($ingredients as $ingredient) {
            $name = mb_strtolower(trim($ingredient->name));

            if ($name === '' || self::isStaple($name, $staples)) {
                continue;
            }

            $unit = (string) $ingredient->unit;
            $known = $byName[$name][$unit] ?? null;

            $byName[$name][$unit] = $known === null
                ? new Ingredient($ingredient->name, $ingredient->amount, $ingredient->amountMax, $ingredient->unit)
                : self::sum($known, $ingredient);
        }

        $items = [];

        foreach ($byName as $units) {
            $first = reset($units);
            $specs = array_values(array_filter(array_map(static fn (Ingredient $part): ?string => $part->spec(), $units)));

            $items[] = ['name' => $first->name, 'spec' => $specs === [] ? null : implode(' + ', $specs)];
        }

        return $items;
    }

    /** @return list<string> */
    public function staples(): array
    {
        return Household::query()->find($this->context->id())?->pantryStaples() ?? [];
    }

    /**
     * Also the compound: "Öl" covers "Olivenöl", "Pfeffer" "Cayennepfeffer".
     *
     * @param  list<string>  $staples
     */
    private static function isStaple(string $name, array $staples): bool
    {
        foreach ($staples as $staple) {
            $staple = mb_strtolower(trim($staple));

            if ($staple !== '' && str_ends_with($name, $staple)) {
                return true;
            }
        }

        return false;
    }

    /** An amount-less line next to a measured one adds nothing to buy. */
    private static function sum(Ingredient $a, Ingredient $b): Ingredient
    {
        if ($a->amount === null || $b->amount === null) {
            return $a->amount === null ? new Ingredient($a->name, $b->amount, $b->amountMax, $b->unit) : $a;
        }

        $max = $a->amountMax === null && $b->amountMax === null
            ? null
            : ($a->amountMax ?? $a->amount) + ($b->amountMax ?? $b->amount);

        return new Ingredient($a->name, $a->amount + $b->amount, $max, $a->unit);
    }
}

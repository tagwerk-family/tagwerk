<?php

declare(strict_types=1);

namespace Tests\Feature\Recipes;

use App\Enums\Gender;
use App\Enums\ListKind;
use App\Models\Checklist;
use App\Models\Household;
use App\Models\MealPlanEntry;
use App\Models\Person;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Recipes\RecipeShopping;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Recipes\Ingredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * From recipes to the shopping list: scaled, summed, without staples.
 */
final class RecipeShoppingTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Checklist $list;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create(['locale' => 'de']);
        app(HouseholdContext::class)->setId((int) $this->household->id);
        $this->list = Checklist::factory()->create([
            'household_id' => $this->household->id,
            'name' => 'Einkauf',
            'kind' => ListKind::Shopping,
            'position' => 0,
        ]);

        $parent = Person::factory()->for($this->household)->create([
            'gender' => Gender::Male,
            'birthday' => now()->subYears(40)->toDateString(),
        ]);
        $this->token = User::factory()->create([
            'household_id' => $this->household->id,
            'person_id' => $parent->id,
        ])->createToken('phone')->plainTextToken;
    }

    /** @param list<string> $lines */
    private function recipe(string $title, array $lines, int $servings = 4): Recipe
    {
        return Recipe::factory()->create([
            'household_id' => $this->household->id,
            'title' => $title,
            'servings' => $servings,
            'ingredients' => array_map(static fn (string $line): array => Ingredient::parse($line)?->toArray() ?? [], $lines),
        ]);
    }

    /** @return array<string, string|null> title → quantity */
    private function items(): array
    {
        return $this->list->items()->orderBy('position')->pluck('quantity', 'title')->all();
    }

    public function test_a_recipe_goes_on_the_list_scaled_and_without_staples(): void
    {
        $recipe = $this->recipe('Palatschinken', ['250 g Mehl', '2 Eier', '1 Prise Salz', '2 EL Sonnenblumenöl'], servings: 4);

        $this->withToken($this->token)
            ->postJson("/api/wall/recipe-book/builtin/{$recipe->id}/ingredients", ['servings' => 2])
            ->assertOk()
            ->assertJsonPath('added', 2);

        $this->assertSame(['Mehl' => '125 g', 'Eier' => '1'], $this->items());
    }

    public function test_only_staples_add_nothing_without_an_error(): void
    {
        $recipe = $this->recipe('Salzwasser', ['1 l Wasser', '1 TL Salz']);

        $this->withToken($this->token)
            ->postJson("/api/wall/recipe-book/builtin/{$recipe->id}/ingredients")
            ->assertOk()
            ->assertJsonPath('added', 0);
    }

    public function test_a_household_chooses_its_own_staples(): void
    {
        $this->household->update(['pantry_staples' => ['Mehl']]);
        $recipe = $this->recipe('Palatschinken', ['250 g Mehl', '1 Prise Salz']);

        $this->withToken($this->token)
            ->postJson("/api/wall/recipe-book/builtin/{$recipe->id}/ingredients")
            ->assertOk();

        $this->assertSame(['Salz' => '1 Prise'], $this->items());
    }

    public function test_the_week_sums_equal_ingredients_by_name_and_unit(): void
    {
        $pancakes = $this->recipe('Palatschinken', ['250 g Mehl', '500 ml Milch'], servings: 1);
        $dumplings = $this->recipe('Knödel', ['100 g Mehl', '2 EL Mehl', '250 ml Milch'], servings: 1);

        foreach ([[$pancakes, '2026-10-05'], [$dumplings, '2026-10-07'], [$pancakes, '2026-10-20']] as [$recipe, $day]) {
            MealPlanEntry::factory()->withRecipe((string) $recipe->id, 'builtin')->create([
                'household_id' => $this->household->id,
                'title' => $recipe->title,
                'planned_on' => $day,
            ]);
        }

        $this->withToken($this->token)
            ->postJson('/api/wall/meals/shopping', ['from' => '2026-10-05', 'until' => '2026-10-11'])
            ->assertOk()
            ->assertJsonPath('added', 2);

        // One adult man eats: one portion each.
        $this->assertSame(['Mehl' => '350 g + 2 EL', 'Milch' => '750 ml'], $this->items());
    }

    public function test_a_week_without_recipes_says_so(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/wall/meals/shopping', ['from' => '2026-10-05', 'until' => '2026-10-11'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('meal');
    }

    public function test_staples_cover_compounds_and_follow_the_language(): void
    {
        $shopping = app(RecipeShopping::class);

        $this->assertSame([['name' => 'Zwiebel', 'spec' => null]], $shopping->combine([
            new Ingredient('Olivenöl', 2, null, 'EL'),
            new Ingredient('Cayennepfeffer'),
            new Ingredient('Zwiebel'),
        ], $shopping->staples()));

        $this->assertSame(['Salt', 'Pepper', 'Oil', 'Water'], Household::defaultStaples('en'));
    }
}

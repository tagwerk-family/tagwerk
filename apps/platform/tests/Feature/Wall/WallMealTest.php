<?php

declare(strict_types=1);

namespace Tests\Feature\Wall;

use App\Enums\ListKind;
use App\Enums\MealKind;
use App\Enums\ProviderArea;
use App\Models\Checklist;
use App\Models\Household;
use App\Models\MealPlanEntry;
use App\Models\ProviderAccount;
use App\Models\Recipe;
use App\Services\DevicePairingService;
use App\Support\HouseholdContext;
use App\Support\PublicAddressResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeResolver;
use Tests\TestCase;

final class WallMealTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create(['timezone' => 'Europe/Vienna']);
        app(HouseholdContext::class)->setId((int) $this->household->id);
        $this->app->instance(PublicAddressResolver::class, new FakeResolver(['tandoor.test' => ['93.184.216.34']]));

        $device = app(DevicePairingService::class)->createCode($this->household, 'Küche');
        $this->token = app(DevicePairingService::class)->redeem($device->pairing_code)['token'];
    }

    private function configureTandoor(?Household $household = null): void
    {
        ProviderAccount::factory()->create([
            'household_id' => ($household ?? $this->household)->id,
            'area' => ProviderArea::Recipes,
            'provider' => 'tandoor',
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'credentials' => ['url' => 'https://tandoor.test', 'token' => 'secret'],
        ]);
    }

    /** @param array<int, array{name: string, amount?: float, unit?: string}> $ingredients */
    private function recipeResponse(array $ingredients): array
    {
        return [
            'id' => 42,
            'name' => 'Gemüselasagne',
            'steps' => [[
                'ingredients' => array_map(fn (array $ingredient): array => [
                    'amount' => $ingredient['amount'] ?? 0,
                    'unit' => isset($ingredient['unit']) ? ['name' => $ingredient['unit']] : null,
                    'food' => ['name' => $ingredient['name']],
                ], $ingredients),
            ]],
        ];
    }

    private function shoppingList(bool $pushToBring = false): Checklist
    {
        return Checklist::factory()->create([
            'household_id' => $this->household->id,
            'name' => 'Einkauf',
            'kind' => ListKind::Shopping,
            'list_provider' => $pushToBring ? 'fahoba/bring' : null,
            'position' => 0,
        ]);
    }

    public function test_no_access_without_token(): void
    {
        $this->getJson('/api/wall/meals')->assertUnauthorized();
    }

    public function test_week_plan_shows_entries_in_range(): void
    {
        MealPlanEntry::factory()->create([
            'household_id' => $this->household->id,
            'planned_on' => '2026-09-16',
            'kind' => MealKind::Dinner,
            'title' => 'Linsensuppe',
        ]);

        $this->withToken($this->token)
            ->getJson('/api/wall/meals?from=2026-09-14&to=2026-09-20')
            ->assertOk()
            ->assertJsonCount(1, 'meals')
            ->assertJsonPath('meals.0.title', 'Linsensuppe')
            ->assertJsonPath('meals.0.kind_label', 'Abend');
    }

    public function test_entry_outside_the_range_is_missing(): void
    {
        MealPlanEntry::factory()->create([
            'household_id' => $this->household->id,
            'planned_on' => '2026-10-30',
        ]);

        $this->withToken($this->token)
            ->getJson('/api/wall/meals?from=2026-09-14&to=2026-09-20')
            ->assertOk()
            ->assertJsonCount(0, 'meals');
    }

    public function test_foreign_household_stays_invisible(): void
    {
        MealPlanEntry::factory()->create([
            'household_id' => Household::factory()->create()->id,
            'planned_on' => '2026-09-16',
            'title' => 'Fremdes Essen',
        ]);

        $this->withToken($this->token)
            ->getJson('/api/wall/meals?from=2026-09-14&to=2026-09-20')
            ->assertOk()
            ->assertJsonCount(0, 'meals');
    }

    public function test_create_entry_with_recipe(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/wall/meals', [
                'planned_on' => '2026-09-16',
                'kind' => 'dinner',
                'title' => 'Gemüselasagne',
                'recipe_id' => '42',
                'recipe_provider' => 'tandoor',
            ])
            ->assertCreated()
            ->assertJsonPath('recipe_id', '42');

        $this->assertDatabaseHas('meal_plan_entries', [
            'title' => 'Gemüselasagne',
            'recipe_id' => '42',
            'recipe_provider' => 'tandoor',
        ]);
    }

    /** A household that never chose cooks both; the plan says which meals it offers. */
    public function test_the_plan_names_the_meals_the_household_cooks(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/wall/meals')
            ->assertOk()
            ->assertJsonPath('kinds.0.value', 'lunch')
            ->assertJsonPath('kinds.1.value', 'dinner');

        $this->household->update(['meal_kinds' => ['dinner']]);

        $this->withToken($this->token)
            ->getJson('/api/wall/meals')
            ->assertJsonCount(1, 'kinds')
            ->assertJsonPath('kinds.0.value', 'dinner')
            ->assertJsonPath('kinds.0.label', 'Abend');
    }

    /** Only what the household cooks can be planned — an old tablet included. */
    public function test_a_meal_the_household_does_not_cook_is_refused(): void
    {
        $this->household->update(['meal_kinds' => ['dinner']]);

        $this->withToken($this->token)
            ->postJson('/api/wall/meals', ['planned_on' => '2026-09-17', 'kind' => 'lunch', 'title' => 'Reste'])
            ->assertJsonValidationErrors('kind');

        $this->withToken($this->token)
            ->postJson('/api/wall/meals', ['planned_on' => '2026-09-17', 'kind' => 'dinner', 'title' => 'Reste'])
            ->assertCreated();
    }

    /** „Reste" and „Essen bei Oma" have no recipe and must still be plannable. */
    public function test_create_entry_without_recipe(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/wall/meals', [
                'planned_on' => '2026-09-17',
                'kind' => 'lunch',
                'title' => 'Reste',
            ])
            ->assertCreated()
            ->assertJsonPath('recipe_id', null)
            ->assertJsonPath('kind_label', 'Mittag');
    }

    public function test_unknown_meal_kind_is_rejected(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/wall/meals', [
                'planned_on' => '2026-09-17',
                'kind' => 'brunch',
                'title' => 'Reste',
            ])
            ->assertStatus(422);
    }

    public function test_delete_entry(): void
    {
        $entry = MealPlanEntry::factory()->create(['household_id' => $this->household->id]);

        $this->withToken($this->token)
            ->deleteJson("/api/wall/meals/{$entry->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('meal_plan_entries', ['id' => $entry->id]);
    }

    public function test_ingredients_land_on_the_shopping_list(): void
    {
        $this->configureTandoor();
        $list = $this->shoppingList();

        Http::fake(['tandoor.test/api/recipe/42/' => Http::response($this->recipeResponse([
            ['name' => 'Zwiebel', 'amount' => 2.0, 'unit' => 'Stück'],
            ['name' => 'Muskat'],
        ]))]);

        $entry = MealPlanEntry::factory()->withRecipe()->create([
            'household_id' => $this->household->id,
        ]);

        $this->withToken($this->token)
            ->postJson("/api/wall/meals/{$entry->id}/ingredients")
            ->assertOk()
            ->assertJsonPath('added', 2);

        $this->assertDatabaseHas('list_items', [
            'list_id' => $list->id,
            'title' => 'Zwiebel',
            'quantity' => '2 Stück',
        ]);

        // Without amount and unit the field stays empty instead of "0".
        $this->assertDatabaseHas('list_items', [
            'title' => 'Muskat',
            'quantity' => null,
        ]);
    }

    public function test_open_existing_ingredient_is_not_added_twice(): void
    {
        $this->configureTandoor();
        $list = $this->shoppingList();
        $list->items()->create(['title' => 'zwiebel', 'position' => 0]);

        Http::fake(['tandoor.test/api/recipe/42/' => Http::response($this->recipeResponse([
            ['name' => 'Zwiebel', 'amount' => 2.0, 'unit' => 'Stück'],
            ['name' => 'Muskat'],
        ]))]);

        $entry = MealPlanEntry::factory()->withRecipe()->create([
            'household_id' => $this->household->id,
        ]);

        $this->withToken($this->token)
            ->postJson("/api/wall/meals/{$entry->id}/ingredients")
            ->assertOk()
            ->assertJsonPath('added', 1);

        // Upper and lower case do not decide whether something ends up on the
        // list twice.
        $this->assertSame(1, $list->items()->whereRaw('LOWER(title) = ?', ['zwiebel'])->count());
        $this->assertSame(2, $list->items()->count());
    }

    public function test_entry_without_recipe_has_no_ingredients(): void
    {
        $entry = MealPlanEntry::factory()->create(['household_id' => $this->household->id]);

        $this->withToken($this->token)
            ->postJson("/api/wall/meals/{$entry->id}/ingredients")
            ->assertStatus(422);
    }

    /**
     * Without configured access the call may fail, but not with a server
     * error: the tablet must be able to show the hint.
     */
    /** Another family's Tandoor is not a recipe source for this one. */
    public function test_a_foreign_tandoor_connection_is_not_used(): void
    {
        $this->configureTandoor(Household::factory()->create());

        $this->withToken($this->token)
            ->getJson('/api/wall/recipes')
            ->assertSuccessful()
            ->assertJsonMissing(['provider' => 'tandoor']);
    }

    public function test_without_tandoor_access_a_clear_rejection(): void
    {
        $this->shoppingList();

        $entry = MealPlanEntry::factory()->withRecipe()->create([
            'household_id' => $this->household->id,
        ]);

        $this->withToken($this->token)
            ->postJson("/api/wall/meals/{$entry->id}/ingredients")
            ->assertStatus(422);
    }

    /**
     * The builtin book is always there — even without any external service.
     *
     * Before the builtin recipe book this query reported „nicht
     * eingerichtet" and the dialog stayed empty; a household without Tandoor
     * could not store any ingredients.
     */
    public function test_recipe_search_knows_the_builtin_book(): void
    {
        Http::preventStrayRequests();

        Recipe::factory()->create([
            'household_id' => $this->household->id,
            'title' => 'Kaiserschmarrn',
        ]);

        $this->withToken($this->token)
            ->getJson('/api/wall/recipes')
            ->assertOk()
            ->assertJsonPath('providers.0.key', 'builtin')
            ->assertJsonPath('recipes.0.name', 'Kaiserschmarrn')
            ->assertJsonPath('recipes.0.provider', 'builtin');
    }

    public function test_ingredients_of_the_builtin_recipe_land_on_the_list(): void
    {
        Http::preventStrayRequests();
        $this->shoppingList();

        $recipe = Recipe::factory()->create([
            'household_id' => $this->household->id,
            'ingredients' => [['name' => 'Mehl', 'spec' => '500 g']],
            'servings' => 4,
        ]);

        $entry = MealPlanEntry::factory()
            ->withRecipe((string) $recipe->id, 'builtin')
            ->create(['household_id' => $this->household->id]);

        $this->withToken($this->token)
            ->postJson("/api/wall/meals/{$entry->id}/ingredients")
            ->assertOk();

        // Nobody lives here yet, which still counts as one portion of four.
        $this->assertDatabaseHas('list_items', ['title' => 'Mehl', 'quantity' => '125 g']);
    }

    /** The plan shows the planned recipe's picture; an entry without a recipe has none. */
    public function test_the_plan_carries_the_recipe_picture(): void
    {
        Http::preventStrayRequests();

        $recipe = Recipe::factory()->create([
            'household_id' => $this->household->id,
            'photo_path' => 'recipes/kaiserschmarrn.jpg',
        ]);

        MealPlanEntry::factory()
            ->withRecipe((string) $recipe->id, 'builtin')
            ->create(['household_id' => $this->household->id, 'planned_on' => '2026-09-16', 'kind' => MealKind::Dinner]);
        MealPlanEntry::factory()
            ->create(['household_id' => $this->household->id, 'planned_on' => '2026-09-16', 'kind' => MealKind::Lunch, 'title' => 'Reste']);

        $response = $this->withToken($this->token)
            ->getJson('/api/wall/meals?from=2026-09-14&to=2026-09-20')
            ->assertOk()
            ->assertJsonPath('meals.0.title', 'Reste')
            ->assertJsonPath('meals.0.photo', null);

        $this->assertStringStartsWith("/api/wall/recipe-book/builtin/{$recipe->id}/photo", (string) $response->json('meals.1.photo'));
    }

    /** A recipe source that is down costs the pictures, not the plan. */
    public function test_a_source_outage_leaves_the_plan_without_pictures(): void
    {
        $this->configureTandoor();
        Http::fake(['tandoor.test/*' => Http::response(status: 500)]);

        MealPlanEntry::factory()
            ->withRecipe('42', 'tandoor')
            ->create(['household_id' => $this->household->id, 'planned_on' => '2026-09-16', 'title' => 'Gemüselasagne']);

        $this->withToken($this->token)
            ->getJson('/api/wall/meals?from=2026-09-14&to=2026-09-20')
            ->assertOk()
            ->assertJsonPath('meals.0.title', 'Gemüselasagne')
            ->assertJsonPath('meals.0.photo', null);
    }

    public function test_recipe_search_returns_results(): void
    {
        $this->configureTandoor();

        Http::fake(['tandoor.test/api/recipe/*' => Http::response([
            'results' => [['id' => 42, 'name' => 'Gemüselasagne']],
        ])]);

        $this->withToken($this->token)
            ->getJson('/api/wall/recipes?q=lasagne')
            ->assertOk()
            ->assertJsonPath('recipes.0.name', 'Gemüselasagne')
            ->assertJsonPath('recipes.0.provider', 'tandoor');
    }

    /** Alphabetically „dinner“ would come before „lunch“ — the order of the day is shown. */
    public function test_lunch_comes_before_dinner(): void
    {
        foreach (['dinner' => 'Kaiserschmarrn', 'lunch' => 'Reste'] as $kind => $title) {
            $this->withToken($this->token)
                ->postJson('/api/wall/meals', ['planned_on' => '2026-09-16', 'kind' => $kind, 'title' => $title])
                ->assertCreated();
        }

        $this->withToken($this->token)
            ->getJson('/api/wall/meals?from=2026-09-16&to=2026-09-16')
            ->assertOk()
            ->assertJsonPath('meals.0.title', 'Reste')
            ->assertJsonPath('meals.1.title', 'Kaiserschmarrn');
    }
}

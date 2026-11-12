<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\MealKind;
use App\Enums\PersonRole;
use App\Http\Controllers\Controller;
use App\Integrations\ProviderRegistry;
use App\Models\Household;
use App\Models\MealPlanEntry;
use App\Models\Person;
use App\Services\PortionFactor;
use App\Services\Recipes\RecipeBook;
use App\Services\Recipes\RecipeShopping;
use App\Support\HouseholdContext;
use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Recipes\Ingredient;
use Fahoba\PluginApi\Recipes\RecipeProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The weekly meal plan on the tablet.
 *
 * The plan belongs to Fahoba, the recipes come from the recipe provider. If
 * that one is down the plan stays fully readable — the name is stored on the
 * entry, not just the recipe ID.
 */
final class WallMealController extends Controller
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly RecipeShopping $shopping,
        private readonly PortionFactor $portions,
        private readonly HouseholdContext $context,
        private readonly RecipeBook $book,
    ) {}

    /** @var Collection<int, Person>|null */
    private ?Collection $people = null;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($data['from'])
            ? CarbonImmutable::parse($data['from'])
            : CarbonImmutable::now()->startOfWeek();

        $to = isset($data['to']) ? CarbonImmutable::parse($data['to']) : $from->addDays(13);

        $entries = MealPlanEntry::query()
            ->whereBetween('planned_on', [$from->toDateString(), $to->toDateString()])
            ->orderBy('planned_on')
            // In the order of the day, not alphabetically: "dinner" would come before "lunch".
            ->orderByRaw("case kind when 'lunch' then 0 else 1 end")
            ->orderBy('position')
            ->get();

        $photos = $this->photos($entries);
        $entries = $entries
            ->map(fn (MealPlanEntry $entry): array => [
                ...$this->entry($entry),
                'photo' => $photos[$entry->recipe_provider.':'.$entry->recipe_id] ?? null,
            ])
            ->all();

        return response()->json([
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'meals' => $entries,
            // The meals the household cooks: the plan offers only these.
            'kinds' => array_map(static fn (MealKind $kind): array => [
                'value' => $kind->value,
                'label' => $kind->label(),
            ], $this->kinds()),
            // For choosing who eats along; the portions follow from them.
            'people' => $this->people()->map(static fn (Person $person): array => [
                'id' => $person->id,
                'name' => $person->name,
                'color' => $person->color,
                'guest' => $person->role === PersonRole::Guest,
            ])->values()->all(),
        ]);
    }

    /**
     * The recipe picker for the dialog — from every configured source.
     *
     * Each hit names its origin: without it "17" in the built-in book could
     * not be told apart from "17" in Tandoor.
     */
    public function recipes(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $hits = [];

        foreach ($this->registry->recipeProviders() as $key => $provider) {
            foreach ($provider->search($data['q'] ?? '') as $recipe) {
                $hits[] = [
                    'id' => (string) $recipe['id'],
                    'name' => (string) $recipe['name'],
                    'provider' => $key,
                    'provider_label' => $provider->label(),
                ];
            }
        }

        return response()->json([
            'providers' => array_map(
                static fn (RecipeProvider $provider): array => [
                    'key' => $provider->key(),
                    'label' => $provider->label(),
                ],
                array_values($this->registry->recipeProviders()),
            ),
            'recipes' => $hits,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'planned_on' => ['required', 'date'],
            // Only what the household cooks; an old tablet must not plan a lunch nobody makes.
            'kind' => ['required', Rule::in(array_map(static fn (MealKind $kind): string => $kind->value, $this->kinds()))],
            'title' => ['required', 'string', 'max:255'],
            'recipe_id' => ['nullable', 'string', 'max:255'],
            'recipe_provider' => ['nullable', 'string', 'max:64', 'required_with:recipe_id'],
            'notes' => ['nullable', 'string', 'max:255'],
            'eaters' => ['nullable', 'array', 'max:50'],
            'eaters.*' => ['integer'],
        ]);

        $entry = MealPlanEntry::create([
            'eaters' => $this->eaters($data['eaters'] ?? null),
            'planned_on' => CarbonImmutable::parse($data['planned_on'])->toDateString(),
            'kind' => $data['kind'],
            'title' => $data['title'],
            'recipe_id' => $data['recipe_id'] ?? null,
            'recipe_provider' => $data['recipe_provider'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json($this->entry($entry), 201);
    }

    /** Who eats along — the rest of an entry is replaced by planning anew. */
    public function update(Request $request, MealPlanEntry $meal): JsonResponse
    {
        $data = $request->validate([
            'eaters' => ['present', 'nullable', 'array', 'max:50'],
            'eaters.*' => ['integer'],
        ]);

        $meal->update(['eaters' => $this->eaters($data['eaters'])]);

        return response()->json($this->entry($meal));
    }

    public function destroy(MealPlanEntry $meal): JsonResponse
    {
        $meal->delete();

        return response()->json(status: 204);
    }

    /**
     * Puts the planned recipe's ingredients on the shopping list.
     *
     * Deliberately on request and not when planning: half of it is often at
     * home already, and a list that fills itself stops being read.
     */
    public function ingredients(MealPlanEntry $meal): JsonResponse
    {
        if (! $meal->hasRecipe()) {
            throw ValidationException::withMessages([
                'meal' => __('This entry has no recipe.'),
            ]);
        }

        return response()->json($this->shopping->put($this->planned($meal)));
    }

    /**
     * Every planned recipe of a period at once, equal ingredients summed —
     * one trip to the shop for the week.
     */
    public function shopping(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $from = CarbonImmutable::parse($data['from']);

        if ($from->diffInDays(CarbonImmutable::parse($data['until'])) > 31) {
            throw ValidationException::withMessages(['until' => __('At most one month at a time.')]);
        }

        $meals = MealPlanEntry::query()
            ->whereBetween('planned_on', [$data['from'], $data['until']])
            ->whereNotNull('recipe_id')
            ->whereNotNull('recipe_provider')
            ->orderBy('planned_on')
            ->get();

        if ($meals->isEmpty()) {
            throw ValidationException::withMessages(['meal' => __('No recipe is planned in this period.')]);
        }

        return response()->json($this->shopping->put(
            $meals->flatMap(fn (MealPlanEntry $meal): array => $this->planned($meal))->values()->all(),
        ));
    }

    /**
     * The recipe's ingredients for whoever eats it.
     *
     * @return list<Ingredient>
     */
    private function planned(MealPlanEntry $meal): array
    {
        $provider = $this->registry->recipeProviders()[$meal->recipe_provider] ?? null;

        return $provider === null
            ? []
            : $this->shopping->ingredients($provider, (string) $meal->recipe_id, $this->servings($meal));
    }

    /** @return array<string, mixed> */
    private function entry(MealPlanEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'date' => $entry->planned_on->toDateString(),
            'kind' => $entry->kind->value,
            'kind_label' => $entry->kind->label(),
            'title' => $entry->title,
            'notes' => $entry->notes,
            'recipe_id' => $entry->recipe_id,
            'recipe_provider' => $entry->recipe_provider,
            'eaters' => $entry->eaters,
            'servings' => $this->servings($entry),
        ];
    }

    /** Portions for an entry: its eaters, or everyone. */
    private function servings(MealPlanEntry $entry): float
    {
        $people = is_array($entry->eaters)
            ? $this->people()->whereIn('id', $entry->eaters)
            : $this->everyone();

        return $this->portions->servings($people, $entry->planned_on);
    }

    /**
     * Only people of the household; everyone at once is stored as null, so
     * that someone added later eats along too. Guests count only when chosen.
     *
     * @param  array<int, mixed>|null  $ids
     * @return list<int>|null
     */
    private function eaters(?array $ids): ?array
    {
        if ($ids === null) {
            return null;
        }

        $known = $this->people()->pluck('id')->map(intval(...));
        $chosen = $known->intersect(array_map(intval(...), $ids))->sort()->values()->all();

        if ($chosen === []) {
            throw ValidationException::withMessages(['eaters' => __('Somebody has to eat.')]);
        }

        $everyone = $this->everyone()->pluck('id')->map(intval(...))->sort()->values()->all();

        return $chosen === $everyone ? null : $chosen;
    }

    /**
     * The pictures of the planned recipes, one lookup per source. A source
     * that is down costs the pictures, never the plan: the plan stays
     * readable from what Fahoba stored itself.
     *
     * @param  Collection<int, MealPlanEntry>  $entries
     * @return array<string, string|null>
     */
    private function photos(Collection $entries): array
    {
        $refs = $entries
            ->filter(fn (MealPlanEntry $entry): bool => $entry->hasRecipe())
            ->map(fn (MealPlanEntry $entry): array => [
                'provider' => (string) $entry->recipe_provider,
                'id' => (string) $entry->recipe_id,
                'title' => $entry->title,
            ])
            ->unique(fn (array $ref): string => $ref['provider'].':'.$ref['id'])
            ->values()
            ->all();

        if ($refs === []) {
            return [];
        }

        try {
            $cards = $this->book->cards($refs);
        } catch (Throwable $error) {
            report($error);

            return [];
        }

        $photos = [];

        foreach ($cards as $card) {
            $photos[$card['provider'].':'.$card['id']] = $card['photo'];
        }

        return $photos;
    }

    /** @return list<MealKind> */
    private function kinds(): array
    {
        return Household::query()->find($this->context->id())?->mealKinds() ?? MealKind::cases();
    }

    /** @return Collection<int, Person> */
    private function people(): Collection
    {
        return $this->people ??= Person::query()->ordered()->get();
    }

    /**
     * Who "everyone" means: the family without its guests.
     *
     * @return Collection<int, Person>
     */
    private function everyone(): Collection
    {
        return $this->people()->reject(fn (Person $person): bool => $person->role === PersonRole::Guest)->values();
    }
}

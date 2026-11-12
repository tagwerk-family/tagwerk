<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Exceptions\InvalidImage;
use App\Http\Controllers\Controller;
use App\Integrations\ProviderRegistry;
use App\Integrations\Recipes\Builtin\BuiltinRecipeProvider;
use App\Models\Recipe;
use App\Models\RecipeFavorite;
use App\Services\PhotoImage;
use App\Services\Recipes\RecipeBook;
use App\Services\Recipes\RecipeImport;
use App\Services\Recipes\RecipeShopping;
use App\Support\Abilities;
use App\Support\Recipes\RecipeInput;
use Fahoba\PluginApi\Recipes\DescribesRecipes;
use Fahoba\PluginApi\Recipes\Ingredient;
use Fahoba\PluginApi\Recipes\RecipeDetail;
use Fahoba\PluginApi\Recipes\RecipeProvider;
use Fahoba\PluginApi\Recipes\RecipeQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The recipe book as an area of its own on the wall and the phone.
 *
 * Separate from the meal plan because a recipe is also needed without a
 * planned meal: look up what goes in and put the ingredients on the shopping
 * list. Whoever does not need it switches the area off.
 */
final class WallRecipeController extends Controller
{
    /** A provider key in a path: built in (`tandoor`) or a plugin's (`fahoba/kochwiki`). */
    public const PROVIDER = '[a-z0-9-]+(?:/[a-z0-9-]+)?';

    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly Abilities $abilities,
        private readonly RecipeBook $book,
        private readonly RecipeShopping $shopping,
    ) {}

    /**
     * Every source at once. A filter leaves out the sources that cannot
     * filter — a recipe without a known time is not "under 30 minutes".
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->query($request);
        $list = $request->validate(['list' => ['nullable', Rule::in(['favorites', 'often', 'forgotten', 'own'])]])['list'] ?? null;
        $categories = [];

        if ($list !== null && $list !== 'own') {
            // The household's own lists: narrowed here, by what a card knows.
            $recipes = array_values(array_filter(
                $this->book->cards(match ($list) {
                    'favorites' => $this->book->favorites(),
                    'often' => $this->book->often(),
                    'forgotten' => $this->book->forgotten(),
                }),
                static fn (array $card): bool => self::matches($card, $query),
            ));
        } else {
            $recipes = [];
            // What the household wrote or copied in itself: the built-in book alone.
            $providers = $list === 'own'
                ? array_intersect_key($this->registry->recipeProviders(), [BuiltinRecipeProvider::KEY => true])
                : $this->registry->recipeProviders();

            foreach ($providers as $key => $provider) {
                if ($provider instanceof DescribesRecipes) {
                    $cards = $provider->browse($query);
                    $categories = [...$categories, ...$provider->categories()];
                } elseif ($query->filters() || $query->offset > 0) {
                    continue;
                } else {
                    $cards = array_map(static fn (array $recipe): array => [
                        'id' => (string) $recipe['id'],
                        'name' => (string) $recipe['name'],
                        'minutes' => null,
                        'difficulty' => null,
                        'vegetarian' => null,
                        'photo' => null,
                    ], $provider->search($query->term, $query->limit));
                }

                foreach ($cards as $card) {
                    $recipes[] = [...$card, 'provider' => $key, 'provider_label' => $provider->label()];
                }
            }
        }

        $categories = array_values(array_unique($categories));
        sort($categories, SORT_LOCALE_STRING);

        return response()->json([
            'recipes' => $this->withFavorites($recipes),
            'categories' => $categories,
            'can' => ['edit' => $this->abilities->allows($request->user(), Permission::RecipesEdit)],
        ]);
    }

    /** A handful of ideas; another `seed` gives others. */
    public function suggestions(Request $request): JsonResponse
    {
        $data = $request->validate(['seed' => ['nullable', 'integer', 'min:0', 'max:2147483647']]);

        return response()->json([
            'recipes' => $this->withFavorites($this->book->suggestions((int) ($data['seed'] ?? random_int(0, 2147483647)))),
        ]);
    }

    /** The title is kept for sources that cannot look a recipe up by id. */
    public function favor(Request $request, string $provider, string $recipe): JsonResponse
    {
        $source = $this->provider($provider);
        $title = $source instanceof DescribesRecipes
            ? ($source->recipe($recipe) ?? abort(404))->title
            : (string) $request->validate(['title' => ['required', 'string', 'max:255']])['title'];

        RecipeFavorite::query()->updateOrCreate(
            ['provider' => $provider, 'recipe_id' => $recipe],
            ['title' => $title],
        );

        return response()->json(['favorite' => true]);
    }

    public function unfavor(string $provider, string $recipe): JsonResponse
    {
        RecipeFavorite::query()->where('provider', $provider)->where('recipe_id', $recipe)->delete();

        return response()->json(['favorite' => false]);
    }

    /**
     * A whole recipe. `servings` scales the ingredients — the server formats
     * them, so that "1 ½ TL" reads the same on the wall, the phone and the
     * shopping list.
     */
    public function show(Request $request, string $provider, string $recipe): JsonResponse
    {
        $data = $request->validate(['servings' => ['nullable', 'integer', 'min:1', 'max:60']]);
        $source = $this->provider($provider);

        if (! $source instanceof DescribesRecipes) {
            return response()->json(['recipe' => [
                'provider' => $provider,
                'provider_label' => $source->label(),
                'id' => $recipe,
                'title' => null,
                'ingredients' => array_map(
                    static fn (array $ingredient): array => [...$ingredient, 'note' => null, 'group' => null],
                    $source->ingredients($recipe),
                ),
                'favorite' => isset($this->book->favoriteKeys()[$provider.':'.$recipe]),
                'can' => ['edit' => false],
            ]]);
        }

        $detail = $source->recipe($recipe) ?? abort(404);

        return response()->json(['recipe' => $this->detail(
            $request,
            $provider,
            $source,
            $detail,
            (int) ($data['servings'] ?? $detail->servings),
        )]);
    }

    /** Scaled to `servings` when given, without the household's staples. */
    public function ingredients(Request $request, string $provider, string $recipe): JsonResponse
    {
        $data = $request->validate(['servings' => ['nullable', 'integer', 'min:1', 'max:60']]);
        $ingredients = $this->shopping->ingredients(
            $this->provider($provider),
            $recipe,
            isset($data['servings']) ? (float) $data['servings'] : null,
        );

        if ($ingredients === []) {
            throw ValidationException::withMessages(['recipe' => __('The ingredients could not be loaded.')]);
        }

        return response()->json($this->shopping->put($ingredients));
    }

    public function store(Request $request): JsonResponse
    {
        $recipe = Recipe::create(RecipeInput::attributes($request->validate(RecipeInput::rules())));

        return $this->saved($request, $recipe, 201);
    }

    /** One recipe from a recipe site, saved and then corrected in the editor. */
    public function link(Request $request, RecipeImport $import): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'string', 'url:http,https', 'max:2048']]);

        return $this->saved($request, $import->fromLink($data['url']), 201);
    }

    public function update(Request $request, Recipe $recipe): JsonResponse
    {
        $recipe->update(RecipeInput::attributes($request->validate(RecipeInput::rules(partial: true))));

        return $this->saved($request, $recipe);
    }

    public function destroy(Recipe $recipe): Response
    {
        $recipe->delete();

        return response()->noContent();
    }

    /** Redrawn like the photo frame's pictures: no EXIF, no location. */
    public function storePhoto(Request $request, Recipe $recipe, PhotoImage $images): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:20480'],
        ]);

        try {
            $path = $images->store((string) $request->file('photo')?->get(), Recipe::PHOTO_DIRECTORY);
        } catch (InvalidImage $error) {
            throw ValidationException::withMessages(['photo' => $error->getMessage()]);
        }

        $recipe->update(['photo_path' => $path]);

        return $this->saved($request, $recipe);
    }

    public function destroyPhoto(Request $request, Recipe $recipe): JsonResponse
    {
        $recipe->update(['photo_path' => null]);

        return $this->saved($request, $recipe);
    }

    public function photo(Recipe $recipe): BinaryFileResponse
    {
        return $this->image((string) $recipe->photo_path);
    }

    /** A photo of the recipe itself, so a handwritten card needs no typing. */
    public function storePage(Request $request, Recipe $recipe, PhotoImage $images): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:20480'],
        ]);

        if (count($recipe->pagePaths()) >= Recipe::MAX_PAGES) {
            throw ValidationException::withMessages(['photo' => __('A recipe holds at most :count pages.', ['count' => Recipe::MAX_PAGES])]);
        }

        try {
            $path = $images->store((string) $request->file('photo')?->get(), Recipe::PHOTO_DIRECTORY);
        } catch (InvalidImage $error) {
            throw ValidationException::withMessages(['photo' => $error->getMessage()]);
        }

        $recipe->update(['pages' => [...$recipe->pagePaths(), $path]]);

        return $this->saved($request, $recipe);
    }

    public function destroyPage(Request $request, Recipe $recipe, string $page): JsonResponse
    {
        $paths = $recipe->pagePaths();
        $kept = array_values(array_filter($paths, static fn (string $path): bool => Recipe::pageId($path) !== $page));

        abort_if(count($kept) === count($paths), 404);

        $recipe->update(['pages' => $kept]);

        return $this->saved($request, $recipe);
    }

    public function page(Recipe $recipe, string $page): BinaryFileResponse
    {
        foreach ($recipe->pagePaths() as $path) {
            if (Recipe::pageId($path) === $page) {
                return $this->image($path);
            }
        }

        abort(404);
    }

    private function image(string $path): BinaryFileResponse
    {
        abort_if($path === '' || ! Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    private function saved(Request $request, Recipe $recipe, int $status = 200): JsonResponse
    {
        $detail = $recipe->refresh()->detail();

        return response()->json(['recipe' => $this->detail(
            $request,
            BuiltinRecipeProvider::KEY,
            $this->provider(BuiltinRecipeProvider::KEY),
            $detail,
            $detail->servings,
        )], $status);
    }

    /** @return array<string, mixed> */
    private function detail(Request $request, string $key, RecipeProvider $source, RecipeDetail $detail, int $servings): array
    {
        $factor = $servings / max(1, $detail->servings);
        $own = $key === BuiltinRecipeProvider::KEY;

        return [
            'provider' => $key,
            'provider_label' => $source->label(),
            'id' => $detail->id,
            'title' => $detail->title,
            'intro' => $detail->intro,
            'steps' => $detail->steps,
            'servings' => $servings,
            'base_servings' => $detail->servings,
            'minutes' => $detail->minutes,
            'difficulty' => $detail->difficulty,
            'categories' => $detail->categories,
            'vegetarian' => $detail->vegetarian,
            'photo' => $detail->photo,
            'source_url' => $detail->sourceUrl,
            'license' => $detail->license,
            'pages' => $detail->pages,
            'ingredients' => array_map(static fn (Ingredient $ingredient): array => [
                'name' => $ingredient->name,
                'spec' => $ingredient->scaled($factor)->spec(),
                'note' => $ingredient->note,
                'group' => $ingredient->group,
            ], $detail->ingredients),
            // For the edit form only: the lines as they were typed.
            'ingredient_text' => $own ? Ingredient::listText($detail->ingredients) : null,
            'favorite' => isset($this->book->favoriteKeys()[$key.':'.$detail->id]),
            'can' => ['edit' => $own && $this->abilities->allows($request->user(), Permission::RecipesEdit)],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @return list<array<string, mixed>>
     */
    private function withFavorites(array $cards): array
    {
        $favorites = $this->book->favoriteKeys();

        return array_map(
            static fn (array $card): array => [...$card, 'favorite' => isset($favorites[$card['provider'].':'.$card['id']])],
            $cards,
        );
    }

    /** @param  array<string, mixed>  $card */
    private static function matches(array $card, RecipeQuery $query): bool
    {
        return ($query->term === '' || mb_stripos((string) $card['name'], trim($query->term)) !== false)
            && ($query->maxMinutes === null || ($card['minutes'] !== null && $card['minutes'] <= $query->maxMinutes))
            && (! $query->vegetarian || $card['vegetarian'] === true)
            && ($query->difficulty === null || $card['difficulty'] === $query->difficulty);
    }

    private function query(Request $request): RecipeQuery
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'max_minutes' => ['nullable', 'integer', 'min:1', 'max:2880'],
            'vegetarian' => ['nullable', 'boolean'],
            'difficulty' => ['nullable', Rule::in(RecipeInput::DIFFICULTIES)],
            'category' => ['nullable', 'string', 'max:60'],
            'offset' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        return new RecipeQuery(
            term: (string) ($data['q'] ?? ''),
            maxMinutes: isset($data['max_minutes']) ? (int) $data['max_minutes'] : null,
            vegetarian: (bool) ($data['vegetarian'] ?? false),
            difficulty: $data['difficulty'] ?? null,
            category: $data['category'] ?? null,
            offset: (int) ($data['offset'] ?? 0),
        );
    }

    /**
     * A source that is not set up does not exist for this household — hence
     * 404 and no error message about credentials.
     */
    private function provider(string $key): RecipeProvider
    {
        return $this->registry->recipeProviders()[$key] ?? abort(404);
    }
}

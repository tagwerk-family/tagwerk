<?php

declare(strict_types=1);

namespace Tests\Feature\Recipes;

use App\Models\Household;
use App\Models\Person;
use App\Models\Recipe;
use App\Models\User;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Recipes\Ingredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The own recipe book on wall and phone: browsing, a whole recipe, writing.
 */
final class RecipeBookTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->household = Household::factory()->create();
    }

    private function phone(bool $child = false, ?Household $household = null): string
    {
        $household ??= $this->household;
        $person = $child
            ? Person::factory()->child()->for($household)->create()
            : Person::factory()->for($household)->create();

        return User::factory()->create([
            'household_id' => $household->id,
            'person_id' => $person->id,
        ])->createToken('phone')->plainTextToken;
    }

    /** @param  list<string>  $lines */
    private function recipe(string $title, array $lines = ['500 g Mehl'], array $attributes = []): Recipe
    {
        return Recipe::factory()->create([
            'household_id' => $this->household->id,
            'title' => $title,
            'ingredients' => array_map(static fn (string $line): array => Ingredient::parse($line)?->toArray() ?? [], $lines),
            ...$attributes,
        ]);
    }

    public function test_a_parent_writes_a_recipe_with_typed_ingredients(): void
    {
        $this->withToken($this->phone())
            ->postJson('/api/wall/recipe-book', [
                'title' => 'Palatschinken',
                'servings' => 4,
                'minutes' => 25,
                'difficulty' => 'easy',
                'vegetarian' => true,
                'categories' => ['Süßspeise', ' ', 'Süßspeise'],
                'ingredient_text' => "Für den Teig:\n250 ml Milch\n2 Eier\n100 g Mehl, glatt\n\nZum Füllen:\nMarmelade",
                'steps' => "1. Alles verrühren.\n2. Dünn ausbacken.",
            ])
            ->assertCreated()
            ->assertJsonPath('recipe.title', 'Palatschinken')
            ->assertJsonPath('recipe.categories', ['Süßspeise'])
            ->assertJsonPath('recipe.ingredients.0', ['name' => 'Milch', 'spec' => '250 ml', 'note' => null, 'group' => 'Für den Teig'])
            ->assertJsonPath('recipe.ingredients.2.note', 'glatt')
            ->assertJsonPath('recipe.ingredients.3.group', 'Zum Füllen')
            ->assertJsonPath('recipe.ingredient_text', "Für den Teig:\n250 ml Milch\n2 Eier\n100 g Mehl, glatt\nZum Füllen:\nMarmelade")
            ->assertJsonPath('recipe.can.edit', true);
    }

    /** A recipe for six is not a recipe for four with more salt. */
    public function test_ingredients_scale_with_the_servings(): void
    {
        $recipe = $this->recipe('Kaiserschmarrn', ['250 g Mehl', '4 Eier', '1 Prise Salz', 'Staubzucker'], ['servings' => 4]);

        $this->withToken($this->phone())
            ->getJson("/api/wall/recipe-book/builtin/{$recipe->id}?servings=6")
            ->assertOk()
            ->assertJsonPath('recipe.servings', 6)
            ->assertJsonPath('recipe.base_servings', 4)
            ->assertJsonPath('recipe.ingredients.0.spec', '375 g')
            ->assertJsonPath('recipe.ingredients.1.spec', '6')
            ->assertJsonPath('recipe.ingredients.2.spec', '1 ½ Prise')
            ->assertJsonPath('recipe.ingredients.3.spec', null);
    }

    public function test_a_child_reads_but_does_not_write(): void
    {
        $recipe = $this->recipe('Kaiserschmarrn');
        $token = $this->phone(child: true);

        $this->withToken($token)
            ->getJson("/api/wall/recipe-book/builtin/{$recipe->id}")
            ->assertOk()
            ->assertJsonPath('recipe.can.edit', false);

        $this->withToken($token)->postJson('/api/wall/recipe-book', ['title' => 'Nur Schokolade'])->assertForbidden();
        $this->withToken($token)->patchJson("/api/wall/recipe-book/builtin/{$recipe->id}", ['title' => 'x'])->assertForbidden();
        $this->withToken($token)->deleteJson("/api/wall/recipe-book/builtin/{$recipe->id}")->assertForbidden();
    }

    public function test_another_households_recipe_is_not_found(): void
    {
        $recipe = $this->recipe('Kaiserschmarrn');
        $stranger = $this->phone(household: Household::factory()->create());

        $this->withToken($stranger)->getJson("/api/wall/recipe-book/builtin/{$recipe->id}")->assertNotFound();
        $this->withToken($stranger)->patchJson("/api/wall/recipe-book/builtin/{$recipe->id}", ['title' => 'x'])->assertNotFound();
        $this->withToken($stranger)->get("/api/wall/recipe-book/builtin/{$recipe->id}/photo")->assertNotFound();
    }

    public function test_search_finds_word_forms_parts_and_ingredients(): void
    {
        $this->recipe('Semmelknödel', ['4 Semmeln', '2 Eier']);
        $this->recipe('Erdäpfelsuppe', ['1 kg Erdäpfel', '1 Zwiebel'], ['categories' => ['Suppe']]);
        $token = $this->phone();

        $names = fn (string $query): array => array_column(
            $this->withToken($token)->getJson('/api/wall/recipe-book?'.$query)->assertOk()->json('recipes'),
            'name',
        );

        $this->assertSame(['Semmelknödel'], $names('q=Knödel'));
        $this->assertSame(['Semmelknödel'], $names('q=Semmel'));
        $this->assertSame(['Erdäpfelsuppe'], $names('q=Suppen'));
        $this->assertSame(['Erdäpfelsuppe'], $names('q=zwiebel'));
        $this->assertSame(['Erdäpfelsuppe'], $names('q=Erdäpf'));
        $this->assertSame(['Erdäpfelsuppe'], $names('q=suppe'));
        // Operators of to_tsquery are not passed through.
        $this->assertSame(['Erdäpfelsuppe'], $names('q='.urlencode("Erdäpf')|(!:*")));
    }

    public function test_filters_narrow_the_book(): void
    {
        $this->recipe('Schnell', attributes: ['minutes' => 20, 'vegetarian' => true, 'difficulty' => 'easy', 'categories' => ['Nudeln']]);
        $this->recipe('Langsam', attributes: ['minutes' => 120, 'vegetarian' => false, 'difficulty' => 'hard', 'categories' => ['Braten']]);
        $this->recipe('Ohne Zeit');
        $token = $this->phone();

        $names = fn (string $query): array => array_column(
            $this->withToken($token)->getJson('/api/wall/recipe-book?'.$query)->assertOk()->json('recipes'),
            'name',
        );

        $this->assertSame(['Schnell'], $names('max_minutes=30'));
        $this->assertSame(['Schnell'], $names('vegetarian=1'));
        $this->assertSame(['Langsam'], $names('difficulty=hard'));
        $this->assertSame(['Langsam'], $names('category=Braten'));

        $this->withToken($token)->getJson('/api/wall/recipe-book')
            ->assertJsonPath('categories', ['Braten', 'Nudeln']);
    }

    public function test_a_photo_is_redrawn_and_served_privately(): void
    {
        $recipe = $this->recipe('Kaiserschmarrn');
        $token = $this->phone();

        $canvas = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($canvas);
        $file = UploadedFile::fake()->createWithContent('schmarrn.jpg', (string) ob_get_clean());

        $url = $this->withToken($token)
            ->post("/api/wall/recipe-book/builtin/{$recipe->id}/photo", ['photo' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('recipe.photo.small');

        app(HouseholdContext::class)->setId((int) $this->household->id);
        $path = (string) $recipe->refresh()->photo_path;

        $this->assertStringStartsWith('recipes/', $path);
        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith("/api/wall/recipe-book/builtin/{$recipe->id}/photo?v=", $url);

        $this->withToken($token)->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp');

        Auth::forgetGuards();
        $this->withToken($token)->deleteJson("/api/wall/recipe-book/builtin/{$recipe->id}")->assertNoContent();
        Storage::disk('local')->assertMissing($path);
    }

    public function test_an_svg_is_no_photo(): void
    {
        $recipe = $this->recipe('Kaiserschmarrn');

        $this->withToken($this->phone())
            ->post("/api/wall/recipe-book/builtin/{$recipe->id}/photo", [
                'photo' => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable();
    }

    /** A handwritten card is photographed instead of typed: only the title is required. */
    public function test_photos_of_the_recipe_are_added_served_and_removed(): void
    {
        $token = $this->phone();

        $recipe = $this->withToken($token)
            ->postJson('/api/wall/recipe-book', ['title' => 'Omas Linzer Torte'])
            ->assertCreated()
            ->assertJsonPath('recipe.pages', [])
            ->json('recipe');

        $pages = [];

        foreach (['front.jpg', 'back.jpg'] as $name) {
            Auth::forgetGuards();
            $pages = $this->withToken($token)
                ->post("/api/wall/recipe-book/builtin/{$recipe['id']}/pages", ['photo' => $this->jpeg($name)], ['Accept' => 'application/json'])
                ->assertOk()
                ->json('recipe.pages');
        }

        $this->assertCount(2, $pages);
        $this->assertSame("/api/wall/recipe-book/builtin/{$recipe['id']}/pages/{$pages[0]['id']}", $pages[0]['url']);

        Auth::forgetGuards();
        $this->withToken($token)->get($pages[1]['url'])->assertOk()->assertHeader('Content-Type', 'image/webp');

        app(HouseholdContext::class)->setId((int) $this->household->id);
        [$first, $second] = Recipe::query()->findOrFail($recipe['id'])->pagePaths();

        Auth::forgetGuards();
        $this->withToken($token)
            ->deleteJson($pages[0]['url'])
            ->assertOk()
            ->assertJsonCount(1, 'recipe.pages')
            ->assertJsonPath('recipe.pages.0.id', $pages[1]['id']);

        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);

        Auth::forgetGuards();
        $this->withToken($token)->deleteJson("/api/wall/recipe-book/builtin/{$recipe['id']}")->assertNoContent();
        Storage::disk('local')->assertMissing($second);
    }

    public function test_a_recipe_holds_a_limited_number_of_pages(): void
    {
        $recipe = $this->recipe('Kaiserschmarrn', attributes: [
            'pages' => array_map(static fn (int $n): string => "recipes/page{$n}.webp", range(1, Recipe::MAX_PAGES)),
        ]);

        $this->withToken($this->phone())
            ->post("/api/wall/recipe-book/builtin/{$recipe->id}/pages", ['photo' => $this->jpeg('more.jpg')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
    }

    public function test_a_child_cannot_add_pages(): void
    {
        $recipe = $this->recipe('Kaiserschmarrn');

        $this->withToken($this->phone(child: true))
            ->post("/api/wall/recipe-book/builtin/{$recipe->id}/pages", ['photo' => $this->jpeg('x.jpg')], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    private function jpeg(string $name): UploadedFile
    {
        $canvas = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($canvas);

        return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
    }
}

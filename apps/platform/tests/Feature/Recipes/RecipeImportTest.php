<?php

declare(strict_types=1);

namespace Tests\Feature\Recipes;

use App\Models\Household;
use App\Models\Person;
use App\Models\Recipe;
use App\Models\User;
use App\Services\PhotoImage;
use App\Services\Recipes\RecipeImport;
use App\Support\HouseholdContext;
use App\Support\PublicAddressResolver;
use Fahoba\PluginApi\Recipes\Ingredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeResolver;
use Tests\TestCase;
use ZipArchive;

/**
 * Recipes from a link and whole collections in and out.
 */
final class RecipeImportTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->household = Household::factory()->create();
        app(HouseholdContext::class)->setId((int) $this->household->id);
        $this->app->instance(PublicAddressResolver::class, new FakeResolver([
            'rezepte.test' => ['93.184.216.34'],
            'intern.test' => ['10.0.0.5'],
        ]));
    }

    private function parent(): string
    {
        $person = Person::factory()->for($this->household)->create();

        return User::factory()->create([
            'household_id' => $this->household->id,
            'person_id' => $person->id,
        ])->createToken('phone')->plainTextToken;
    }

    private static function png(): string
    {
        $image = imagecreatetruecolor(40, 30);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 120, 40));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private static function page(): string
    {
        $recipe = [
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'WebPage', 'name' => 'Startseite'],
                [
                    '@type' => ['Recipe'],
                    'name' => 'Kaiserschmarrn &amp; Röster',
                    'description' => '<p>Flaumig, wie <b>bei Oma</b>.</p>',
                    'author' => ['@type' => 'Person', 'name' => 'Anna Koch'],
                    'image' => ['@type' => 'ImageObject', 'url' => 'https://rezepte.test/bild.png'],
                    'recipeYield' => ['4', '4 Portionen'],
                    'prepTime' => 'PT15M',
                    'cookTime' => 'PT20M',
                    'recipeCategory' => 'Süßspeise, Hauptspeise',
                    'suitableForDiet' => 'https://schema.org/VegetarianDiet',
                    'recipeIngredient' => ['250 g Mehl', '4 Eier', '1 Prise Salz'],
                    'recipeInstructions' => [
                        ['@type' => 'HowToSection', 'name' => 'Teig', 'itemListElement' => [
                            ['@type' => 'HowToStep', 'text' => 'Mehl und Eier verrühren.'],
                            ['@type' => 'HowToStep', 'text' => 'Rasten lassen.'],
                        ]],
                        ['@type' => 'HowToSection', 'name' => 'Backen', 'itemListElement' => [
                            ['@type' => 'HowToStep', 'text' => 'In Butter herausbacken.'],
                        ]],
                    ],
                ],
            ],
        ];

        return '<html><head><script type="application/ld+json">'.json_encode($recipe).'</script></head><body>…</body></html>';
    }

    public function test_a_parent_imports_a_recipe_from_a_link(): void
    {
        Http::fake([
            'rezepte.test/kaiserschmarrn' => Http::response(self::page()),
            'rezepte.test/bild.png' => Http::response(self::png(), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->withToken($this->parent())
            ->postJson('/api/wall/recipe-book/link', ['url' => 'https://rezepte.test/kaiserschmarrn'])
            ->assertCreated()
            ->assertJsonPath('recipe.title', 'Kaiserschmarrn & Röster')
            ->assertJsonPath('recipe.intro', 'Flaumig, wie bei Oma.')
            ->assertJsonPath('recipe.minutes', 35)
            ->assertJsonPath('recipe.vegetarian', true)
            ->assertJsonPath('recipe.categories', ['Süßspeise', 'Hauptspeise'])
            ->assertJsonPath('recipe.license.author', 'Anna Koch')
            ->assertJsonPath('recipe.source_url', 'https://rezepte.test/kaiserschmarrn');

        $recipe = Recipe::query()->sole();
        $this->assertSame("### Teig\n\n1. Mehl und Eier verrühren.\n2. Rasten lassen.\n\n### Backen\n\n1. In Butter herausbacken.", $recipe->steps);
        $this->assertSame(['Mehl', 'Eier', 'Salz'], array_column($recipe->ingredients, 'name'));
        $this->assertSame(250.0, (float) $recipe->ingredients[0]['amount']);
        $this->assertNotNull($recipe->photo_path);
        Storage::disk('local')->assertExists($recipe->photo_path);
    }

    public function test_a_private_address_is_never_fetched(): void
    {
        Http::fake();

        $this->withToken($this->parent())
            ->postJson('/api/wall/recipe-book/link', ['url' => 'http://intern.test/rezept'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        Http::assertNothingSent();
    }

    public function test_a_page_without_a_recipe_says_so(): void
    {
        Http::fake(['rezepte.test/*' => Http::response('<html><body>Kein Rezept</body></html>')]);

        $this->withToken($this->parent())
            ->postJson('/api/wall/recipe-book/link', ['url' => 'https://rezepte.test/blog'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        $this->assertSame(0, Recipe::query()->count());
    }

    public function test_a_child_cannot_import(): void
    {
        $child = Person::factory()->child()->for($this->household)->create();
        $token = User::factory()->create(['household_id' => $this->household->id, 'person_id' => $child->id])
            ->createToken('phone')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/wall/recipe-book/link', ['url' => 'https://rezepte.test/x'])
            ->assertForbidden();
    }

    public function test_a_collection_travels_to_another_household_with_its_photos(): void
    {
        $import = app(RecipeImport::class);
        Recipe::factory()->create([
            'household_id' => $this->household->id,
            'title' => 'Linsensuppe',
            'servings' => 3,
            'categories' => ['Suppe'],
            'ingredients' => [Ingredient::parse('200 g Linsen')?->toArray()],
            'photo_path' => app(PhotoImage::class)->store(self::png(), Recipe::PHOTO_DIRECTORY),
        ]);
        Recipe::factory()->create(['household_id' => $this->household->id, 'title' => 'Kaiserschmarrn']);

        $zip = $import->export();

        $other = Household::factory()->create();
        Recipe::factory()->create(['household_id' => $other->id, 'title' => 'kaiserschmarrn']);
        $context = app(HouseholdContext::class);

        $result = $context->forId((int) $other->id, fn (): array => $import->import($zip));
        $this->assertSame(['imported' => 1, 'skipped' => 1], $result);

        $soup = $context->forId((int) $other->id, fn (): Recipe => Recipe::query()->where('title', 'Linsensuppe')->sole());
        $this->assertSame(3, $soup->servings);
        $this->assertSame(['Suppe'], $soup->categories);
        $this->assertSame('Linsen', $soup->ingredients[0]['name']);
        Storage::disk('local')->assertExists((string) $soup->photo_path);

        // Twice is the same as once.
        $this->assertSame(['imported' => 0, 'skipped' => 2], $context->forId((int) $other->id, fn (): array => $import->import($zip)));
        unlink($zip);
    }

    public function test_a_collection_from_a_newer_version_is_refused(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('recipes.json', (string) json_encode(['format' => RecipeImport::FORMAT, 'version' => RecipeImport::VERSION + 1, 'recipes' => []]));
        $zip->close();

        try {
            app(RecipeImport::class)->import($path);
            $this->fail('A newer collection was accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('file', $error->errors());
        } finally {
            unlink($path);
        }
    }

    public function test_the_commands_export_and_import(): void
    {
        Recipe::factory()->create(['household_id' => $this->household->id, 'title' => 'Palatschinken']);
        $other = Household::factory()->create();
        $file = sys_get_temp_dir().'/fahoba-recipes-test.zip';

        $this->artisan('fahoba:export-recipes', ['household' => $this->household->id, 'file' => $file])->assertSuccessful();
        $this->artisan('fahoba:import-recipes', ['household' => $other->id, 'file' => $file])
            ->expectsOutput('Imported 1 recipes, skipped 0.')
            ->assertSuccessful();

        unlink($file);
        $this->assertSame(1, Recipe::withoutHouseholdScope()->where('household_id', $other->id)->count());
    }
}

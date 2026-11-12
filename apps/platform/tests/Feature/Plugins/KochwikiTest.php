<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Enums\ConnectionStatus;
use App\Enums\ProviderArea;
use App\Models\CatalogRecipe;
use App\Models\Household;
use App\Models\Person;
use App\Models\ProviderAccount;
use App\Models\User;
use App\Security\ContentSecurityPolicy;
use App\Support\HouseholdContext;
use Fahoba\Kochwiki\KochwikiRecipeProvider;
use Fahoba\Kochwiki\Wikitext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Koch-Wiki: the page parser against real pages, the mirror, and the
 * provider behind a household's connection.
 */
final class KochwikiTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array{revision: int, text: string, id: int}> */
    private array $pages = [];

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        $data = json_decode((string) file_get_contents(base_path('tests/Fixtures/kochwiki/pages.json')), true);

        foreach ($data['query']['pages'] as $page) {
            $this->pages[$page['title']] = [
                'id' => $page['pageid'],
                'revision' => $page['revisions'][0]['revid'],
                'text' => $page['revisions'][0]['slots']['main']['content'],
            ];
        }
    }

    /** @return array<string, mixed> */
    private function parse(string $title): array
    {
        $recipe = Wikitext::recipe($title, $this->pages[$title]['text']);
        $this->assertNotNull($recipe);

        return $recipe;
    }

    public function test_the_recipe_box_and_ingredients_are_read(): void
    {
        $recipe = $this->parse('Spaghetti alla carbonara');

        $this->assertSame(4, $recipe['servings']);
        $this->assertSame(35, $recipe['minutes']);
        $this->assertSame('medium', $recipe['difficulty']);
        $this->assertSame('Spaghetti Carbonara.jpg', $recipe['image']);
        $this->assertContains('Latium-Küche', $recipe['categories']);
        $this->assertNull($recipe['vegetarian']);

        $this->assertSame(
            ['amount' => 400.0, 'amount_max' => null, 'unit' => 'g', 'name' => 'Spaghetti', 'note' => 'Trockenware', 'group' => null],
            $recipe['ingredients'][0],
        );
        $this->assertSame(100.0, $recipe['ingredients'][2]['amount']);
        $this->assertSame(150.0, $recipe['ingredients'][2]['amount_max']);
        $this->assertSame('Salz', $recipe['ingredients'][4]['name']);
    }

    public function test_groups_hours_and_the_vegetarian_category(): void
    {
        $recipe = $this->parse('Sachertorte');

        $this->assertSame(12, $recipe['servings']);
        $this->assertSame(90, $recipe['minutes']);
        $this->assertTrue($recipe['vegetarian']);
        $this->assertSame('Torte', $recipe['ingredients'][0]['group']);
        $this->assertSame('Glasur', end($recipe['ingredients'])['group']);
        $this->assertStringContainsString('165 °C', (string) $recipe['steps']);
        $this->assertStringContainsString('## Konserv-Glasur', (string) $recipe['steps']);
    }

    /** Markdown out, nothing of the wiki's markup left — the renderer would show it as text. */
    public function test_the_text_becomes_markdown_steps(): void
    {
        foreach (['Spaghetti alla carbonara', 'Sachertorte'] as $title) {
            $recipe = $this->parse($title);
            $text = $recipe['intro']."\n".$recipe['steps'];

            $this->assertStringStartsWith('1. ', (string) $recipe['steps']);
            $this->assertStringNotContainsString('[[', $text);
            $this->assertStringNotContainsString('{{', $text);
            $this->assertStringNotContainsString("''", $text);
            $this->assertStringNotContainsString('<', $text);
            $this->assertStringNotContainsString('Kategorie', $text);
        }

        $this->assertStringContainsString('**Spaghetti alla carbonara**', (string) $this->parse('Spaghetti alla carbonara')['intro']);
    }

    public function test_a_page_without_the_recipe_box_is_no_recipe(): void
    {
        $this->assertNull(Wikitext::recipe('Hauptseite', $this->pages['Hauptseite']['text']));
    }

    public function test_only_https_links_survive(): void
    {
        $recipe = Wikitext::recipe('Test', "{{Rezept|\n| Menge = 2\n|}}\n== Zubereitung ==\n* Siehe [https://example.org/a Seite] und [http://example.org/b alt] und [javascript:alert(1) böse]");

        $this->assertSame('1. Siehe [Seite](https://example.org/a) und alt und [javascript:alert(1) böse]', $recipe['steps'] ?? null);
    }

    public function test_the_mirror_fetches_only_what_changed(): void
    {
        $this->fakeWiki();

        $this->artisan('fahoba:kochwiki-sync --force')->assertSuccessful();

        $this->assertSame(2, CatalogRecipe::query()->count());

        $torte = CatalogRecipe::query()->where('title', 'Sachertorte')->sole();

        $this->assertSame((string) $this->pages['Sachertorte']['id'], $torte->source_id);
        $this->assertStringEndsWith('/400px-Sachertorte_von_Masterdot-fertig.JPG', $torte->photo['small']);
        $this->assertStringEndsWith('/800px-Sachertorte_von_Masterdot-fertig.JPG', $torte->photo['large']);
        $this->assertSame('Masterdot', $torte->license['photo_author']);
        $this->assertSame('CC BY-SA', $torte->license['photo_license']);
        $this->assertSame('https://www.kochwiki.org/wiki/Sachertorte', $torte->source_url);

        // Second run: same revisions — the page list only, no content, not even the main page again.
        $this->fakeWiki();
        $this->artisan('fahoba:kochwiki-sync --force')->assertSuccessful();

        Http::assertSentCount(1);

        // A page that is gone takes its recipe along.
        $this->fakeWiki(without: 'Sachertorte');
        $this->artisan('fahoba:kochwiki-sync --force')->assertSuccessful();

        $this->assertSame(['Spaghetti alla carbonara'], CatalogRecipe::query()->pluck('title')->all());
    }

    /** A volunteer wiki is not asked anything while no household uses it. */
    public function test_the_schedule_does_nothing_without_a_connection(): void
    {
        Http::fake();

        $this->artisan('fahoba:kochwiki-sync')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_connected_household_browses_and_opens_it(): void
    {
        $this->fakeWiki();
        $this->artisan('fahoba:kochwiki-sync --force')->assertSuccessful();

        $household = Household::factory()->create();
        $token = $this->phone($household);
        $stranger = $this->phone(Household::factory()->create());

        $this->withToken($token)->getJson('/api/wall/recipe-book?q=sacher')
            ->assertOk()
            ->assertJsonCount(0, 'recipes');

        app(HouseholdContext::class)->setId((int) $household->id);
        ProviderAccount::query()->create([
            'household_id' => $household->id,
            'area' => ProviderArea::Recipes,
            'provider' => KochwikiRecipeProvider::KEY,
            'account_label' => 'Koch-Wiki',
            'status' => ConnectionStatus::Active,
        ]);

        $card = $this->withToken($token)->getJson('/api/wall/recipe-book?q=sacher&vegetarian=1')
            ->assertOk()
            ->assertJsonPath('recipes.0.name', 'Sachertorte')
            ->assertJsonPath('recipes.0.provider', 'fahoba/kochwiki')
            ->json('recipes.0');

        // What the family made itself leaves the catalog out.
        $this->withToken($token)->getJson('/api/wall/recipe-book?q=sacher&list=own')
            ->assertOk()
            ->assertJsonCount(0, 'recipes');

        $this->withToken($token)->getJson("/api/wall/recipe-book/fahoba/kochwiki/{$card['id']}?servings=6")
            ->assertOk()
            ->assertJsonPath('recipe.title', 'Sachertorte')
            ->assertJsonPath('recipe.base_servings', 12)
            ->assertJsonPath('recipe.ingredients.0.spec', '65 g')
            ->assertJsonPath('recipe.can.edit', false)
            ->assertJsonPath('recipe.license.author', 'Koch-Wiki');

        Auth::forgetGuards();
        $this->withToken($stranger)->getJson("/api/wall/recipe-book/fahoba/kochwiki/{$card['id']}")->assertNotFound();
    }

    public function test_its_photos_are_allowed_by_the_policy(): void
    {
        $this->assertTrue(app(ContentSecurityPolicy::class)->allowsImage('https://www.kochwiki.org/images/thumb/a/b.jpg/400px-b.jpg'));
        $this->assertStringContainsString('https://www.kochwiki.org', app(ContentSecurityPolicy::class)->forApp());
    }

    private function phone(Household $household): string
    {
        $person = Person::factory()->for($household)->create();

        return User::factory()->create([
            'household_id' => $household->id,
            'person_id' => $person->id,
        ])->createToken('phone')->plainTextToken;
    }

    private function fakeWiki(?string $without = null): void
    {
        $pages = array_filter($this->pages, static fn (string $title): bool => $title !== $without, ARRAY_FILTER_USE_KEY);
        $images = json_decode((string) file_get_contents(base_path('tests/Fixtures/kochwiki/images.json')), true);

        // A fresh fake: stubs pile up otherwise, and the first one keeps answering.
        Http::swap(new HttpFactory);
        Http::fake(function (Request $request) use ($pages, $images) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            $this->assertStringStartsWith('Fahoba/1.0 (+', $request->header('User-Agent')[0] ?? '');
            $this->assertSame('5', (string) ($query['maxlag'] ?? ''));

            if (($query['generator'] ?? null) === 'allpages') {
                return Http::response(['query' => ['pages' => array_values(array_map(
                    static fn (string $title, array $page): array => ['pageid' => $page['id'], 'title' => $title, 'lastrevid' => $page['revision']],
                    array_keys($pages),
                    $pages,
                ))]]);
            }

            if (isset($query['pageids'])) {
                $ids = explode('|', (string) $query['pageids']);

                return Http::response(['query' => ['pages' => array_values(array_map(
                    static fn (string $title, array $page): array => [
                        'pageid' => $page['id'],
                        'title' => $title,
                        'revisions' => [['revid' => $page['revision'], 'slots' => ['main' => ['content' => $page['text']]]]],
                    ],
                    array_keys($pages),
                    $pages,
                )) |> (static fn (array $all): array => array_values(array_filter($all, static fn (array $page): bool => in_array((string) $page['pageid'], $ids, true))))]]);
            }

            return Http::response($images);
        });
    }
}

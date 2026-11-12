<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\Recipes\Pages\CreateRecipe;
use App\Filament\Resources\Recipes\Pages\ListRecipes;
use App\Models\Calendar;
use App\Models\Household;
use App\Models\Person;
use App\Models\ProviderAccount;
use App\Models\Recipe;
use App\Models\User;
use App\Support\HouseholdContext;
use App\Support\PublicAddressResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakeResolver;
use Tests\TestCase;

final class AdminResourcesTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(Household $household): User
    {
        $user = User::factory()->create(['household_id' => $household->id]);

        $this->actingAs($user);

        return $user;
    }

    public function test_people_list_shows_own_household_members(): void
    {
        $household = Household::factory()->create();
        $this->signIn($household);

        Person::factory()->create([
            'household_id' => $household->id,
            'name' => 'Wilhelmine',
        ]);

        $this->get('/admin/people')
            ->assertSuccessful()
            ->assertSee('Wilhelmine');
    }

    public function test_people_list_hides_foreign_households(): void
    {
        $own = Household::factory()->create();
        $foreign = Household::factory()->create();
        $this->signIn($own);

        Person::factory()->create([
            'household_id' => $foreign->id,
            'name' => 'Wilhelmine',
        ]);

        $this->get('/admin/people')
            ->assertSuccessful()
            ->assertDontSee('Wilhelmine');
    }

    public function test_person_form_loads(): void
    {
        $this->signIn(Household::factory()->create());

        $this->get('/admin/people/create')->assertSuccessful();
    }

    public function test_device_form_loads(): void
    {
        $this->signIn(Household::factory()->create());

        $this->get('/admin/devices/create')->assertSuccessful();
    }

    public function test_calendar_list_shows_own_calendars(): void
    {
        $household = Household::factory()->create();
        $this->signIn($household);

        Calendar::factory()->create([
            'household_id' => $household->id,
            'provider_account_id' => ProviderAccount::factory()->create([
                'household_id' => $household->id,
            ])->id,
            'summary' => 'Wochenplan',
        ]);

        $this->get('/admin/calendars')
            ->assertSuccessful()
            ->assertSee('Wochenplan');
    }

    /**
     * Assigning a calendar to a person is the setting most often overlooked:
     * without it events appear on the wall in the calendar colour and count
     * towards nobody. The hint therefore stays until the household has used
     * the assignment once.
     */
    public function test_the_calendar_list_explains_the_assignment_while_none_exists(): void
    {
        $household = Household::factory()->create();
        $this->signIn($household);

        Person::factory()->create(['household_id' => $household->id]);

        Calendar::factory()->create([
            'household_id' => $household->id,
            'provider_account_id' => ProviderAccount::factory()->create([
                'household_id' => $household->id,
            ])->id,
        ]);

        $this->get('/admin/calendars')
            ->assertSuccessful()
            ->assertSee('Kalender gehört noch niemandem');
    }

    public function test_the_hint_disappears_after_the_first_assignment(): void
    {
        $household = Household::factory()->create();
        $this->signIn($household);

        $person = Person::factory()->create(['household_id' => $household->id]);
        $account = ProviderAccount::factory()->create(['household_id' => $household->id]);

        Calendar::factory()->create([
            'household_id' => $household->id,
            'provider_account_id' => $account->id,
        ])->people()->attach($person);

        Calendar::factory()->create([
            'household_id' => $household->id,
            'provider_account_id' => $account->id,
        ]);

        $this->get('/admin/calendars')
            ->assertSuccessful()
            ->assertDontSee('gehört noch niemandem')
            ->assertDontSee('gehören noch niemandem');
    }

    /**
     * The builtin calendar is called „Familie" and applies to everyone.
     * Flagging it would mean criticising something that is correct.
     */
    public function test_the_builtin_family_calendar_triggers_no_hint(): void
    {
        $household = Household::factory()->create();
        $this->signIn($household);

        Person::factory()->create(['household_id' => $household->id]);

        $this->get('/admin/calendars')
            ->assertSuccessful()
            ->assertDontSee('gehört noch niemandem')
            ->assertDontSee('gehören noch niemandem');
    }

    /** Without people there is nothing to assign -- then the hint would be noise. */
    public function test_without_people_there_is_no_hint(): void
    {
        $household = Household::factory()->create();
        $this->signIn($household);

        Calendar::factory()->create([
            'household_id' => $household->id,
            'provider_account_id' => ProviderAccount::factory()->create([
                'household_id' => $household->id,
            ])->id,
        ]);

        $this->get('/admin/calendars')
            ->assertSuccessful()
            ->assertDontSee('gehört noch niemandem');
    }

    public function test_calendar_can_be_edited(): void
    {
        $household = Household::factory()->create();
        $this->signIn($household);

        $calendar = Calendar::factory()->create([
            'household_id' => $household->id,
            'provider_account_id' => ProviderAccount::factory()->create([
                'household_id' => $household->id,
            ])->id,
        ]);

        $this->get("/admin/calendars/{$calendar->id}/edit")->assertSuccessful();
    }

    public function test_calendars_cannot_be_created_by_hand(): void
    {
        $this->signIn(Household::factory()->create());

        // Calendars are created when importing from Google.
        $this->get('/admin/calendars/create')->assertNotFound();
    }

    public function test_account_list_shows_connected_accounts(): void
    {
        $household = Household::factory()->create();
        $this->signIn($household);

        ProviderAccount::factory()->create([
            'household_id' => $household->id,
            'account_label' => 'alex@example.test',
        ]);

        $this->get('/admin/connections')
            ->assertSuccessful()
            ->assertSee('alex@example.test');
    }

    /**
     * The ingredient column counts, it does not count per ingredient.
     *
     * For an array field Filament treats every entry as a value of its own
     * and calls the formatter per ingredient — three ingredients became
     * „3, 3, 3".
     */
    public function test_recipe_list_shows_the_ingredient_count(): void
    {
        $household = Household::factory()->create();
        $this->signIn($household);

        Recipe::factory()->create([
            'household_id' => $household->id,
            'title' => 'Kaiserschmarrn',
            'ingredients' => [
                ['name' => 'Mehl', 'spec' => '250 g'],
                ['name' => 'Eier', 'spec' => '4 Stück'],
                ['name' => 'Milch', 'spec' => '375 ml'],
            ],
        ]);

        $this->get('/admin/recipes')
            ->assertSuccessful()
            ->assertSee('Kaiserschmarrn')
            ->assertDontSee('3, 3, 3');
    }

    /** Amounts from the repeater are text; stored they are numbers, as everywhere else. */
    public function test_a_recipe_is_written_in_the_admin(): void
    {
        $household = Household::factory()->create();
        $this->signIn($household);
        app(HouseholdContext::class)->setId((int) $household->id);

        Livewire::test(CreateRecipe::class)
            ->fillForm([
                'title' => 'Palatschinken',
                'servings' => 4,
                'minutes' => 20,
                'difficulty' => 'easy',
                'categories' => ['Süßspeise'],
                'ingredients' => [
                    ['amount' => '250', 'unit' => 'ml', 'name' => 'Milch', 'note' => null, 'group' => null, 'amount_max' => null],
                ],
                'steps' => "1. Verrühren\n2. Ausbacken",
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $recipe = Recipe::query()->sole();

        $this->assertSame(250, $recipe->ingredients[0]['amount']);
        $this->assertSame('250 ml', $recipe->ingredientList()[0]['spec']);
        $this->assertSame(['Süßspeise'], $recipe->categories);
        $this->assertSame("1. Verrühren\n2. Ausbacken", $recipe->steps);
    }

    public function test_the_recipe_collection_actions_work_in_the_admin(): void
    {
        $household = Household::factory()->create();
        $this->signIn($household);
        app(HouseholdContext::class)->setId((int) $household->id);
        Recipe::factory()->create(['household_id' => $household->id, 'title' => 'Palatschinken']);

        Livewire::test(ListRecipes::class)
            ->callAction('export')
            ->assertFileDownloaded('fahoba-recipes.zip');

        // A private address is refused before anything is fetched.
        $this->app->instance(PublicAddressResolver::class, new FakeResolver(['intern.test' => ['10.0.0.5']]));

        Livewire::test(ListRecipes::class)
            ->callAction('link', data: ['url' => 'http://intern.test/rezept'])
            ->assertNotified(__('This address cannot be reached from the server.'));

        $this->assertSame(1, Recipe::query()->count());
    }
}

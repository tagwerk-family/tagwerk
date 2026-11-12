<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ListKind;
use App\Enums\MealKind;
use App\Models\Checklist;
use App\Models\Household;
use App\Models\MealPlanEntry;
use App\Models\Person;
use App\Models\Recipe;
use App\Models\Task;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Recipes\Ingredient;
use Illuminate\Database\Seeder;

/**
 * Lists and tasks of the sample household.
 *
 * The routines are deliberately shaped differently — weekly on several days,
 * daily, and monthly on the second Thursday. Otherwise it would go unnoticed
 * if only the simplest rule worked.
 */
final class HomeSeeder extends Seeder
{
    public function run(): void
    {
        $household = Household::firstOrFail();

        app(HouseholdContext::class)->setId($household->id);

        $people = Person::query()->pluck('id', 'name');

        $shopping = Checklist::create([
            'name' => 'Einkauf',
            'kind' => ListKind::Shopping,
            'color' => '#059669',
            'list_provider' => 'fahoba/bring',
            'position' => 0,
        ]);

        foreach (['Milch', 'Vollkornbrot', 'Äpfel', 'Kaffee'] as $position => $title) {
            $shopping->items()->create(['title' => $title, 'position' => $position]);
        }

        $shopping->items()->create([
            'title' => 'Zahnpasta',
            'quantity' => '2 Tuben',
            'done_at' => now()->subHours(3),
            'position' => 4,
        ]);

        $packingList = Checklist::create([
            'name' => 'Schwimmbad',
            'kind' => ListKind::Packing,
            'color' => '#0284c7',
            'position' => 1,
        ]);

        foreach (['Badesachen', 'Handtücher', 'Sonnencreme'] as $position => $title) {
            $packingList->items()->create(['title' => $title, 'position' => $position]);
        }

        $tasks = [
            [
                'title' => 'Müll rausbringen',
                'rrule' => 'FREQ=WEEKLY;BYDAY=TU',
                'points' => 5,
                'person' => 'Max',
            ],
            [
                'title' => 'Tisch decken',
                'rrule' => 'FREQ=DAILY',
                'points' => 2,
                'person' => 'Lena',
            ],
            [
                'title' => 'Zimmer aufräumen',
                'rrule' => 'FREQ=WEEKLY;BYDAY=SA',
                'points' => 10,
                'person' => null,
            ],
            [
                // Covers the case from the spec that simpler recurrence
                // models fail at.
                'title' => 'Altpapier zur Sammelstelle',
                'rrule' => 'FREQ=MONTHLY;BYDAY=+2TH',
                'points' => 8,
                'person' => null,
            ],
            [
                'title' => 'Fahrradschlauch flicken',
                'rrule' => null,
                'points' => 0,
                'person' => null,
            ],
        ];

        foreach ($tasks as $position => $task) {
            Task::create([
                'title' => $task['title'],
                'rrule' => $task['rrule'],
                'points' => $task['points'],
                'person_id' => $task['person'] === null ? null : $people[$task['person']] ?? null,
                'due_on' => now()->startOfWeek()->toDateString(),
                'position' => $position,
            ]);
        }

        /*
         * The weekly plan with and without a recipe: "Reste" is the most
         * common entry of all and must not depend on Tandoor being set up.
         */
        $meals = [
            [0, MealKind::Dinner, 'Gemüselasagne', 42],
            [1, MealKind::Dinner, 'Linsensuppe', 43],
            [2, MealKind::Lunch, 'Reste', null],
            [2, MealKind::Dinner, 'Kaiserschmarrn', null],
            [4, MealKind::Dinner, 'Essen bei Oma', null],
        ];

        foreach ($meals as [$offset, $kind, $title, $recipeId]) {
            MealPlanEntry::create([
                'planned_on' => now()->startOfWeek()->addDays($offset)->toDateString(),
                'kind' => $kind,
                'title' => $title,
                'recipe_id' => $recipeId,
            ]);
        }

        $this->recipes();
    }

    /** A few recipes in the built-in book — without any external service. */
    private function recipes(): void
    {
        $recipes = [
            [
                'title' => 'Kaiserschmarrn',
                'intro' => 'Der Klassiker für Sonntag Mittag.',
                'servings' => 4, 'minutes' => 30, 'difficulty' => 'easy', 'vegetarian' => true,
                'categories' => ['Süßspeise', 'Österreichische Küche'],
                'ingredients' => ['250 g Mehl', '4 Eier', '375 ml Milch', '2 EL Rosinen', '1 Prise Salz', '2 EL Staubzucker, zum Bestreuen'],
                'steps' => "1. Mehl, Milch, Dotter und Salz glatt rühren.\n2. Eiklar zu **steifem Schnee** schlagen und unterheben.\n3. In heißer Butter stocken lassen, wenden, mit zwei Gabeln zerreißen.\n4. Rosinen dazu, mit Staubzucker servieren.",
            ],
            [
                'title' => 'Gemüselasagne',
                'servings' => 4, 'minutes' => 75, 'difficulty' => 'medium', 'vegetarian' => true,
                'categories' => ['Auflauf', 'Italienische Küche'],
                'ingredients' => ['1 Packung Lasagneblätter', '2 Zucchini', '500 g Passierte Tomaten', '200 g Mozzarella', '1 Zwiebel'],
                'steps' => "1. Zwiebel und Zucchini würfeln und anbraten.\n2. Mit den Tomaten zehn Minuten köcheln lassen.\n3. Abwechselnd mit den Blättern schichten, Mozzarella obenauf.\n4. *40 Minuten* bei 180 Grad backen.",
            ],
            [
                'title' => 'Spaghetti Carbonara',
                'servings' => 4, 'minutes' => 25, 'difficulty' => 'easy', 'vegetarian' => false,
                'categories' => ['Nudeln', 'Italienische Küche'],
                'ingredients' => ['400 g Spaghetti', '150 g Guanciale', '4 Dotter', '80 g Pecorino, gerieben', 'Pfeffer'],
                'steps' => "1. Spaghetti in Salzwasser bissfest kochen.\n2. Guanciale knusprig auslassen.\n3. Dotter mit Pecorino und Pfeffer verrühren.\n4. Nudeln vom Herd nehmen, alles vermengen, mit etwas Kochwasser cremig rühren.",
            ],
        ];

        foreach ($recipes as $recipe) {
            Recipe::create([
                ...$recipe,
                'ingredients' => array_map(
                    static fn (string $line): array => Ingredient::parse($line)?->toArray() ?? [],
                    $recipe['ingredients'],
                ),
            ]);
        }
    }
}

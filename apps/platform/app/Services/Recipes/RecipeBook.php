<?php

declare(strict_types=1);

namespace App\Services\Recipes;

use App\Integrations\ProviderRegistry;
use App\Models\MealPlanEntry;
use App\Models\RecipeFavorite;
use App\Support\HouseholdTimezone;
use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Recipes\DescribesRecipes;
use Fahoba\PluginApi\Recipes\RecipeQuery;
use Illuminate\Database\Eloquent\Builder;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * What the household itself knows about recipes, across every source:
 * favourites, what it cooks often, what it has not cooked for a long time —
 * and suggestions drawn from all of that.
 *
 * A recipe is addressed as `provider:id`, like a planned meal. The sources
 * fill in what a card looks like; a source that cannot look up by id shows
 * the name Fahoba remembered.
 *
 * @phpstan-type Card array{id: string, name: string, minutes: int|null, difficulty: string|null, vegetarian: bool|null, photo: string|null, provider: string, provider_label: string}
 * @phpstan-type Ref array{provider: string, id: string, title: string}
 */
final class RecipeBook
{
    /** Planned within this many days before or after today: not suggested again. */
    public const RECENT_DAYS = 14;

    /** Not planned for this long counts as "a long time ago". */
    public const FORGOTTEN_DAYS = 60;

    private const LIMIT = 60;

    public function __construct(private readonly ProviderRegistry $registry) {}

    /** @return array<string, true> `provider:id` of every favourite */
    public function favoriteKeys(): array
    {
        return RecipeFavorite::query()
            ->get(['provider', 'recipe_id'])
            ->mapWithKeys(static fn (RecipeFavorite $favorite): array => [$favorite->provider.':'.$favorite->recipe_id => true])
            ->all();
    }

    /** @return list<Ref> */
    public function favorites(): array
    {
        return RecipeFavorite::query()
            ->orderBy('title')
            ->get()
            ->map(static fn (RecipeFavorite $favorite): array => [
                'provider' => (string) $favorite->provider,
                'id' => (string) $favorite->recipe_id,
                'title' => (string) $favorite->title,
            ])
            ->all();
    }

    /**
     * Cooked at least twice, the most cooked first.
     *
     * @return list<Ref>
     */
    public function often(): array
    {
        return $this->history()
            ->havingRaw('count(*) >= 2')
            ->orderByRaw('count(*) desc')
            ->orderByRaw('max(planned_on) desc')
            ->limit(self::LIMIT)
            ->get()
            ->map($this->ref(...))
            ->all();
    }

    /**
     * Cooked before, but not for two months — the longest ago first.
     *
     * @return list<Ref>
     */
    public function forgotten(): array
    {
        return $this->history()
            ->havingRaw('max(planned_on) < ?', [$this->today()->subDays(self::FORGOTTEN_DAYS)->toDateString()])
            ->orderByRaw('max(planned_on) asc')
            ->limit(self::LIMIT)
            ->get()
            ->map($this->ref(...))
            ->all();
    }

    /**
     * Cards for known recipes, in the given order. Gone ones are left out.
     *
     * @param  list<Ref>  $refs
     * @return list<Card>
     */
    public function cards(array $refs): array
    {
        $providers = $this->registry->recipeProviders();
        $found = [];

        foreach (collect($refs)->groupBy('provider') as $key => $group) {
            $provider = $providers[$key] ?? null;

            if ($provider === null) {
                continue;
            }

            if ($provider instanceof DescribesRecipes) {
                $ids = $group->pluck('id')->map(strval(...))->all();

                foreach ($provider->browse(new RecipeQuery(ids: $ids, limit: count($ids))) as $card) {
                    $found[$key.':'.$card['id']] = [...$card, 'provider' => (string) $key, 'provider_label' => $provider->label()];
                }

                continue;
            }

            foreach ($group as $ref) {
                $found[$key.':'.$ref['id']] = [
                    'id' => $ref['id'],
                    'name' => $ref['title'],
                    'minutes' => null,
                    'difficulty' => null,
                    'vegetarian' => null,
                    'photo' => null,
                    'provider' => (string) $key,
                    'provider_label' => $provider->label(),
                ];
            }
        }

        $cards = [];

        foreach ($refs as $ref) {
            if (isset($found[$ref['provider'].':'.$ref['id']])) {
                $cards[] = $found[$ref['provider'].':'.$ref['id']];
            }
        }

        return $cards;
    }

    /**
     * A handful of ideas. Favourites weigh three times, often cooked twice,
     * the rest of the book once; nothing planned in the last or next two
     * weeks. The same seed gives the same ideas — "Andere" asks with another.
     *
     * @return list<Card>
     */
    public function suggestions(int $seed, int $count = 6): array
    {
        /** @var array<string, array{card: Card, weight: int}> $pool */
        $pool = [];

        foreach ($this->cards($this->favorites()) as $card) {
            $pool[$card['provider'].':'.$card['id']] = ['card' => $card, 'weight' => 3];
        }

        foreach ($this->cards($this->often()) as $card) {
            $key = $card['provider'].':'.$card['id'];
            $pool[$key] = ['card' => $card, 'weight' => ($pool[$key]['weight'] ?? 0) + 2];
        }

        foreach ($this->registry->recipeProviders() as $key => $provider) {
            if (! $provider instanceof DescribesRecipes) {
                continue;
            }

            foreach ($provider->browse(new RecipeQuery(limit: 4 * $count, seed: $seed)) as $card) {
                $pool[$key.':'.$card['id']] ??= ['card' => [...$card, 'provider' => (string) $key, 'provider_label' => $provider->label()], 'weight' => 1];
            }
        }

        foreach ($this->recentKeys() as $key) {
            unset($pool[$key]);
        }

        // Stable order before drawing: the same seed must draw the same.
        ksort($pool);
        $random = new Randomizer(new Mt19937($seed));
        $chosen = [];

        while ($pool !== [] && count($chosen) < $count) {
            $ticket = $random->getInt(1, array_sum(array_column($pool, 'weight')));

            foreach ($pool as $key => $entry) {
                $ticket -= $entry['weight'];

                if ($ticket <= 0) {
                    $chosen[] = $entry['card'];
                    unset($pool[$key]);

                    break;
                }
            }
        }

        return $chosen;
    }

    /** @return list<string> */
    private function recentKeys(): array
    {
        $today = $this->today();

        return MealPlanEntry::query()
            ->whereNotNull('recipe_id')
            ->whereBetween('planned_on', [
                $today->subDays(self::RECENT_DAYS)->toDateString(),
                $today->addDays(self::RECENT_DAYS)->toDateString(),
            ])
            ->get(['recipe_provider', 'recipe_id'])
            ->map(static fn (MealPlanEntry $entry): string => $entry->recipe_provider.':'.$entry->recipe_id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return Builder<MealPlanEntry> */
    private function history(): Builder
    {
        return MealPlanEntry::query()
            ->whereNotNull('recipe_id')
            ->whereNotNull('recipe_provider')
            ->where('planned_on', '<=', $this->today()->toDateString())
            ->select(['recipe_provider', 'recipe_id'])
            ->selectRaw('max(title) as title')
            ->groupBy('recipe_provider', 'recipe_id');
    }

    /** @return Ref */
    private function ref(MealPlanEntry $row): array
    {
        return ['provider' => (string) $row->recipe_provider, 'id' => (string) $row->recipe_id, 'title' => (string) $row->getAttribute('title')];
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(HouseholdTimezone::current())->startOfDay();
    }
}

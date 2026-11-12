<?php

declare(strict_types=1);

namespace App\Support\Recipes;

use Fahoba\PluginApi\Recipes\RecipeQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Search and filters over `recipes` and `catalog_recipes` — both carry the
 * same columns.
 *
 * Full text with the German dictionary, so "Knödel" finds "Knödeln". The
 * expression is the index's expression (migration `extend_recipes`); a
 * different one would leave the index unused.
 */
final class RecipeSearch
{
    public const VECTOR = "to_tsvector('german'::regconfig, coalesce(title, '') || ' ' || coalesce(categories::text, '') || ' ' || coalesce(jsonb_path_query_array(ingredients, '$[*].name')::text, ''))";

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $builder
     * @return Builder<TModel>
     */
    public static function apply(Builder $builder, RecipeQuery $query): Builder
    {
        $words = self::words($query->term);

        if ($words !== []) {
            // Every word as a prefix: while typing "Kartof" is already a hit.
            $tsquery = implode(' & ', array_map(static fn (string $word): string => $word.':*', $words));

            // The title also by substring: German compounds — "Knödel" is in "Semmelknödel".
            $builder
                ->where(fn (Builder $q) => $q
                    ->whereRaw(self::VECTOR." @@ to_tsquery('german'::regconfig, ?)", [$tsquery])
                    ->orWhereRaw('title ilike ?', ['%'.self::like($query->term).'%']))
                ->orderByRaw('case when title ilike ? then 0 else 1 end', [self::like($query->term).'%'])
                ->orderByRaw('ts_rank('.self::VECTOR.", to_tsquery('german'::regconfig, ?)) desc", [$tsquery]);
        }

        return $builder
            ->when($query->maxMinutes !== null, fn (Builder $q) => $q->whereNotNull('minutes')->where('minutes', '<=', $query->maxMinutes))
            ->when($query->vegetarian, fn (Builder $q) => $q->where('vegetarian', true))
            ->when($query->difficulty !== null, fn (Builder $q) => $q->where('difficulty', $query->difficulty))
            ->when($query->category !== null, fn (Builder $q) => $q->whereRaw('categories @> ?::jsonb', [json_encode([$query->category], JSON_UNESCAPED_UNICODE)]))
            ->when($query->ids !== null, fn (Builder $q) => $q->whereIn('id', array_values(array_filter($query->ids, ctype_digit(...)))))
            ->when($query->seed !== null, fn (Builder $q) => $q->orderByRaw('md5(id::text || ?)', [(string) $query->seed]))
            ->orderBy('title')
            ->offset($query->offset)
            ->limit($query->limit);
    }

    /**
     * Every category used, alphabetically — the chips of the filter.
     *
     * @param  Builder<covariant Model>  $builder
     * @return list<string>
     */
    public static function categories(Builder $builder): array
    {
        return $builder
            ->selectRaw('distinct jsonb_array_elements_text(categories) as category')
            ->reorder()
            ->orderBy('category')
            ->pluck('category')
            ->map(static fn (mixed $category): string => (string) $category)
            ->values()
            ->all();
    }

    /**
     * Letters and digits only — whatever reaches `to_tsquery` must not carry
     * its operators.
     *
     * @return list<string>
     */
    public static function words(string $term): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($term), $matches);

        return array_slice($matches[0], 0, 8);
    }

    private static function like(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($term));
    }
}

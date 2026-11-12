<?php

declare(strict_types=1);

namespace App\Services\Recipes;

use App\Exceptions\InvalidImage;
use App\Models\Recipe;
use App\Services\PhotoImage;
use App\Support\OutboundHttp;
use App\Support\Recipes\LinkedRecipe;
use App\Support\Recipes\RecipeInput;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Recipes into and out of the household's own book: one from a link, or a
 * whole collection as a ZIP — `recipes.json` plus the photos beside it.
 *
 * Whatever comes in passes the same rules as a typed recipe, and photos are
 * redrawn like every other picture: nothing is stored as it arrived.
 */
final class RecipeImport
{
    public const FORMAT = 'fahoba-recipes';

    /** Collections exported before the rename to Fahoba still import. */
    private const LEGACY_FORMAT = 'tagwerk-recipes';

    public const VERSION = 1;

    private const MANIFEST = 'recipes.json';

    private const MAX_PAGE = 2 * 1024 * 1024;

    private const MAX_PHOTO = 15 * 1024 * 1024;

    private const MAX_MANIFEST = 20 * 1024 * 1024;

    private const MAX_RECIPES = 2000;

    /** What a collection carries per recipe — everything but the household. */
    private const FIELDS = ['title', 'intro', 'steps', 'servings', 'minutes', 'difficulty', 'categories', 'vegetarian', 'ingredients', 'source', 'source_url', 'license'];

    public function __construct(
        private readonly OutboundHttp $http,
        private readonly PhotoImage $photos,
    ) {}

    /**
     * One recipe from a web page that carries schema.org JSON-LD.
     *
     * @throws ValidationException
     */
    public function fromLink(string $url): Recipe
    {
        if (! $this->http->isPublic($url)) {
            throw ValidationException::withMessages(['url' => __('This address cannot be reached from the server.')]);
        }

        $response = $this->http->get($url, 15);
        $html = $response?->successful() ? $response->body() : null;

        if ($html === null || strlen($html) > self::MAX_PAGE) {
            throw ValidationException::withMessages(['url' => __('The page could not be loaded.')]);
        }

        $found = LinkedRecipe::fromHtml($html, $url);

        if ($found === null) {
            throw ValidationException::withMessages(['url' => __('The page has no recipe Fahoba can read.')]);
        }

        $recipe = $this->create([...$found, 'source' => 'link']);

        if (is_string($found['image'])) {
            $this->attachPhoto($recipe, $this->download($found['image']));
        }

        return $recipe;
    }

    /** A ZIP of every own recipe; the caller removes the file. */
    public function export(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'recipes');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $recipes = [];

        foreach (Recipe::query()->orderBy('title')->get() as $recipe) {
            $entry = array_intersect_key($recipe->attributesToArray(), array_flip(self::FIELDS));
            $entry['photo'] = null;

            if (is_string($recipe->photo_path) && Storage::disk('local')->exists($recipe->photo_path)) {
                $entry['photo'] = 'photos/'.$recipe->getKey().'.webp';
                $zip->addFromString($entry['photo'], (string) Storage::disk('local')->get($recipe->photo_path));
            }

            $recipes[] = $entry;
        }

        $zip->addFromString(self::MANIFEST, (string) json_encode([
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => now()->toIso8601String(),
            'recipes' => $recipes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->close();

        return $path;
    }

    /**
     * A collection from `export()`. A recipe whose title the book already has
     * is skipped, so importing the same file twice changes nothing.
     *
     * @return array{imported: int, skipped: int}
     *
     * @throws ValidationException
     */
    public function import(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw ValidationException::withMessages(['file' => __('This is not a recipe collection.')]);
        }

        try {
            $stat = $zip->statName(self::MANIFEST);
            $manifest = $stat !== false && $stat['size'] <= self::MAX_MANIFEST
                ? json_decode((string) $zip->getFromName(self::MANIFEST), true)
                : null;

            if (! is_array($manifest) || ! in_array($manifest['format'] ?? null, [self::FORMAT, self::LEGACY_FORMAT], true) || ! is_array($manifest['recipes'] ?? null)) {
                throw ValidationException::withMessages(['file' => __('This is not a recipe collection.')]);
            }

            if (! is_int($manifest['version'] ?? null) || $manifest['version'] > self::VERSION) {
                throw ValidationException::withMessages(['file' => __('This collection comes from a newer Fahoba.')]);
            }

            if (count($manifest['recipes']) > self::MAX_RECIPES) {
                throw ValidationException::withMessages(['file' => __('At most :count recipes at a time.', ['count' => self::MAX_RECIPES])]);
            }

            $known = Recipe::query()->pluck('title')->map(static fn (string $title): string => mb_strtolower($title))->flip()->all();
            $imported = 0;
            $skipped = 0;

            foreach ($manifest['recipes'] as $entry) {
                $title = is_array($entry) && is_string($entry['title'] ?? null) ? mb_strtolower(trim($entry['title'])) : '';

                if ($title === '' || isset($known[$title])) {
                    $skipped++;

                    continue;
                }

                try {
                    $recipe = $this->create(array_intersect_key($entry, array_flip(self::FIELDS)));
                } catch (ValidationException) {
                    $skipped++;

                    continue;
                }

                $this->attachPhoto($recipe, $this->photoFrom($zip, $entry['photo'] ?? null));
                $known[$title] = true;
                $imported++;
            }

            return ['imported' => $imported, 'skipped' => $skipped];
        } finally {
            $zip->close();
        }
    }

    /** @param  array<string, mixed>  $data */
    private function create(array $data): Recipe
    {
        $rules = [
            ...RecipeInput::rules(),
            'source' => ['nullable', 'string', 'max:32'],
            'source_url' => ['nullable', 'url:http,https', 'max:2048'],
            'license' => ['nullable', 'array'],
            'license.*' => ['nullable', 'string', 'max:2048'],
        ];
        $valid = Validator::make($data, $rules)->validate();

        return Recipe::create([
            ...RecipeInput::attributes($valid),
            'source' => $valid['source'] ?? null,
            'source_url' => $valid['source_url'] ?? null,
            'license' => ($valid['license'] ?? []) === [] ? null : $valid['license'],
        ]);
    }

    private function download(string $url): ?string
    {
        $response = $this->http->get($url, 15);

        return $response?->successful() && strlen($response->body()) <= self::MAX_PHOTO ? $response->body() : null;
    }

    private function photoFrom(ZipArchive $zip, mixed $name): ?string
    {
        // Only the names `export()` writes; nothing else in the archive is read.
        if (! is_string($name) || preg_match('#^photos/[A-Za-z0-9_-]+\.(jpe?g|png|webp)$#', $name) !== 1) {
            return null;
        }

        $stat = $zip->statName($name);

        return $stat !== false && $stat['size'] <= self::MAX_PHOTO ? ($zip->getFromName($name) ?: null) : null;
    }

    /** A photo that is no picture costs the photo, not the recipe. */
    private function attachPhoto(Recipe $recipe, ?string $content): void
    {
        if ($content === null) {
            return;
        }

        try {
            $recipe->update(['photo_path' => $this->photos->store($content, Recipe::PHOTO_DIRECTORY)]);
        } catch (InvalidImage) {
            // Kept without a photo.
        }
    }
}

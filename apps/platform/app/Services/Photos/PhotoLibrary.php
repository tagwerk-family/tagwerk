<?php

declare(strict_types=1);

namespace App\Services\Photos;

use App\Enums\ProviderArea;
use App\Integrations\Photos\Builtin\BuiltinPhotoSource;
use App\Integrations\ProviderRegistry;
use App\Models\Household;
use App\Models\Photo as PhotoRow;
use App\Models\ProviderAccount;
use App\Support\HouseholdContext;
use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Photos\ListsPhotos;
use Fahoba\PluginApi\Photos\Photo;
use Fahoba\PluginApi\Photos\PhotoFile;
use Fahoba\PluginApi\Photos\PhotoSource;

/**
 * All of the household's pictures, wherever they come from.
 *
 * The frame should not know whether a picture was uploaded or lives in a
 * synced folder. It asks for pictures and gets pictures; mapping source to
 * account happens here.
 */
final class PhotoLibrary
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly PhotoRules $rules,
    ) {}

    /**
     * Pictures from all configured sources, chosen by the household's rule.
     *
     * `favorites` and `albums` narrow to uploaded photos — only those carry
     * a heart or belong to an album. The rule still chooses within them.
     *
     * @param  array<int, int>  $albums
     * @return array<int, array{source: string, id: string, url: string, caption: string|null, taken_on: string|null, favorite: bool|null}>
     */
    public function random(int $count, string $from = 'all', array $albums = []): array
    {
        $household = Household::query()->find(app(HouseholdContext::class)->id());
        $rule = $this->rules->find($household?->photo_rule);

        if ($from !== 'all') {
            $photos = $this->chosen($from, $albums);
            $sources = array_fill(0, count($photos), BuiltinPhotoSource::KEY);
        } elseif ($rule === null) {
            return $this->evenly($count);
        } else {
            [$photos, $sources] = $this->stock($count);
        }

        if ($rule === null) {
            $positions = array_keys($photos);
            shuffle($positions);
        } else {
            $positions = $rule->pick($photos, $count, $this->today($household));
        }

        return array_map(
            fn (int $position): array => $this->entry($sources[$position], $photos[$position]),
            array_slice($positions, 0, $count),
        );
    }

    /**
     * Uploaded photos with a heart, or in one of the albums.
     *
     * @param  array<int, int>  $albums
     * @return array<int, Photo>
     */
    private function chosen(string $from, array $albums): array
    {
        $query = PhotoRow::query();

        if ($from === 'favorites') {
            $query->where('favorite', true);
        } else {
            $query->whereHas('albums', static fn ($album) => $album->whereIn('photo_albums.id', $albums));
        }

        return $query->get()->map(BuiltinPhotoSource::describe(...))->values()->all();
    }

    /**
     * Every picture whose source can list them, with its source — what
     * memories are made from.
     *
     * @return array<int, array{0: string, 1: Photo}>
     */
    public function listed(): array
    {
        $listed = [];

        foreach ($this->sources() as $key => [$source, $account]) {
            if ($source instanceof ListsPhotos) {
                foreach ($source->all($account) as $photo) {
                    $listed[] = [$key, $photo];
                }
            }
        }

        return $listed;
    }

    /** The household's day as a plain date. */
    public function today(?Household $household): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now($household->timezone ?? config('app.timezone'))->toDateString());
    }

    /**
     * Without a rule: a handful from each source, mixed rather than one after
     * the other — otherwise a household with 2000 folder pictures and eight
     * uploaded ones would practically never see the uploaded ones.
     *
     * @return array<int, array{source: string, id: string, url: string, caption: string|null, taken_on: string|null, favorite: bool|null}>
     */
    private function evenly(int $count): array
    {
        $photos = [];

        foreach ($this->sources() as $key => [$source, $account]) {
            foreach ($source->random($account, $count) as $photo) {
                $photos[] = $this->entry($key, $photo);
            }
        }

        shuffle($photos);

        return array_slice($photos, 0, $count);
    }

    /**
     * Everything a rule can choose from, with the source of each picture at
     * the same position. A source that cannot list its stock contributes its
     * random handful.
     *
     * @return array{0: array<int, Photo>, 1: array<int, string>}
     */
    private function stock(int $count): array
    {
        $photos = [];
        $sources = [];

        foreach ($this->sources() as $key => [$source, $account]) {
            foreach ($source instanceof ListsPhotos ? $source->all($account) : $source->random($account, $count) as $photo) {
                $photos[] = $photo;
                $sources[] = $key;
            }
        }

        return [$photos, $sources];
    }

    /** @return array{source: string, id: string, url: string, caption: string|null, taken_on: string|null, favorite: bool|null} */
    public function entry(string $source, Photo $photo): array
    {
        return [
            'source' => $source,
            'id' => $photo->id,
            'url' => "/api/wall/photos/{$source}/{$photo->id}",
            'caption' => $photo->caption,
            'taken_on' => $photo->takenOn,
            'favorite' => $photo->favorite,
        ];
    }

    /** The file behind an ID — null when the source does not know it. */
    public function file(string $source, string $id): ?PhotoFile
    {
        $entry = $this->sources()[$source] ?? null;

        if ($entry === null) {
            return null;
        }

        [$provider, $account] = $entry;

        return $provider->file($account, $id);
    }

    /**
     * The configured sources along with their account.
     *
     * @return array<string, array{0: PhotoSource, 1: ProviderAccount|null}>
     */
    public function sources(): array
    {
        $accounts = ProviderAccount::query()
            ->forArea(ProviderArea::Photos)
            ->get()
            ->keyBy(static fn (ProviderAccount $account): string => (string) $account->provider);

        $sources = [];

        foreach ($this->registry->photoSources() as $key => $provider) {
            $account = $accounts->get($key);

            if ($account !== null && ! $account->isActive()) {
                continue;
            }

            if ($provider->isConfigured($account)) {
                $sources[$key] = [$provider, $account];
            }
        }

        return $sources;
    }
}

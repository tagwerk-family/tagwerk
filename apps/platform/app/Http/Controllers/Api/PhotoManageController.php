<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Exceptions\PermissionDenied;
use App\Http\Controllers\Controller;
use App\Integrations\Photos\Builtin\BuiltinPhotoSource;
use App\Models\Photo;
use App\Models\PhotoAlbum;
use App\Models\User;
use App\Services\PhotoImage;
use App\Services\Photos\PhotoCast;
use App\Services\Photos\PhotoLibrary;
use App\Support\Abilities;
use App\Support\HouseholdTimezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Managing the uploaded photos from the phone: a grid, a trash, restore.
 *
 * Deleting only moves a photo into the trash; the wall stops showing it at
 * once, and `fahoba:purge-photo-trash` removes file and row after
 * Photo::TRASH_DAYS. Whoever uploaded a photo may delete it, a parent with
 * `household.manage` any of them. Only uploaded photos — folders on the
 * server are managed where they live.
 */
final class PhotoManageController extends Controller
{
    private const PER_PAGE = 60;

    public function __construct(
        private readonly Abilities $abilities,
        private readonly PhotoImage $images,
        private readonly PhotoLibrary $library,
        private readonly PhotoCast $casts,
    ) {}

    /**
     * Newest first by the day taken, the upload day where none is known —
     * the grid groups by month like a phone's gallery. `favorites` or
     * `album` narrow it. Each photo says whether the asker may delete it.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'favorites' => ['nullable', 'boolean'],
            'album' => ['nullable', 'integer'],
        ]);

        $query = Photo::query()
            ->orderByRaw('coalesce(taken_on, created_at::date) desc')
            ->latest('created_at')
            ->latest('id');

        if ($request->boolean('favorites')) {
            $query->where('favorite', true);
        }

        if (isset($data['album'])) {
            $album = PhotoAlbum::query()->findOrFail($data['album']);
            $query->whereHas('albums', static fn ($albums) => $albums->whereKey($album->id));
        }

        return $this->page($request, $query);
    }

    /** The caption; whoever may delete a photo may also name it. */
    public function update(Request $request, int $photo): JsonResponse
    {
        $data = $request->validate(['caption' => ['present', 'nullable', 'string', 'max:255']]);
        $record = Photo::query()->findOrFail($photo);

        if (! $this->mayDelete($request, $record)) {
            throw new PermissionDenied(pinHelps: false);
        }

        $caption = trim((string) $data['caption']);
        $record->update(['caption' => $caption === '' ? null : $caption]);

        return response()->json(['caption' => $record->caption]);
    }

    /** "An der Wand zeigen": every wall of the household shows it for a while. */
    public function show(int $photo): JsonResponse
    {
        $record = Photo::query()->findOrFail($photo);

        $this->casts->show((int) $record->household_id, $this->library->entry(
            BuiltinPhotoSource::KEY,
            BuiltinPhotoSource::describe($record),
        ));

        return response()->json(['minutes' => PhotoCast::MINUTES]);
    }

    /** Only what the asker could restore; someone else's trash is not theirs to see. */
    public function trash(Request $request): JsonResponse
    {
        $query = Photo::onlyTrashed()->latest('deleted_at')->latest('id');

        if (! $this->managesAll($request)) {
            $query->where('user_id', $this->userId($request));
        }

        return $this->page($request, $query);
    }

    public function destroy(Request $request): JsonResponse
    {
        $photos = $this->chosen($request, Photo::query());
        $photos->each->delete();

        return response()->json(['deleted' => $photos->count()]);
    }

    public function restore(Request $request): JsonResponse
    {
        $photos = $this->chosen($request, Photo::onlyTrashed());
        $photos->each->restore();

        return response()->json(['restored' => $photos->count()]);
    }

    /** Gone for good, before the thirty days are up. Only from the trash. */
    public function purge(Request $request): JsonResponse
    {
        $photos = $this->chosen($request, Photo::onlyTrashed());
        $photos->each->forceDelete();

        return response()->json(['purged' => $photos->count()]);
    }

    /**
     * The picture for the grid (small) or for the large view (`full`).
     * Trashed photos too — the trash shows them.
     */
    public function image(Request $request, int $photo): BinaryFileResponse
    {
        $record = Photo::withTrashed()->findOrFail($photo);

        abort_if($record->trashed() && ! $this->mayDelete($request, $record), 404);
        abort_unless(Storage::disk('local')->exists($record->path), 404);

        $path = $request->boolean('full') ? $record->path : $this->images->thumbnail($record->path);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /** @param  Builder<Photo>  $query */
    private function page(Request $request, Builder $query): JsonResponse
    {
        $page = $query->paginate(self::PER_PAGE);

        return response()->json([
            'photos' => collect($page->items())->map(fn (Photo $photo): array => [
                'id' => $photo->id,
                'thumb' => "/api/photos/{$photo->id}/image",
                'url' => "/api/photos/{$photo->id}/image?full=1",
                'caption' => $photo->caption,
                'taken_on' => $photo->taken_on?->toDateString(),
                'added_on' => $photo->created_at?->setTimezone(HouseholdTimezone::current())->toDateString(),
                'favorite' => $photo->favorite,
                'mine' => $this->isOwn($request, $photo),
                'can_delete' => $this->mayDelete($request, $photo),
                'days_left' => $photo->daysLeft(),
            ])->all(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    /**
     * The photos named in `ids`, all of them the asker's to change — one
     * foreign photo refuses the whole request instead of doing half of it.
     *
     * @param  Builder<Photo>  $query
     * @return Collection<int, Photo>
     */
    private function chosen(Request $request, Builder $query): Collection
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
        ]);

        $photos = $query->whereIn('id', $data['ids'])->get();

        if ($photos->contains(fn (Photo $photo): bool => ! $this->mayDelete($request, $photo))) {
            throw new PermissionDenied(pinHelps: false);
        }

        return $photos;
    }

    private function mayDelete(Request $request, Photo $photo): bool
    {
        return $this->managesAll($request)
            || $this->isOwn($request, $photo);
    }

    private function isOwn(Request $request, Photo $photo): bool
    {
        return $photo->user_id !== null && (int) $photo->user_id === $this->userId($request);
    }

    /** A wall device has ids too, but never uploads; only accounts own photos. */
    private function userId(Request $request): ?int
    {
        $actor = $request->user();

        return $actor instanceof User ? (int) $actor->getKey() : null;
    }

    private function managesAll(Request $request): bool
    {
        return $this->abilities->allows($request->user(), Permission::HouseholdManage);
    }
}

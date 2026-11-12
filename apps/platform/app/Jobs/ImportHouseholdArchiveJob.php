<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Notifications\HouseholdMovedIn;
use App\Services\HouseholdArchive;
use App\Services\HouseholdImport;
use App\Support\OutgoingMail;
use Fahoba\PluginApi\Households\HouseholdImported;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Moves an uploaded archive in; the account that uploaded it hears how it went. */
final class ImportHouseholdArchiveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public readonly string $file, public readonly int $userId) {}

    public static function cacheKey(int $userId): string
    {
        return "household-import:{$userId}";
    }

    public function handle(HouseholdArchive $archive, HouseholdImport $import): void
    {
        $user = User::query()->find($this->userId);
        $folder = Storage::disk('local')->path('imports/'.basename($this->file, '.zip'));

        try {
            if ($user === null) {
                return;
            }

            $data = $archive->read(Storage::disk('local')->path($this->file), $folder);
            $result = $import->import($data, $folder, $user);

            event(new HouseholdImported((int) $result->household->id, (int) $user->id));

            Cache::put(self::cacheKey($this->userId), ['ok' => true, 'household' => $result->household->name, 'reconnect' => $result->reconnect], now()->addDay());

            if (OutgoingMail::enabled()) {
                $user->notify(new HouseholdMovedIn((int) $result->household->id, $result->household->name, $result->reconnect));
            }
        } catch (RuntimeException $e) {
            Cache::put(self::cacheKey($this->userId), ['ok' => false, 'message' => $e->getMessage()], now()->addDay());
        } finally {
            Storage::disk('local')->delete($this->file);
            (new Filesystem)->deleteDirectory($folder);
        }
    }
}

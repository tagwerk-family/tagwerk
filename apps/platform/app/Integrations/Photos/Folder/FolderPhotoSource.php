<?php

declare(strict_types=1);

namespace App\Integrations\Photos\Folder;

use App\Services\Photos\PhotoDateIndex;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Connections\ConnectedAccount;
use Fahoba\PluginApi\Connections\CredentialField;
use Fahoba\PluginApi\Connections\DescribesSetup;
use Fahoba\PluginApi\Connections\UsesCredentials;
use Fahoba\PluginApi\Photos\ListsPhotos;
use Fahoba\PluginApi\Photos\Photo;
use Fahoba\PluginApi\Photos\PhotoFile;
use Fahoba\PluginApi\Photos\PhotoSource;
use FilesystemIterator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A folder on the server, read recursively.
 *
 * This is the route for anything that can be synced somehow: a network
 * share, an rclone folder, the Amazon Photos app's folder. No token, no
 * expiry, no dependency on a service's whims.
 *
 * Reading happens strictly below the configured folder. A picture's ID is
 * the encoded path inside it, and before serving it is checked that it still
 * lies below after resolving — otherwise the source would be a read path onto
 * the whole server.
 *
 * The household does not pick an arbitrary server path, only a subfolder of
 * its own base `<fahoba.photo_folders>/<household id>`, which the operator
 * sets up. Otherwise one family could enter the upload directory — or the
 * folder of another family — and see its pictures.
 */
class FolderPhotoSource implements DescribesSetup, ListsPhotos, PhotoSource, UsesCredentials
{
    public const KEY = 'folder';

    /** Extensions a browser can display. */
    private const TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];

    /** Limit per scan: a picture archive can run to six figures. */
    private const MAX_FILES = 5000;

    /** How long the file list stays valid. A kiosk pulls a picture every 30 seconds. */
    private const TTL = 600;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('Folder on the server');
    }

    public function isConfigured(?ConnectedAccount $account): bool
    {
        return $this->root($account) !== null;
    }

    /** @return array<int, Photo> */
    public function random(?ConnectedAccount $account, int $count): array
    {
        $files = $this->files($account);

        shuffle($files);

        return array_map(
            static fn (string $relative): Photo => new Photo(id: self::encode($relative)),
            array_slice($files, 0, $count),
        );
    }

    /**
     * Every picture with the day it was taken.
     *
     * The dates come from an index that fills up in portions — see
     * PhotoDateIndex. Resolved lazily: the registry creates sources without
     * touching the database.
     *
     * @return array<int, Photo>
     */
    public function all(?ConnectedAccount $account): array
    {
        $root = $this->root($account);

        if ($account === null || $root === null) {
            return [];
        }

        $files = $this->files($account);
        $dates = app(PhotoDateIndex::class)->dates($account, $root, $files, count($files) < self::MAX_FILES);

        return array_map(
            static fn (string $relative): Photo => new Photo(id: self::encode($relative), takenOn: $dates[$relative] ?? null),
            $files,
        );
    }

    public function file(?ConnectedAccount $account, string $id): ?PhotoFile
    {
        $root = $this->root($account);
        $relative = self::decode($id);

        if ($root === null || $relative === null) {
            return null;
        }

        $mime = self::TYPES[mb_strtolower(pathinfo($relative, PATHINFO_EXTENSION))] ?? null;

        if ($mime === null) {
            return null;
        }

        /*
         * Resolved instead of concatenated: "../" in the path and a symlink
         * pointing out of the folder both look harmless before resolving.
         */
        $full = realpath($root.DIRECTORY_SEPARATOR.$relative);

        if ($full === false || ! is_file($full) || ! str_starts_with($full, $root.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return new PhotoFile($full, $mime);
    }

    /** @return array<int, CredentialField> */
    public function credentialFields(): array
    {
        return [
            new CredentialField(
                name: 'path',
                label: __('Subfolder'),
                secret: false,
                required: false,
                placeholder: $this->placeholder(),
                hint: __('Inside the photo folder the operator created for this household on the server. Leave empty for the whole folder; subfolders are read too.'),
            ),
        ];
    }

    /** @param  array<string, string|null>  $credentials */
    public function verifyCredentials(array $credentials): ?string
    {
        $household = app(HouseholdContext::class)->id();

        if (self::base($household) === null) {
            return __('There is no photo folder on the server for this household yet. The operator creates it (household :id).', ['id' => $household]);
        }

        $path = self::resolve($household, (string) ($credentials['path'] ?? ''));

        if ($path === null) {
            return __('This subfolder does not exist in the household\'s photo folder.');
        }

        if (! is_readable($path)) {
            return __('Family Home Base cannot read the folder.');
        }

        return null;
    }

    /** @param  array<string, string|null>  $credentials */
    public function accountLabel(array $credentials): string
    {
        return basename(trim((string) ($credentials['path'] ?? ''))) ?: $this->label();
    }

    public function icon(): string
    {
        return 'heroicon-o-folder';
    }

    public function description(): string
    {
        return __('Pictures from a folder on the server, including subfolders.');
    }

    /** @return array<int, string> */
    public function setupNotes(): array
    {
        return [
            __('The operator creates the household\'s photo folder on the server — a network share or a sync lands there.'),
            __('Family Home Base only reads; it moves, changes and deletes nothing.'),
        ];
    }

    protected function placeholder(): string
    {
        return __('Vacation');
    }

    /** The configured folder, resolved — null when it is missing. */
    private function root(?ConnectedAccount $account): ?string
    {
        if ($account === null) {
            return null;
        }

        return self::resolve($account->householdId(), trim((string) ($account->credentials()['path'] ?? '')));
    }

    /**
     * Removes the household's folder with everything in it — the household
     * is being deleted. A symlink is only unlinked, never followed: it may
     * point at the operator's archive.
     */
    public static function forget(int $household): void
    {
        $root = trim((string) config('fahoba.photo_folders'));
        $real = $root === '' ? false : realpath($root);

        if ($real === false) {
            return;
        }

        $folder = $real.DIRECTORY_SEPARATOR.$household;

        if (is_link($folder)) {
            unlink($folder);
        } elseif (is_dir($folder) && dirname((string) realpath($folder)) === $real) {
            (new Filesystem)->deleteDirectory($folder);
        }
    }

    /** The household's own photo folder on the server — null until the operator creates it. */
    private static function base(?int $household): ?string
    {
        $root = trim((string) config('fahoba.photo_folders'));

        if ($root === '' || $household === null) {
            return null;
        }

        $full = realpath($root.DIRECTORY_SEPARATOR.$household);

        return $full !== false && is_dir($full) ? $full : null;
    }

    /** A subfolder of the household's base, resolved — null when it leaves the base. */
    private static function resolve(?int $household, string $relative): ?string
    {
        $base = self::base($household);

        if ($base === null) {
            return null;
        }

        $full = realpath($base.DIRECTORY_SEPARATOR.ltrim(trim($relative), '/'));

        if ($full === false || ! is_dir($full)) {
            return null;
        }

        return $full === $base || str_starts_with($full, $base.DIRECTORY_SEPARATOR) ? $full : null;
    }

    /**
     * The pictures below the folder, relative.
     *
     * @return array<int, string>
     */
    private function files(?ConnectedAccount $account): array
    {
        $root = $this->root($account);

        if ($root === null) {
            return [];
        }

        /** @var array<int, string> */
        return Cache::remember(
            "photos:folder:{$account?->id}:".md5($root),
            self::TTL,
            fn (): array => $this->scan($root),
        );
    }

    /** @return array<int, string> */
    private function scan(string $root): array
    {
        $found = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            if (! isset(self::TYPES[mb_strtolower($file->getExtension())])) {
                continue;
            }

            $found[] = mb_substr((string) $file->getPathname(), mb_strlen($root) + 1);

            if (count($found) >= self::MAX_FILES) {
                break;
            }
        }

        return $found;
    }

    /** The path inside the folder, packed URL-safe. */
    private static function encode(string $relative): string
    {
        return rtrim(strtr(base64_encode($relative), '+/', '-_'), '=');
    }

    private static function decode(string $id): ?string
    {
        $raw = base64_decode(strtr($id, '-_', '+/'), true);

        return is_string($raw) && $raw !== '' && ! str_contains($raw, "\0") ? $raw : null;
    }
}

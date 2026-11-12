<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Household;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * A household to take along: `household.json` (the export) and its files
 * under `files/<disk>/<path>`. The family's answer to "give me my data",
 * and what another Fahoba server imports.
 */
final class HouseholdArchive
{
    private const JSON = 'household.json';

    /** Paths as Fahoba writes them — anything else in an archive is not read. */
    private const PATH = '#^[A-Za-z0-9_-]+(/[A-Za-z0-9_.-]+)*$#';

    public function __construct(private readonly HouseholdExport $export) {}

    public function write(Household $household, string $zip, bool $secrets = false): void
    {
        $data = $this->export->export($household, $secrets);

        $archive = new ZipArchive;

        if ($archive->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot write {$zip}.");
        }

        $archive->addFromString(self::JSON, (string) json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        foreach (self::files($data['tables']) as [$disk, $path]) {
            $absolute = Storage::disk($disk)->path($path);

            if (is_file($absolute)) {
                $archive->addFile($absolute, "files/{$disk}/{$path}");
                // Photos are compressed already.
                $archive->setCompressionName("files/{$disk}/{$path}", ZipArchive::CM_STORE);
            }
        }

        $archive->close();
    }

    /**
     * Extracts the export and the files its rows name — nothing else, so a
     * crafted archive cannot write outside the folder.
     *
     * @return array<string, mixed>
     */
    public function read(string $zip, string $folder): array
    {
        $archive = new ZipArchive;

        if ($archive->open($zip, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException(__('This is no Fahoba household export.'));
        }

        $json = $archive->getFromName(self::JSON);
        $data = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($data) || ! is_array($data['tables'] ?? null)) {
            $archive->close();

            throw new RuntimeException(__('This is no Fahoba household export.'));
        }

        foreach (self::files($data['tables']) as [$disk, $path]) {
            $content = $archive->getFromName("files/{$disk}/{$path}");

            if (is_string($content)) {
                $target = "{$folder}/{$disk}/{$path}";
                is_dir(dirname($target)) || mkdir(dirname($target), 0o775, true);
                file_put_contents($target, $content);
            }
        }

        $archive->close();

        return $data;
    }

    /** The extracted file, or null when the archive did not have it. */
    public static function extracted(string $folder, string $disk, string $path): ?string
    {
        $file = "{$folder}/{$disk}/{$path}";

        return preg_match(self::PATH, $path) === 1 && ! str_contains($path, '..') && is_file($file) ? $file : null;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $tables
     * @return list<array{string, string}> disk, path
     */
    private static function files(array $tables): array
    {
        $files = [];

        foreach (HouseholdImport::FILE_COLUMNS as $table => $columns) {
            foreach ($tables[$table] ?? [] as $row) {
                foreach ($columns as $column => $disk) {
                    $value = $row[$column] ?? null;
                    $paths = $column === 'pages' ? (array) json_decode((string) $value, true) : [$value];

                    foreach ($paths as $path) {
                        if (is_string($path) && preg_match(self::PATH, $path) === 1 && ! str_contains($path, '..')) {
                            $files[] = [$disk, $path];
                        }
                    }
                }
            }
        }

        return $files;
    }
}

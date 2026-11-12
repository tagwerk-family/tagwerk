<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\HouseholdArchive;
use App\Services\HouseholdExport;
use App\Services\HouseholdImport;
use Fahoba\PluginApi\Households\HouseholdImported;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * Two imports. Without --new: an export of `fahoba:export-household` into
 * an empty database with the same ids — locally only, to look at a
 * family's data. With --new: a household moving in from another server
 * (ZIP from Export & move, or JSON), with new ids, next to the households
 * already here.
 */
final class ImportHouseholdCommand extends Command
{
    protected $signature = 'fahoba:import-household
        {file : ZIP from Export & move, or a JSON export (optionally gzipped)}
        {--new : Move in with new ids, next to existing households}
        {--importer= : With --new: address of the account here that becomes its membership}';

    protected $description = 'Imports one exported household';

    public function handle(HouseholdExport $export, HouseholdArchive $archive, HouseholdImport $import): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->error("Cannot read {$file}.");

            return self::FAILURE;
        }

        $folder = null;

        try {
            if (str_ends_with(strtolower($file), '.zip')) {
                $folder = sys_get_temp_dir().'/fahoba-import-'.bin2hex(random_bytes(6));
                $data = $archive->read($file, $folder);
            } else {
                $content = (string) file_get_contents($file);
                $content = str_starts_with($content, "\x1f\x8b") ? (string) gzdecode($content) : $content;
                $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
                $data = is_array($data) ? $data : [];
            }

            if (! $this->option('new')) {
                if (app()->isProduction()) {
                    $this->error('Not on a production server: without --new the import is for a local copy.');

                    return self::FAILURE;
                }

                $export->import($data);
                $this->info("Household {$data['household_id']} imported (exported {$data['exported_at']}).");

                return self::SUCCESS;
            }

            $importer = null;

            if (is_string($this->option('importer')) && $this->option('importer') !== '') {
                $importer = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($this->option('importer'))])->first();

                if ($importer === null) {
                    $this->error('No account with that address here.');

                    return self::FAILURE;
                }
            }

            $result = $import->import($data, $folder, $importer);
            event(new HouseholdImported((int) $result->household->id, $importer?->id));
        } catch (\JsonException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($folder !== null) {
                (new Filesystem)->deleteDirectory($folder);
            }
        }

        $this->info("Moved in as household {$result->household->id} ({$result->household->name}).");

        if ($result->reconnect !== []) {
            $this->line('Connect again: '.implode(', ', $result->reconnect));
        }

        if ($result->skipped !== []) {
            $this->warn('Left out (not installed here): '.implode(', ', $result->skipped));
        }

        return self::SUCCESS;
    }
}

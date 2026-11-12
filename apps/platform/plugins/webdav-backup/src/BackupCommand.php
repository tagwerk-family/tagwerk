<?php

declare(strict_types=1);

namespace Fahoba\WebDavBackup;

use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Writes `db_<stamp>.dump` (pg_dump -Fc, like `deploy/backup.sh`) and
 * `storage_<stamp>.tar.gz` into the WebDAV folder and removes what is older
 * than the days to keep. Without `--now` it runs only once a day, after the
 * chosen time.
 */
final class BackupCommand extends Command
{
    protected $signature = 'fahoba:webdav-backup {--now : Run regardless of time and today\'s run}';

    protected $description = 'Back up database and uploads to the WebDAV folder set under Operations → Extensions';

    private const DONE_ON = 'webdav-backup:done-on';

    private const STAMP = 'Y-m-d_Hi';

    public function handle(BackupExtension $extension): int
    {
        $settings = $extension->settings();

        if ($settings === null) {
            if ($this->option('now')) {
                $this->error('The extension is off or has no folder yet.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        $now = CarbonImmutable::now();

        if (! $this->option('now') && ($now->format('H:i') < $settings['time'] || Cache::get(self::DONE_ON) === $now->toDateString())) {
            return self::SUCCESS;
        }

        $dir = storage_path('framework/webdav-backup');
        File::ensureDirectoryExists($dir);
        $stamp = $now->format(self::STAMP);
        $files = ["db_{$stamp}.dump" => "{$dir}/db.dump", "storage_{$stamp}.tar.gz" => "{$dir}/storage.tar.gz"];

        try {
            $this->dump($files["db_{$stamp}.dump"]);
            $this->archive($files["storage_{$stamp}.tar.gz"]);

            $dav = $this->dav($settings);
            $this->ensureFolder($dav, $settings['url']);

            foreach ($files as $name => $path) {
                $this->upload($dav, $settings['url'].$name, $path);
            }

            $removed = $this->prune($dav, $settings['url'], $now->subDays($settings['keep']));
            $this->remember(true, __('Written: :files. Removed: :count.', ['files' => implode(', ', array_keys($files)), 'count' => $removed]));
            Cache::forever(self::DONE_ON, $now->toDateString());
            $this->info("Backup {$stamp} written, {$removed} old file(s) removed.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            /*
             * Marked done anyway: a broken folder would otherwise fail every
             * quarter hour until midnight. The message stays on the
             * extension's settings, and the log has it.
             */
            Cache::forever(self::DONE_ON, $now->toDateString());
            $this->remember(false, $exception->getMessage());
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            File::delete(array_values($files));
        }
    }

    private function dump(string $target): void
    {
        $db = config('database.connections.'.config('database.default'));

        Process::timeout(1800)
            ->env(['PGPASSWORD' => (string) ($db['password'] ?? '')])
            ->run(['pg_dump', '-Fc', '-h', (string) $db['host'], '-p', (string) $db['port'], '-U', (string) $db['username'], '-f', $target, (string) $db['database']])
            ->throw();
    }

    private function archive(string $target): void
    {
        // The temporary files live under storage/framework, not storage/app: they never pack themselves.
        Process::timeout(1800)->run(['tar', '-C', storage_path('app'), '-czf', $target, '.'])->throw();
    }

    /** @param array{username: string, password: string} $settings */
    private function dav(array $settings): PendingRequest
    {
        return Http::withBasicAuth($settings['username'], $settings['password'])->timeout(1800)->connectTimeout(10);
    }

    /** Creates the folder; 405 means it exists already. */
    private function ensureFolder(PendingRequest $dav, string $url): void
    {
        $status = $dav->send('MKCOL', $url)->status();

        if (! in_array($status, [201, 405], true) && ($status < 200 || $status >= 300)) {
            throw new RuntimeException(__('The WebDAV folder answered :status.', ['status' => $status]));
        }
    }

    private function upload(PendingRequest $dav, string $url, string $path): void
    {
        $response = $dav->withBody(Utils::streamFor(fopen($path, 'rb')), 'application/octet-stream')->put($url);

        if (! $response->successful()) {
            throw new RuntimeException(__('The WebDAV folder answered :status.', ['status' => $response->status()]));
        }
    }

    /** Removes this command's files older than `$before`, judged by the stamp in the name. */
    private function prune(PendingRequest $dav, string $url, CarbonImmutable $before): int
    {
        $response = $dav->withHeaders(['Depth' => '1'])
            ->withBody('<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>', 'application/xml')
            ->send('PROPFIND', $url);

        preg_match_all('#<(?:[a-z]+:)?href>([^<]+)</(?:[a-z]+:)?href>#i', $response->body(), $matches);
        $removed = 0;

        foreach ($matches[1] as $href) {
            $name = rawurldecode(basename($href));

            if (preg_match('/^(?:db|storage)_(\d{4}-\d{2}-\d{2}_\d{4})\.(?:dump|tar\.gz)$/', $name, $stamp) !== 1) {
                continue;
            }

            if (CarbonImmutable::createFromFormat(self::STAMP, $stamp[1])->lessThan($before)) {
                $dav->delete($url.rawurlencode($name));
                $removed++;
            }
        }

        return $removed;
    }

    private function remember(bool $ok, string $message): void
    {
        Cache::forever(BackupExtension::LAST_RUN, [
            'at' => CarbonImmutable::now()->format('Y-m-d H:i'),
            'ok' => $ok,
            'message' => $message,
        ]);
    }
}

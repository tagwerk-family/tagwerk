<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Filament\Pages\Extensions as ExtensionsPage;
use App\Integrations\Plugins\Extensions;
use App\Models\User;
use Carbon\CarbonImmutable;
use Fahoba\WebDavBackup\BackupExtension;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Once a day after the chosen time, database and uploads go to the folder,
 * and what is older than the days to keep leaves it.
 */
final class WebDavBackupTest extends TestCase
{
    use RefreshDatabase;

    private const FOLDER = 'https://nas.local/dav/backup/';

    private bool $refusing = false;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Process::fake(function (PendingProcess $process) {
            $command = (array) $process->command;
            $target = $command[0] === 'pg_dump' ? $command[array_search('-f', $command, true) + 1] : $command[array_search('-czf', $command, true) + 1];
            file_put_contents($target, $command[0]);

            return Process::result();
        });
        Http::fake(fn (Request $request) => $this->refusing ? Http::response('', 401) : match ($request->method()) {
            'MKCOL' => Http::response('', 405),
            'PROPFIND' => Http::response('<d:multistatus xmlns:d="DAV:">'
                .'<d:response><d:href>/dav/backup/</d:href></d:response>'
                .'<d:response><d:href>/dav/backup/db_2026-09-01_0317.dump</d:href></d:response>'
                .'<d:response><d:href>/dav/backup/storage_2026-10-19_0317.tar.gz</d:href></d:response>'
                .'<d:response><d:href>/dav/backup/notes.txt</d:href></d:response>'
                .'</d:multistatus>', 207),
            default => Http::response('', 201),
        });
    }

    private function enable(array $settings = []): void
    {
        app(Extensions::class)->save(BackupExtension::KEY, true, [
            'url' => self::FOLDER,
            'username' => 'backup',
            'password' => Crypt::encryptString('secret'),
            'time' => '03:17',
            'keep' => 14,
            ...$settings,
        ]);
    }

    public function test_off_nothing_happens(): void
    {
        $this->artisan('fahoba:webdav-backup')->assertSuccessful();

        Http::assertNothingSent();
        Process::assertNothingRan();
    }

    public function test_before_the_time_nothing_happens(): void
    {
        $this->enable();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 03:00'));

        $this->artisan('fahoba:webdav-backup')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_after_the_time_both_files_go_up_and_old_ones_leave(): void
    {
        $this->enable();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 03:30'));

        $this->artisan('fahoba:webdav-backup')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === self::FOLDER.'db_2026-10-20_0330.dump'
            && $request->body() === 'pg_dump'
            && $request->header('Authorization')[0] === 'Basic '.base64_encode('backup:secret'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request->url() === self::FOLDER.'storage_2026-10-20_0330.tar.gz');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === self::FOLDER.'db_2026-09-01_0317.dump');
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), 'storage_2026-10-19'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), 'notes.txt'));

        $this->assertTrue(Cache::get(BackupExtension::LAST_RUN)['ok']);
        $this->assertFileDoesNotExist(storage_path('framework/webdav-backup/db.dump'));
    }

    public function test_once_a_day(): void
    {
        $this->enable();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 03:30'));
        $this->artisan('fahoba:webdav-backup')->assertSuccessful();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 03:45'));
        $this->artisan('fahoba:webdav-backup')->assertSuccessful();

        Http::assertSentCount(5); // MKCOL, two PUTs, PROPFIND, one DELETE — once
    }

    public function test_a_refusing_folder_is_reported_on_the_settings(): void
    {
        $this->refusing = true;
        $this->enable();

        $this->artisan('fahoba:webdav-backup', ['--now' => true])->assertFailed();

        $last = Cache::get(BackupExtension::LAST_RUN);
        $this->assertFalse($last['ok']);
        $this->assertSame(__('The WebDAV folder answered :status.', ['status' => 401]), $last['message']);
    }

    public function test_the_password_is_stored_encrypted_and_kept(): void
    {
        $operator = User::factory()->create(['household_id' => null]);
        $operator->forceFill(['is_operator' => true])->save();
        $this->actingAs($operator);
        Filament::setCurrentPanel('admin');

        $save = fn (string $password) => Livewire::test(ExtensionsPage::class)
            ->set('data.fahoba_webdav_backup.enabled', true)
            ->set('data.fahoba_webdav_backup.settings.url', self::FOLDER)
            ->set('data.fahoba_webdav_backup.settings.username', 'backup')
            ->set('data.fahoba_webdav_backup.settings.password', $password)
            ->set('data.fahoba_webdav_backup.settings.time', '03:17')
            ->set('data.fahoba_webdav_backup.settings.keep', 14)
            ->call('save')
            ->assertHasNoFormErrors();

        $save('secret');
        $stored = app(Extensions::class)->settings(BackupExtension::KEY)['password'];
        $this->assertNotSame('secret', $stored);
        $this->assertSame('secret', app(BackupExtension::class)->settings()['password']);

        $save('');
        $this->assertSame('secret', app(BackupExtension::class)->settings()['password']);
    }
}

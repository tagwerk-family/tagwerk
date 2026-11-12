<?php

declare(strict_types=1);

namespace Fahoba\WebDavBackup;

use App\Integrations\Plugins\Extensions;
use Fahoba\PluginApi\Plugins\Extension;
use Fahoba\PluginApi\Plugins\ExtensionCategory;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * The server's database and uploads, every night, into a WebDAV folder.
 *
 * The address is the operator's, not a household's, so it may be a NAS in
 * the operator's own network — no `OutboundHttp` here. The `.env` with
 * `APP_KEY` is not part of it: whoever has the backup must not have the key.
 */
final class BackupExtension implements Extension
{
    public const KEY = 'fahoba/webdav-backup';

    /** What the last run left: `['at' => ISO time, 'ok' => bool, 'message' => string]`. */
    public const LAST_RUN = 'webdav-backup:last-run';

    public function __construct(private readonly Extensions $extensions) {}

    public function label(): string
    {
        return __('Backup to WebDAV');
    }

    public function description(): string
    {
        return __('Every night, the database and all uploads go into a WebDAV folder — Nextcloud, a NAS or a storage box. Keep the .env with APP_KEY separately.');
    }

    public function category(): ExtensionCategory
    {
        return ExtensionCategory::General;
    }

    public function settingsSchema(): array
    {
        return [
            TextInput::make('url')
                ->label(__('WebDAV folder'))
                ->url()
                ->required()
                ->placeholder('https://cloud.example.org/remote.php/dav/files/backup/fahoba/'),
            TextInput::make('username')->label(__('User name'))->required(),
            TextInput::make('password')
                ->label(__('Password'))
                ->password()
                ->revealable(false)
                ->formatStateUsing(static fn (): ?string => null)
                ->placeholder(fn (): ?string => filled($this->stored('password')) ? __('Stored — leave empty to keep it') : null)
                ->required(fn (): bool => blank($this->stored('password')))
                // Stored encrypted; empty keeps the one already stored.
                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Crypt::encryptString($state) : $this->stored('password')),
            TimePicker::make('time')->label(__('Time (server time)'))->seconds(false)->default('03:17')->required(),
            TextInput::make('keep')->label(__('Keep for days'))->integer()->minValue(1)->maxValue(365)->default(14)->required(),
            TextEntry::make('last_run')
                ->label(__('Last backup'))
                ->state(static function (): string {
                    $last = Cache::get(self::LAST_RUN);

                    return is_array($last) ? $last['at'].' — '.$last['message'] : __('Not yet');
                }),
        ];
    }

    public function refuse(bool $enabled, array $settings, Authenticatable $by): ?string
    {
        return null;
    }

    public function configurePanel(Panel $panel): void {}

    /** @return array{url: string, username: string, password: string, time: string, keep: int}|null null while off or incomplete */
    public function settings(): ?array
    {
        $settings = $this->extensions->settings(self::KEY);

        if (! $this->extensions->enabled(self::KEY) || blank($settings['url'] ?? null) || blank($settings['password'] ?? null)) {
            return null;
        }

        return [
            'url' => rtrim((string) $settings['url'], '/').'/',
            'username' => (string) ($settings['username'] ?? ''),
            'password' => Crypt::decryptString((string) $settings['password']),
            'time' => substr((string) ($settings['time'] ?? '03:17'), 0, 5),
            'keep' => max(1, (int) ($settings['keep'] ?? 14)),
        ];
    }

    private function stored(string $key): mixed
    {
        return $this->extensions->settings(self::KEY)[$key] ?? null;
    }
}

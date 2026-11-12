<?php

declare(strict_types=1);

namespace App\Services;

use App\Dashboard\WidgetRegistry;
use App\Integrations\Plugins\PluginCatalog;
use Fahoba\PluginApi\Plugins\MovesSecrets;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Secrets are encrypted with this server's APP_KEY and unreadable anywhere
 * else. On request (a move) the export carries them in plain text, wrapped
 * in a marker no column or form ever stores, and both imports encrypt them
 * again with the target's key.
 *
 * Only what belongs to the household and works on another server: the
 * connections' credentials and tokens, a plugin's `MovesSecrets`, tile
 * secrets. What is bound to this server — push subscriptions (its VAPID
 * keys), Google's push channels (its address), sign-in tokens, second
 * factors — stays scrubbed.
 */
final readonly class ExportSecrets
{
    public const MARK = 'fahoba_plain_secret';

    /** The mark of an export written before the rename to Fahoba. */
    private const LEGACY_MARK = 'tagwerk_plain_secret';

    /** Encrypted columns (Laravel's `encrypted`, `encrypted:array`) that may move. */
    private const COLUMNS = [
        'provider_accounts' => ['credentials', 'access_token', 'refresh_token'],
    ];

    /** Plain columns scrubbed only because their partner was. */
    private const ALONGSIDE = [
        'provider_accounts' => ['token_expires_at'],
    ];

    public function __construct(
        private WidgetRegistry $widgets,
        private PluginCatalog $plugins,
    ) {}

    /** @return array<string, list<string>> table → columns that move instead of being scrubbed */
    public function movable(): array
    {
        $columns = self::COLUMNS;

        foreach ($this->plugins->offered('export') as $class) {
            $rules = app($class);

            if ($rules instanceof MovesSecrets) {
                $columns = array_merge_recursive($columns, $rules->movableSecrets());
            }
        }

        return $columns;
    }

    /** @return array<string, list<string>> */
    public function spared(): array
    {
        return array_merge_recursive($this->movable(), self::ALONGSIDE);
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $tables
     * @return array<string, list<array<string, mixed>>>
     */
    public function reveal(array $tables): array
    {
        foreach ($this->movable() as $table => $columns) {
            $tables[$table] = array_map(function (array $row) use ($columns): array {
                foreach ($columns as $column) {
                    if (is_string($row[$column] ?? null) && $row[$column] !== '') {
                        $row[$column] = $this->plain($row[$column]);
                    }
                }

                return $row;
            }, $tables[$table] ?? []);
        }

        $tables['dashboard_widgets'] = array_map(function (array $row): array {
            $settings = is_string($row['settings'] ?? null) ? json_decode($row['settings'], true) : null;

            if (! is_array($settings)) {
                return $row;
            }

            foreach ($this->widgets->find((string) ($row['type'] ?? ''))?->settings ?? [] as $field) {
                if ($field->kind === 'secret' && is_string($settings[$field->key] ?? null)) {
                    $settings[$field->key] = $this->plain($settings[$field->key]);
                }
            }

            return [...$row, 'settings' => json_encode($settings)];
        }, $tables['dashboard_widgets'] ?? []);

        return $tables;
    }

    /**
     * Encrypts every marked value of a row with this server's key — in a
     * column, and inside tile settings.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function seal(string $table, array $row): array
    {
        foreach ($row as $column => $value) {
            if (($plain = self::marked($value)) !== null) {
                $row[$column] = Crypt::encryptString($plain);
            }
        }

        if ($table === 'dashboard_widgets' && is_string($row['settings'] ?? null)) {
            $settings = json_decode($row['settings'], true);

            if (is_array($settings)) {
                foreach ($settings as $key => $value) {
                    if (($plain = self::marked($value)) !== null) {
                        $settings[$key] = Crypt::encryptString($plain);
                    }
                }

                $row['settings'] = json_encode($settings);
            }
        }

        return $row;
    }

    /** @return array{fahoba_plain_secret: string}|null null when unreadable here too — the form asks again there */
    private function plain(string $encrypted): ?array
    {
        try {
            return [self::MARK => Crypt::decryptString($encrypted)];
        } catch (DecryptException) {
            return null;
        }
    }

    /** The plain secret of a marked value, null for anything else. */
    private static function marked(mixed $value): ?string
    {
        foreach ([self::MARK, self::LEGACY_MARK] as $mark) {
            if (is_array($value) && array_keys($value) === [$mark] && is_string($value[$mark])) {
                return $value[$mark];
            }
        }

        return null;
    }
}

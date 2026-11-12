<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\Plugins\PluginCatalog;
use App\Models\Household;
use App\Support\RenamedFromTagwerk;
use Fahoba\PluginApi\Plugins\ExportRules;
use Fahoba\PluginApi\Plugins\LoadReport;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * One household as plain rows, out of a server and into an empty database —
 * to look at what a family really has, never as a backup (that is
 * `deploy/backup.sh`).
 *
 * Rows go through the query builder, not the models: no household scope, no
 * casts, no events — what is stored is what arrives.
 */
final class HouseholdExport
{
    public const FORMAT = 1;

    /** Tables of the server itself; nothing in them belongs to a household. */
    private const SYSTEM = [
        'cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs', 'migrations',
        'password_reset_tokens', 'personal_access_tokens', 'sessions', 'plugin_settings', 'catalog_recipes',
        // A plugin's sign-in links: like tokens, not part of a local copy.
        'social_identities',
        // Messages to accounts — sent on this server, read here, no family data.
        'notifications',
    ];

    /** Tables without `household_id`, reached through the row they hang off. */
    private const PIVOTS = [
        'calendar_person' => ['calendar_id', 'calendars'],
        'event_person' => ['event_id', 'events'],
        'photo_album_photo' => ['photo_album_id', 'photo_albums'],
    ];

    /*
     * Cleared on the way out. A local copy with these would sync with the
     * family's Google account, push to their phones and tablets, or keep a
     * browser signed in. Encrypted ones would not even be readable without
     * the server's APP_KEY.
     */
    private const SCRUBBED = [
        'provider_accounts' => ['credentials', 'access_token', 'refresh_token', 'token_expires_at'],
        'devices' => ['push_endpoint', 'push_p256dh', 'push_auth', 'push_subscribed_at'],
        'calendars' => ['push_channel_id', 'push_resource_id', 'push_expires_at'],
        'users' => ['remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'],
    ];

    /**
     * `$secrets`: passwords in plain text (`ExportSecrets`) — for a move to a
     * server with another APP_KEY. Whoever holds such a file holds the keys.
     *
     * @return array{format: int, migration: string, household_id: int, exported_at: string, secrets: bool, tables: array<string, list<array<string, mixed>>>}
     */
    public function export(Household $household, bool $secrets = false): array
    {
        // A plugin that did not load took its scrub rules along: its
        // secrets would travel into the copy.
        if (($failed = app(LoadReport::class)->failures()) !== []) {
            throw new RuntimeException('Plugins did not load ('.implode(', ', array_keys($failed)).'); their export rules are missing.');
        }

        $id = (int) $household->id;
        $tables = ['households' => $this->rows(DB::table('households')->where('id', $id))];

        foreach ($this->householdTables() as $table) {
            $tables[$table] = $this->rows(DB::table($table)->where('household_id', $id));
        }

        // Accounts by their membership here; memberships elsewhere stay home.
        $tables['users'] = $this->rows(DB::table('users')->whereIn(
            'id',
            DB::table('memberships')->where('household_id', $id)->select('user_id'),
        ));

        foreach (self::PIVOTS as $table => [$key, $parent]) {
            $tables[$table] = $this->rows(DB::table($table)->whereIn(
                $key,
                DB::table($parent)->where('household_id', $id)->select('id'),
            ));
        }

        $spared = $secrets ? app(ExportSecrets::class)->spared() : [];

        foreach ($this->scrubbed() as $table => $columns) {
            $columns = array_values(array_diff($columns, $spared[$table] ?? []));
            $tables[$table] = array_map(
                static fn (array $row): array => [...$row, ...array_fill_keys(array_intersect($columns, array_keys($row)), null)],
                $tables[$table] ?? [],
            );
        }

        if ($secrets) {
            $tables = app(ExportSecrets::class)->reveal($tables);
        }

        return [
            'format' => self::FORMAT,
            'secrets' => $secrets,
            'migration' => (string) DB::table('migrations')->orderByDesc('id')->value('migration'),
            'household_id' => $id,
            'exported_at' => now()->toIso8601String(),
            'tables' => $tables,
        ];
    }

    /**
     * Into a database that has the schema but no household yet.
     *
     * Foreign keys are switched off for the transaction: the rows arrive
     * table by table, not in the order their references need. Afterwards
     * each sequence continues after the highest id, so new rows do not
     * collide with imported ones.
     *
     * @param  array<string, mixed>  $data
     */
    public function import(array $data): void
    {
        if (($data['format'] ?? null) !== self::FORMAT || ! is_array($data['tables'] ?? null)) {
            throw new RuntimeException('Not a household export of this version.');
        }

        if (DB::table('households')->exists()) {
            throw new RuntimeException('The database already has households; import only into an empty one.');
        }

        if (! DB::table('migrations')->where('migration', $data['migration'] ?? '')->exists()) {
            throw new RuntimeException("The export comes from a schema this code does not know ({$data['migration']}); update and migrate first.");
        }

        DB::transaction(function () use ($data): void {
            DB::statement('SET LOCAL session_replication_role = replica');

            foreach ($data['tables'] as $table => $rows) {
                $this->insert((string) $table, $rows);
            }
        });
    }

    /** @return list<string> */
    private function systemTables(): array
    {
        return array_merge(self::SYSTEM, ...array_map(
            static fn (ExportRules $rules): array => $rules->systemTables(),
            $this->exportRules(),
        ));
    }

    /** @return array<string, list<string>> */
    private function scrubbed(): array
    {
        return array_merge(self::SCRUBBED, ...array_map(
            static fn (ExportRules $rules): array => $rules->scrubbed(),
            $this->exportRules(),
        ));
    }

    /** @return list<ExportRules> */
    private function exportRules(): array
    {
        return array_values(array_map(
            static fn (string $class): ExportRules => app($class),
            app(PluginCatalog::class)->offered('export'),
        ));
    }

    /**
     * Every table with a `household_id` — read from the schema, so a new
     * domain table travels along without a change here. A table that has
     * none and is not known as system or pivot table stops the export:
     * silently leaving it out would make a copy that looks complete.
     *
     * @return list<string>
     */
    private function householdTables(): array
    {
        $columns = DB::select(
            "select t.table_name as name, bool_or(c.column_name = 'household_id') as scoped
             from information_schema.tables t
             join information_schema.columns c using (table_schema, table_name)
             where t.table_schema = current_schema() and t.table_type = 'BASE TABLE'
             group by t.table_name
             order by t.table_name",
        );

        $scoped = [];

        foreach ($columns as $table) {
            if ($table->name === 'users' || in_array($table->name, $this->systemTables(), true)) {
                continue;
            }

            if ($table->scoped) {
                $scoped[] = (string) $table->name;
            } elseif ($table->name !== 'households' && ! array_key_exists($table->name, self::PIVOTS)) {
                throw new RuntimeException("Table {$table->name} has no household_id and is not known to the export.");
            }
        }

        return $scoped;
    }

    /** @return list<array<string, mixed>> */
    private function rows(Builder $query): array
    {
        return $query->get()->map(static fn (object $row): array => (array) $row)->values()->all();
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function insert(string $table, array $rows): void
    {
        // The name comes from a file and ends up in SQL: only tables this schema has.
        if (preg_match('/^[a-z_]+$/', $table) !== 1 || ! Schema::hasTable($table)) {
            throw new RuntimeException("Unknown table {$table} in the export.");
        }

        $known = array_flip(Schema::getColumnListing($table));

        $rows = array_map(static fn (array $row): array => ExportSecrets::seal($table, RenamedFromTagwerk::row($table, $row)), $rows);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table($table)->insert(array_map(
                static fn (array $row): array => array_intersect_key($row, $known),
                $chunk,
            ));
        }

        if (isset($known['id'])) {
            DB::statement(
                "select setval(pg_get_serial_sequence(?, 'id'), coalesce(max(id), 1), max(id) is not null) from {$table}",
                [$table],
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\ProviderRegistry;
use App\Models\Household;
use App\Models\User;
use App\Support\RenamedFromTagwerk;
use Fahoba\PluginApi\Connections\UsesCredentials;
use Fahoba\PluginApi\Connections\UsesOAuth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A household moving in from another server — into a database that has
 * households of its own. Every row gets a new id; the foreign keys are
 * rewritten from PostgreSQL's catalog, so a new table needs nothing here.
 *
 * Foreign keys stay on (the hosted database user is no superuser): tables
 * go in the order their references need, and a column that closes a cycle
 * goes in empty and is filled afterwards.
 */
final class HouseholdImport
{
    /**
     * Ids inside JSON are no foreign keys. A list column names the table its
     * ids point to; an object column names it per key. `HouseholdImportTest`
     * fails on a JSON column neither here nor in `ID_FREE` — a missed one
     * would silently point at another family's person.
     *
     * @var array<string, string|array<string, string>>
     */
    public const EMBEDDED_IDS = [
        'tasks.rotation' => 'people',
        'tasks.per_person_ids' => 'people',
        'meal_plan_entries.eaters' => 'people',
        'users.hidden_calendar_ids' => 'calendars',
        'devices.hidden_calendar_ids' => 'calendars',
        'devices.preferences' => self::PREFERENCE_IDS,
        'memberships.preferences' => self::PREFERENCE_IDS,
        'users.preferences' => self::PREFERENCE_IDS,
    ];

    /**
     * JSON columns known to hold no ids of this database. `dismissed_notices`
     * names connections inside its keys; a stale one only lets a closed
     * notice show once more.
     */
    public const ID_FREE = [
        'dashboard_widgets.settings', 'devices.permissions', 'people.permissions', 'memberships.dismissed_notices',
        'households.disabled_features', 'households.meal_kinds', 'households.opted_in_features', 'households.pantry_staples',
        'provider_accounts.settings', 'recipes.categories', 'recipes.ingredients', 'recipes.license', 'recipes.pages',
        'tasks.dayparts', 'webhooks.events',
    ];

    /** Files a row points to: table → column → disk. `recipes.pages` is a list. */
    public const FILE_COLUMNS = [
        'photos' => ['path' => 'local'],
        'recipes' => ['photo_path' => 'local', 'pages' => 'local'],
        'people' => ['avatar_path' => 'public'],
    ];

    private const PREFERENCE_IDS = ['task_people' => 'people', 'calendar_people' => 'people', 'photo_albums' => 'photo_albums'];

    /** @var array<string, array<int|string, int>> table → old id → new id */
    private array $map = [];

    /** @var array<int, true> old user ids that arrive as an invitation */
    private array $invited = [];

    /** @var array<int, true> old user ids this server already had */
    private array $existing = [];

    /** @var list<array{string, string, string}> disk, from (in the archive), to */
    private array $copies = [];

    /** @var array<string, list<string>> */
    private array $nullable = [];

    /**
     * @param  array<string, mixed>  $data  what `HouseholdExport::export()` wrote
     * @param  ?string  $files  the archive's extracted `files/` folder
     * @param  ?User  $importer  must be in the export by address; becomes its membership
     */
    public function import(array $data, ?string $files = null, ?User $importer = null): ImportResult
    {
        if (($data['format'] ?? null) !== HouseholdExport::FORMAT || ! is_array($data['tables'] ?? null) || ! is_array($data['tables']['households'][0] ?? null)) {
            throw new RuntimeException(__('This is no Fahoba household export.'));
        }

        if (! DB::table('migrations')->where('migration', (string) ($data['migration'] ?? ''))->exists()) {
            throw new RuntimeException(__('The export comes from a newer Fahoba. Update this server first.'));
        }

        /** @var array<string, list<array<string, mixed>>> $tables */
        $tables = $data['tables'];

        if ($importer !== null && ! collect($tables['users'] ?? [])->contains(fn (array $user): bool => mb_strtolower((string) $user['email']) === mb_strtolower((string) $importer->email))) {
            throw new RuntimeException(__('Your address is not in this export. Sign in with the address you use on the other server.'));
        }

        $this->map = $this->invited = $this->existing = $this->copies = [];
        $keys = $this->foreignKeys();
        $skipped = array_values(array_filter(array_keys($tables), fn (string $table): bool => ! $this->importable($table)));
        $present = array_values(array_diff(array_keys($tables), $skipped));
        [$order, $deferred] = $this->order($present, $keys);

        $household = DB::transaction(function () use ($tables, $keys, $order, $deferred, $importer, $files): Household {
            $later = [];

            foreach ($order as $table) {
                foreach ($tables[$table] as $row) {
                    $this->insert($table, $row, $keys[$table] ?? [], $deferred[$table] ?? [], $importer, $files, $later);
                }
            }

            foreach ($later as [$table, $id, $column, $ref, $old]) {
                DB::table($table)->where('id', $id)->update([$column => $this->map[$ref][$old] ?? null]);
            }

            $this->rewriteEmbedded($tables);

            return Household::query()->findOrFail($this->map['households'][(int) $tables['households'][0]['id']]);
        });

        foreach ($this->copies as [$disk, $from, $to]) {
            Storage::disk($disk)->put($to, (string) file_get_contents($from));
        }

        // What came without its passwords (or with one this server could not read).
        $reconnect = collect($tables['provider_accounts'] ?? [])
            ->filter(fn (array $account): bool => self::needsSecrets((string) $account['area'], (string) $account['provider']))
            ->reject(fn (array $account): bool => self::carriesSecrets($account))
            ->map(fn (array $account): string => $account['area'].': '.$account['provider'])
            ->unique()
            ->values()
            ->all();

        return new ImportResult($household, $reconnect, $skipped);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $keys  column → referenced table
     * @param  list<string>  $deferred
     * @param  list<array{string, int, string, string, int|string}>  $later
     */
    private function insert(string $table, array $row, array $keys, array $deferred, ?User $importer, ?string $files, array &$later): void
    {
        $old = $row['id'] ?? null;

        if ($table === 'users' && $this->existingUser($row, $importer)) {
            return;
        }

        $nullable = $this->nullable($table);
        $postponed = [];

        foreach ($keys as $column => $ref) {
            if (! isset($row[$column])) {
                continue;
            }

            if (in_array($column, $deferred, true)) {
                $postponed[$column] = [$ref, $row[$column]];
                $row[$column] = null;

                continue;
            }

            $new = $this->map[$ref][$row[$column]] ?? null;

            if ($new === null && ! in_array($column, $nullable, true)) {
                // Points at a row that did not come along (an operator, another household).
                return;
            }

            $row[$column] = $new;
        }

        if ($table === 'users') {
            $row['is_operator'] = false;
        }

        // Moved in means starting fresh here: neither suspended nor read-only.
        if ($table === 'households') {
            $row['suspended_at'] = null;
            $row['read_only_since'] = null;
        }

        if ($table === 'memberships' && in_array($row['user_id'], $this->invitedIds(), true)) {
            $row['accepted_at'] = null;
        }

        $row = $this->files($table, $row, $files);

        $row = ExportSecrets::seal($table, RenamedFromTagwerk::row($table, $row));
        $known = array_flip(Schema::getColumnListing($table));
        $row = array_intersect_key($row, $known);

        if (! isset($known['id'])) {
            DB::table($table)->insert($row);

            return;
        }

        unset($row['id']);
        $id = (int) DB::table($table)->insertGetId($row);
        $this->map[$table][$old] = $id;

        foreach ($postponed as $column => [$ref, $value]) {
            $later[] = [$table, $id, $column, $ref, $value];
        }
    }

    /**
     * An address this server knows is never merged blindly: the importer's
     * own becomes its membership, any other arrives as an invitation —
     * never with the export's password hash.
     *
     * @param  array<string, mixed>  $row
     */
    private function existingUser(array $row, ?User $importer): bool
    {
        $existing = User::query()->whereRaw('lower(email) = ?', [mb_strtolower((string) $row['email'])])->first();

        if ($existing === null) {
            return false;
        }

        $this->existing[(int) $row['id']] = true;

        if ($existing->is_operator !== true) {
            $this->map['users'][$row['id']] = (int) $existing->id;

            if ($existing->id !== $importer?->id) {
                $this->invited[(int) $row['id']] = true;
            }
        }

        return true;
    }

    /**
     * Files keep their path unless this server already has one there.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function files(string $table, array $row, ?string $files): array
    {
        foreach (self::FILE_COLUMNS[$table] ?? [] as $column => $disk) {
            if (! isset($row[$column]) || $row[$column] === '') {
                continue;
            }

            $list = $column === 'pages';
            $paths = $list ? (array) json_decode((string) $row[$column], true) : [(string) $row[$column]];
            $kept = [];

            foreach ($paths as $path) {
                $source = $files === null ? null : HouseholdArchive::extracted($files, $disk, (string) $path);

                if ($source === null) {
                    continue;
                }

                $target = Storage::disk($disk)->exists((string) $path)
                    ? dirname((string) $path).'/'.Str::lower((string) Str::ulid()).'.'.pathinfo((string) $path, PATHINFO_EXTENSION)
                    : (string) $path;

                $this->copies[] = [$disk, $source, $target];
                $kept[] = $target;
            }

            $row[$column] = $list ? json_encode($kept) : ($kept[0] ?? null);
        }

        return $row;
    }

    /** @param array<string, list<array<string, mixed>>> $tables */
    private function rewriteEmbedded(array $tables): void
    {
        foreach (self::EMBEDDED_IDS as $key => $spec) {
            [$table, $column] = explode('.', $key);

            foreach ($tables[$table] ?? [] as $row) {
                $id = $this->map[$table][$row['id'] ?? null] ?? null;
                $value = isset($row[$column]) ? json_decode((string) $row[$column], true) : null;

                // An existing account keeps its own settings.
                if ($id === null || ! is_array($value) || ($table === 'users' && $this->wasExisting((int) $row['id']))) {
                    continue;
                }

                if (is_string($spec)) {
                    $value = $this->mapIds($value, $spec);
                } else {
                    foreach ($spec as $entry => $ref) {
                        if (is_array($value[$entry] ?? null)) {
                            $value[$entry] = $this->mapIds($value[$entry], $ref);
                        }
                    }
                }

                DB::table($table)->where('id', $id)->update([$column => json_encode($value)]);
            }
        }
    }

    /** A consent or a non-empty form — the built-in calendar and Koch-Wiki have neither. */
    private static function needsSecrets(string $area, string $key): bool
    {
        try {
            $provider = app(ProviderRegistry::class)->all($area)[$key] ?? null;
        } catch (Throwable) {
            return true;
        }

        return $provider === null
            || $provider instanceof UsesOAuth
            || ($provider instanceof UsesCredentials && $provider->credentialFields() !== []);
    }

    /** @param array<string, mixed> $account */
    private static function carriesSecrets(array $account): bool
    {
        foreach (['credentials', 'access_token', 'refresh_token'] as $column) {
            if (is_array($account[$column] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<int> new ids of the accounts that arrive as an invitation */
    private function invitedIds(): array
    {
        return array_values(array_intersect_key($this->map['users'] ?? [], $this->invited));
    }

    private function wasExisting(int $oldUserId): bool
    {
        return isset($this->existing[$oldUserId]);
    }

    /**
     * Ids that did not come along are dropped; null ("nobody") stays.
     *
     * @param  array<int|string, mixed>  $ids
     * @return list<int|null>
     */
    private function mapIds(array $ids, string $table): array
    {
        $mapped = [];

        foreach ($ids as $id) {
            if ($id === null) {
                $mapped[] = null;
            } elseif (isset($this->map[$table][$id])) {
                $mapped[] = $this->map[$table][$id];
            }
        }

        return $mapped;
    }

    private function importable(string $table): bool
    {
        return preg_match('/^[a-z_]+$/', $table) === 1 && Schema::hasTable($table);
    }

    /**
     * Tables in the order their references need, and per table the columns
     * that wait for a second pass: self references, and one nullable column
     * per cycle.
     *
     * @param  list<string>  $tables
     * @param  array<string, array<string, string>>  $keys
     * @return array{list<string>, array<string, list<string>>}
     */
    private function order(array $tables, array $keys): array
    {
        $deferred = [];
        $edges = [];

        foreach ($tables as $table) {
            foreach ($keys[$table] ?? [] as $column => $ref) {
                if ($ref === $table) {
                    $deferred[$table][] = $column;
                } elseif (in_array($ref, $tables, true)) {
                    $edges[$table][$column] = $ref;
                }
            }
        }

        $order = [];
        $remaining = $tables;

        while ($remaining !== []) {
            $ready = array_values(array_filter($remaining, fn (string $table): bool => array_diff(array_values($edges[$table] ?? []), $order) === []));

            if ($ready !== []) {
                $order = [...$order, ...$ready];
                $remaining = array_values(array_diff($remaining, $ready));

                continue;
            }

            // A cycle: postpone one nullable reference inside it.
            $broken = false;

            foreach ($remaining as $table) {
                foreach ($edges[$table] ?? [] as $column => $ref) {
                    if (in_array($ref, $remaining, true) && in_array($column, $this->nullable($table), true)) {
                        $deferred[$table][] = $column;
                        unset($edges[$table][$column]);
                        $broken = true;

                        break 2;
                    }
                }
            }

            if (! $broken) {
                throw new RuntimeException('The tables '.implode(', ', $remaining).' reference each other without a nullable column.');
            }
        }

        return [$order, $deferred];
    }

    /** @return array<string, array<string, string>> table → column → referenced table */
    private function foreignKeys(): array
    {
        $rows = DB::select(
            "select cl.relname as tbl, att.attname as col, ref.relname as ref
             from pg_constraint con
             join pg_class cl on cl.oid = con.conrelid
             join pg_class ref on ref.oid = con.confrelid
             join pg_namespace ns on ns.oid = cl.relnamespace
             join pg_attribute att on att.attrelid = cl.oid and att.attnum = con.conkey[1]
             where con.contype = 'f' and ns.nspname = current_schema() and array_length(con.conkey, 1) = 1",
        );

        $keys = [];

        foreach ($rows as $row) {
            $keys[(string) $row->tbl][(string) $row->col] = (string) $row->ref;
        }

        return $keys;
    }

    /** @return list<string> */
    private function nullable(string $table): array
    {
        return $this->nullable[$table] ??= array_map(
            static fn (object $row): string => (string) $row->column_name,
            DB::select("select column_name from information_schema.columns where table_schema = current_schema() and table_name = ? and is_nullable = 'YES'", [$table]),
        );
    }
}

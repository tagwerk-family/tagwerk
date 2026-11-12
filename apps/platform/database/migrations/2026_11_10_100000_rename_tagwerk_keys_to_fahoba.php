<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renamed from Tagwerk: the bundled plugins' keys move from `tagwerk/…` to
 * `fahoba/…` wherever a database keeps them — connections, favourites,
 * catalogs, tiles, designs, extensions' settings, preferences — and the
 * built-in calendar's id from `tagwerk` to `fahoba`; under the old id
 * `BuiltinCalendar` would add a second family calendar.
 *
 * Every column of the schema, so plugins' own tables are covered too. Not
 * the tables holding serialized PHP (cache, queue, sessions): a changed
 * length breaks them, and they refill themselves. `RenamedFromTagwerk` does
 * the same for the rows of an older export.
 */
return new class extends Migration
{
    private const SKIPPED = [
        'migrations', 'cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches', 'sessions',
    ];

    public function up(): void
    {
        $this->rename('tagwerk/', 'fahoba/');

        DB::update("update calendars set external_id = 'fahoba' where external_id = 'tagwerk'
            and provider_account_id in (select id from provider_accounts where provider = 'builtin')");
        DB::update("update provider_accounts set account_label = 'Fahoba' where provider = 'builtin' and account_label = 'Tagwerk'");
    }

    public function down(): void
    {
        $this->rename('fahoba/', 'tagwerk/');

        DB::update("update calendars set external_id = 'tagwerk' where external_id = 'fahoba'
            and provider_account_id in (select id from provider_accounts where provider = 'builtin')");
        DB::update("update provider_accounts set account_label = 'Tagwerk' where provider = 'builtin' and account_label = 'Fahoba'");
    }

    private function rename(string $from, string $to): void
    {
        $columns = DB::select(
            "select c.table_name, c.column_name, c.data_type
               from information_schema.columns c
               join information_schema.tables t on t.table_schema = c.table_schema and t.table_name = c.table_name
              where c.table_schema = current_schema() and t.table_type = 'BASE TABLE'
                and c.data_type in ('character varying', 'text', 'json', 'jsonb')"
        );

        foreach ($columns as $column) {
            if (in_array($column->table_name, self::SKIPPED, true)) {
                continue;
            }

            $table = '"'.$column->table_name.'"';
            $name = '"'.$column->column_name.'"';

            // `json` keeps the text as written, and PHP's json_encode() escapes
            // the slash: "tagwerk\/bring".
            if (in_array($column->data_type, ['json', 'jsonb'], true)) {
                $escaped = str_replace('/', '\\/', $from);
                DB::update(
                    "update {$table} set {$name} = replace(replace({$name}::text, ?, ?), ?, ?)::{$column->data_type}
                      where strpos({$name}::text, ?) > 0 or strpos({$name}::text, ?) > 0",
                    ['"'.$from, '"'.$to, '"'.$escaped, '"'.str_replace('/', '\\/', $to), '"'.$from, '"'.$escaped],
                );
            } else {
                DB::update(
                    "update {$table} set {$name} = ? || substr({$name}, ?) where {$name} like ?",
                    [$to, strlen($from) + 1, $from.'%'],
                );
            }
        }
    }
};

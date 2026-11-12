<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\MigrationSeal;
use Tests\TestCase;

/**
 * A released migration stays as it was. A server that ran it never runs it
 * again, so a change would only reach fresh installations — and during a
 * rollout the previous release still serves on the same schema. Fixes go
 * into a new migration; `fahoba:seal-migrations` seals at release time.
 */
final class MigrationsAppendOnlyTest extends TestCase
{
    public function test_a_sealed_migration_neither_changes_nor_disappears(): void
    {
        $current = app(MigrationSeal::class)->current();

        foreach (app(MigrationSeal::class)->sealed() as $path => $hash) {
            $this->assertArrayHasKey($path, $current, "Released migration {$path} was removed. Leave it and add a new one.");
            $this->assertSame($hash, $current[$path], "Released migration {$path} was changed. Revert it and add a new one.");
        }
    }

    public function test_the_seal_covers_the_released_migrations(): void
    {
        $this->assertNotEmpty(app(MigrationSeal::class)->sealed(), 'Run php artisan fahoba:seal-migrations.');
    }

    /**
     * The schema dump is where a fresh database starts; an upgraded one never
     * reads it again. Changing it would give the two different schemas.
     */
    public function test_the_schema_dump_is_sealed_like_a_migration(): void
    {
        $this->assertArrayHasKey('database/schema/pgsql-schema.sql', app(MigrationSeal::class)->current());
        $this->assertArrayHasKey('database/schema/pgsql-schema.sql', app(MigrationSeal::class)->sealed());
    }
}

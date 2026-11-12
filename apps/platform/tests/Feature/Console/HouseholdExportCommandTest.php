<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Calendar;
use App\Models\Household;
use App\Models\Person;
use App\Models\PointEntry;
use App\Models\ProviderAccount;
use App\Models\Task;
use App\Models\TaskCompletion;
use App\Models\User;
use App\Services\HouseholdExport;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\ExportRules;
use Fahoba\PluginApi\Plugins\LoadReport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * One household out of a server and into an empty local database, to look
 * at what a family really has. Nothing of another household leaves, and
 * nothing that would let the copy act on the family's accounts or tablets.
 */
final class HouseholdExportCommandTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Person $child;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = tempnam(sys_get_temp_dir(), 'household').'.json';

        $this->household = Household::factory()->create(['name' => 'Huber']);
        app(HouseholdContext::class)->setId((int) $this->household->id);

        $this->child = Person::factory()->child()->create(['household_id' => $this->household->id, 'name' => 'Lena']);
        $task = Task::factory()->create(['household_id' => $this->household->id, 'points' => 2]);
        $completion = TaskCompletion::create([
            'task_id' => $task->id,
            'person_id' => $this->child->id,
            'occurred_on' => '2026-10-01',
            'completed_at' => now(),
        ]);
        PointEntry::query()->create(['person_id' => $this->child->id, 'points' => 2, 'reason' => 'Tisch', 'task_completion_id' => $completion->id]);

        $calendar = Calendar::factory()->create(['household_id' => $this->household->id]);
        $calendar->people()->attach($this->child->id);

        ProviderAccount::factory()->create(['household_id' => $this->household->id])
            ->forceFill(['access_token' => 'secret-access', 'refresh_token' => 'secret-refresh', 'credentials' => ['password' => 'secret']])
            ->save();
        User::factory()->create(['household_id' => $this->household->id])
            ->forceFill(['remember_token' => 'remember-me'])
            ->save();

        $other = Household::factory()->create(['name' => 'Fremd']);
        Person::factory()->create(['household_id' => $other->id, 'name' => 'Fremdes Kind']);

        app(HouseholdContext::class)->setId(null);
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function exported(): array
    {
        $this->artisan('fahoba:export-household', ['household' => $this->household->id, '--output' => $this->file])
            ->assertSuccessful();

        return json_decode((string) file_get_contents($this->file), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_exports_the_household_and_nothing_of_another(): void
    {
        $data = $this->exported();

        $this->assertSame($this->household->id, $data['household_id']);
        $this->assertSame(['Huber'], array_column($data['tables']['households'], 'name'));
        $this->assertSame(['Lena'], array_column($data['tables']['people'], 'name'));
        $this->assertCount(1, $data['tables']['point_entries']);
        $this->assertCount(1, $data['tables']['task_completions']);
        $this->assertSame([$this->child->id], array_column($data['tables']['calendar_person'], 'person_id'));
        $this->assertArrayNotHasKey('sessions', $data['tables']);
        $this->assertArrayNotHasKey('personal_access_tokens', $data['tables']);
    }

    /** A local copy must not reach the family's Google account, phones or sessions. */
    public function test_leaves_out_what_would_act_on_the_family(): void
    {
        $data = $this->exported();

        $account = $data['tables']['provider_accounts'][0];
        $this->assertNull($account['access_token']);
        $this->assertNull($account['refresh_token']);
        $this->assertNull($account['credentials']);
        $this->assertNull($data['tables']['users'][0]['remember_token']);
    }

    /** A plugin names its server tables and secrets; the export follows. */
    public function test_a_plugin_states_what_happens_to_its_tables(): void
    {
        Schema::create('acme_cursors', function (Blueprint $table): void {
            $table->id();
        });
        app(Catalog::class)->offer('export', 'acme/rules', AcmeExportRules::class);

        $data = $this->exported();

        $this->assertArrayNotHasKey('acme_cursors', $data['tables']);
        $this->assertNull($data['tables']['people'][0]['name']);
    }

    public function test_an_unknown_table_without_household_stops_the_export(): void
    {
        Schema::create('acme_cursors', function (Blueprint $table): void {
            $table->id();
        });

        $this->expectExceptionMessage('acme_cursors');

        app(HouseholdExport::class)->export($this->household);
    }

    /** A plugin that did not load took its scrub rules along; its secrets would travel. */
    public function test_the_export_refuses_while_a_plugin_did_not_load(): void
    {
        app(LoadReport::class)->fail('acme/hooks', 'Cannot reach the service.');

        $this->expectExceptionMessage('acme/hooks');

        app(HouseholdExport::class)->export($this->household);
    }

    public function test_an_unknown_household_fails(): void
    {
        $this->artisan('fahoba:export-household', ['household' => 999999, '--output' => $this->file])
            ->assertFailed();
    }

    /**
     * Into an empty database with the same ids, and new rows continue after
     * them instead of colliding.
     */
    public function test_imports_into_an_empty_database(): void
    {
        $this->exported();
        // An empty database: accounts belong to no household, so they go too.
        DB::table('households')->delete();
        DB::table('users')->delete();

        $this->artisan('fahoba:import-household', ['file' => $this->file])->assertSuccessful();

        $this->assertSame(1, DB::table('households')->count());
        $this->assertSame('Lena', DB::table('people')->where('id', $this->child->id)->value('name'));
        $this->assertSame(2, (int) DB::table('point_entries')->sum('points'));
        $this->assertSame(1, DB::table('calendar_person')->count());

        $next = Household::factory()->create();
        $this->assertGreaterThan($this->household->id, $next->id);
    }

    /** The development database is not overwritten by accident. */
    public function test_refuses_a_database_that_already_has_households(): void
    {
        $this->exported();
        $before = DB::table('households')->count();

        $this->artisan('fahoba:import-household', ['file' => $this->file])->assertFailed();

        $this->assertSame($before, DB::table('households')->count());
    }

    /** A copy from a newer server would miss columns here. */
    public function test_refuses_an_export_from_an_unknown_schema(): void
    {
        $data = $this->exported();
        $data['migration'] = '2099_01_01_000000_from_the_future';
        file_put_contents($this->file, json_encode($data, JSON_THROW_ON_ERROR));
        DB::table('households')->delete();

        $this->artisan('fahoba:import-household', ['file' => $this->file])->assertFailed();

        $this->assertSame(0, DB::table('households')->count());
    }
}

final class AcmeExportRules implements ExportRules
{
    public function systemTables(): array
    {
        return ['acme_cursors'];
    }

    public function scrubbed(): array
    {
        return ['people' => ['name']];
    }
}

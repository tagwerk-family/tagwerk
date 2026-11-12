<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Calendar;
use App\Models\DashboardWidget;
use App\Models\Device;
use App\Models\Event;
use App\Models\Household;
use App\Models\Membership;
use App\Models\Person;
use App\Models\Photo;
use App\Models\ProviderAccount;
use App\Models\Task;
use App\Models\User;
use App\Services\HouseholdArchive;
use App\Services\HouseholdExport;
use App\Services\HouseholdImport;
use Fahoba\PluginApi\Plugins\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Feature\Dashboard\StationWidget;
use Tests\TestCase;

/**
 * A household moves into a server that has households of its own: new ids,
 * every reference rewritten — foreign keys and ids inside JSON alike.
 */
final class HouseholdImportTest extends TestCase
{
    use RefreshDatabase;

    /** A JSON column nobody classified could carry another family's person ids. */
    public function test_every_json_column_is_classified(): void
    {
        $system = ['catalog_recipes', 'plugin_settings', 'notifications'];
        $columns = collect(DB::select("select table_name || '.' || column_name as name from information_schema.columns where table_schema = current_schema() and data_type in ('json', 'jsonb')"))
            ->pluck('name')
            ->reject(fn (string $name): bool => in_array(explode('.', $name)[0], $system, true))
            ->reject(fn (string $name): bool => array_key_exists($name, HouseholdImport::EMBEDDED_IDS) || in_array($name, HouseholdImport::ID_FREE, true))
            ->values()
            ->all();

        $this->assertSame([], $columns, 'Add these to HouseholdImport::EMBEDDED_IDS or ID_FREE.');
    }

    public function test_a_household_moves_in_with_new_ids(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $source = Household::factory()->create(['name' => 'Familie Huber', 'read_only_since' => now()]);
        $anna = Person::factory()->create(['household_id' => $source->id, 'name' => 'Anna']);
        $ben = Person::factory()->create(['household_id' => $source->id, 'name' => 'Ben']);
        $task = Task::factory()->create(['household_id' => $source->id, 'rotation' => [$ben->id, $anna->id]]);
        $calendar = Calendar::withoutHouseholdScope()->where('household_id', $source->id)->firstOrFail();
        $event = Event::factory()->create(['household_id' => $source->id, 'calendar_id' => $calendar->id]);
        $event->people()->attach($anna->id);
        $photo = Photo::factory()->create(['household_id' => $source->id]);
        Storage::disk('local')->put($photo->path, 'picture');

        $importer = User::factory()->create(['household_id' => $source->id, 'person_id' => $anna->id, 'email' => 'anna@example.org']);
        Membership::query()->where('user_id', $importer->id)->update(['preferences' => json_encode(['task_people' => [$ben->id, null]])]);
        $relative = User::factory()->create(['household_id' => $source->id, 'person_id' => $ben->id, 'email' => 'oma@example.org']);

        $zip = tempnam(sys_get_temp_dir(), 'tw').'.zip';
        app(HouseholdArchive::class)->write($source, $zip);
        $folder = sys_get_temp_dir().'/tw-'.uniqid();
        $data = app(HouseholdArchive::class)->read($zip, $folder);

        $result = app(HouseholdImport::class)->import($data, $folder, $importer);
        $moved = $result->household;

        $this->assertNotSame($source->id, $moved->id);
        $this->assertSame('Familie Huber', $moved->name);
        $this->assertFalse($moved->isReadOnly());

        $people = Person::withoutHouseholdScope()->where('household_id', $moved->id)->pluck('id', 'name');
        $this->assertCount(2, $people);
        $this->assertNotContains($anna->id, $people->all());

        $movedTask = Task::withoutHouseholdScope()->where('household_id', $moved->id)->sole();
        $this->assertSame([$people['Ben'], $people['Anna']], $movedTask->rotation);
        $this->assertNotSame($task->id, $movedTask->id);

        $movedEvent = Event::withoutHouseholdScope()->where('household_id', $moved->id)->sole();
        $movedCalendar = Calendar::withoutHouseholdScope()->where('household_id', $moved->id)->where('id', $movedEvent->calendar_id)->sole();
        $this->assertSame('builtin', $movedCalendar->provider ?? 'builtin');
        $this->assertSame([$people['Anna']], DB::table('event_person')->where('event_id', $movedEvent->id)->pluck('person_id')->all());

        // The importer's own address becomes the membership, with the preferences mapped.
        $membership = Membership::query()->where('household_id', $moved->id)->where('user_id', $importer->id)->sole();
        $this->assertNotNull($membership->accepted_at);
        $this->assertSame($people['Anna'], $membership->person_id);
        $this->assertSame([$people['Ben'], null], $membership->preferences['task_people']);

        // Another known address is invited, never merged.
        $this->assertNull(Membership::query()->where('household_id', $moved->id)->where('user_id', $relative->id)->sole()->accepted_at);
        $this->assertSame(2, User::query()->count());

        // The photo existed on this server already: a new path, the same picture.
        $movedPhoto = Photo::withoutHouseholdScope()->where('household_id', $moved->id)->sole();
        $this->assertNotSame($photo->path, $movedPhoto->path);
        $this->assertSame('picture', Storage::disk('local')->get($movedPhoto->path));

        // The source household is untouched.
        $this->assertSame([$ben->id, $anna->id], $task->fresh()->rotation);
    }

    public function test_tile_secrets_travel_only_on_request_and_are_encrypted_again(): void
    {
        app(Catalog::class)->offer('widgets', 'acme/station', StationWidget::class);

        $source = Household::factory()->create();
        $device = Device::factory()->create(['household_id' => $source->id]);
        DashboardWidget::withoutHouseholdScope()->create([
            'household_id' => $source->id, 'device_id' => $device->id, 'type' => 'acme/station',
            'x' => 0, 'y' => 0, 'w' => 3, 'h' => 2, 'position' => 0,
            'settings' => ['station' => 'Wien', 'key' => Crypt::encryptString('geheim-123')],
        ]);

        $plain = app(HouseholdExport::class)->export($source);
        $this->assertStringNotContainsString('geheim-123', (string) json_encode($plain));
        $this->assertFalse($plain['secrets']);

        $revealed = app(HouseholdExport::class)->export($source, secrets: true);
        $this->assertStringContainsString('geheim-123', (string) json_encode($revealed));
        $this->assertTrue($revealed['secrets']);

        $moved = app(HouseholdImport::class)->import($revealed)->household;
        $stored = DashboardWidget::withoutHouseholdScope()->where('household_id', $moved->id)->sole()->settings;

        $this->assertIsString($stored['key']);
        $this->assertNotSame('geheim-123', $stored['key']);
        $this->assertSame('geheim-123', Crypt::decryptString($stored['key']));
        $this->assertSame('Wien', $stored['station']);
    }

    public function test_connections_move_with_their_passwords_but_not_their_push_channels(): void
    {
        $source = Household::factory()->create();
        ProviderAccount::withoutHouseholdScope()->create([
            'household_id' => $source->id, 'area' => 'lists', 'provider' => 'fahoba/bring', 'status' => 'active', 'account_label' => 'Bring',
            'credentials' => ['email' => 'anna@example.org', 'password' => 'bring-geheim'],
        ]);
        $calendar = Calendar::withoutHouseholdScope()->where('household_id', $source->id)->firstOrFail();
        $calendar->forceFill(['push_channel_id' => 'channel-1'])->save();

        $plain = app(HouseholdExport::class)->export($source);
        $this->assertStringNotContainsString('bring-geheim', (string) json_encode($plain));
        $this->assertSame(['lists: fahoba/bring'], app(HouseholdImport::class)->import($plain)->reconnect);

        $revealed = app(HouseholdExport::class)->export($source, secrets: true);
        $this->assertStringNotContainsString('channel-1', (string) json_encode($revealed));

        $result = app(HouseholdImport::class)->import($revealed);
        $account = ProviderAccount::withoutHouseholdScope()->where('household_id', $result->household->id)->where('provider', 'fahoba/bring')->sole();

        $this->assertSame([], $result->reconnect);
        $this->assertSame('bring-geheim', $account->credentials['password']);
    }

    public function test_the_importer_must_be_in_the_export(): void
    {
        $source = Household::factory()->create();
        User::factory()->create(['household_id' => $source->id]);
        $stranger = User::factory()->create(['household_id' => Household::factory()->create()->id]);

        $this->expectException(RuntimeException::class);

        app(HouseholdImport::class)->import(app(HouseholdExport::class)->export($source), null, $stranger);
    }

    public function test_an_export_from_a_newer_release_is_refused(): void
    {
        $data = app(HouseholdExport::class)->export(Household::factory()->create());
        $data['migration'] = '2099_01_01_000000_from_the_future';

        $this->expectExceptionMessage('neuer');

        app(HouseholdImport::class)->import($data);
    }
}

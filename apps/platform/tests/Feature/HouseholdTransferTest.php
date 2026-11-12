<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\BuildHouseholdArchiveJob;
use App\Jobs\ImportHouseholdArchiveJob;
use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use App\Notifications\HouseholdArchiveReady;
use App\Notifications\HouseholdMovedIn;
use App\Services\HouseholdArchive;
use Fahoba\PluginApi\Households\HouseholdImported;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Export & move: the family takes its data along or brings it here. */
final class HouseholdTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_stays_open_while_read_only(): void
    {
        $household = Household::factory()->create(['read_only_since' => now()]);
        $parent = User::factory()->create(['household_id' => $household->id]);

        $this->actingAs($parent)->get('/admin/export')->assertOk()->assertSee('Export')->assertDontSee('Haushalt hierher umziehen');
    }

    public function test_the_hosted_line_shows_only_when_configured(): void
    {
        $parent = User::factory()->create(['household_id' => Household::factory()->create()->id]);

        $this->actingAs($parent)->get('/admin/export')->assertOk()->assertDontSee('Fahoba Cloud');

        config(['fahoba.hosted_url' => 'https://cloud.example/signup']);
        $this->actingAs($parent)->get('/admin/export')->assertOk()->assertSee('https://cloud.example/signup');
    }

    public function test_the_export_arrives_by_a_signed_link(): void
    {
        Storage::fake('local');
        Notification::fake();
        $household = Household::factory()->create();
        $parent = User::factory()->create(['household_id' => $household->id]);

        BuildHouseholdArchiveJob::dispatchSync((int) $household->id, (int) $parent->id);

        $archive = Cache::get(BuildHouseholdArchiveJob::cacheKey((int) $household->id));
        Storage::disk('local')->assertExists($archive['file']);

        $url = null;
        Notification::assertSentTo($parent, HouseholdArchiveReady::class, function (HouseholdArchiveReady $mail) use (&$url, $household): bool {
            $url = HouseholdArchiveReady::url($household, $mail->file);

            return true;
        });

        $this->get((string) $url)->assertOk()->assertDownload('fahoba-household-'.$household->id.'.zip');
        $this->get(preg_replace('/signature=[^&]+/', 'signature=forged', (string) $url))->assertForbidden();
    }

    public function test_an_upload_moves_in_as_a_new_household(): void
    {
        Storage::fake('local');
        Notification::fake();
        Event::fake([HouseholdImported::class]);

        $source = Household::factory()->create(['name' => 'Familie Huber']);
        $anna = Person::factory()->create(['household_id' => $source->id]);
        $importer = User::factory()->create(['household_id' => $source->id, 'person_id' => $anna->id]);

        Storage::disk('local')->makeDirectory('imports');
        app(HouseholdArchive::class)->write($source, Storage::disk('local')->path('imports/upload.zip'));

        ImportHouseholdArchiveJob::dispatchSync('imports/upload.zip', (int) $importer->id);

        $this->assertSame(2, Household::query()->where('name', 'Familie Huber')->count());
        $this->assertSame(2, $importer->memberships()->count());
        Event::assertDispatched(HouseholdImported::class, fn (HouseholdImported $event): bool => $event->importedBy === $importer->id);
        Notification::assertSentTo($importer, HouseholdMovedIn::class);
        Storage::disk('local')->assertMissing('imports/upload.zip');
        $this->assertTrue(Cache::get(ImportHouseholdArchiveJob::cacheKey((int) $importer->id))['ok']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConnectionStatus;
use App\Enums\ProviderArea;
use App\Filament\Resources\Households\Pages\EditHousehold;
use App\Integrations\Photos\Folder\FolderPhotoSource;
use App\Integrations\Photos\Rules\LookBackRule;
use App\Integrations\Photos\Rules\WeightedRule;
use App\Integrations\Plugins\PluginCatalog;
use App\Models\Household;
use App\Models\Photo as PhotoRow;
use App\Models\PhotoDate;
use App\Models\ProviderAccount;
use App\Models\User;
use App\Services\Photos\PhotoLibrary;
use App\Services\Photos\PhotoRules;
use App\Services\Photos\TakenOn;
use App\Support\HouseholdContext;
use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Photos\Photo;
use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\LoadReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use stdClass;
use Tests\TestCase;

/**
 * The photo frame's rules: which pictures it prefers, by the day they were
 * taken.
 */
final class PhotoRulesTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->today = CarbonImmutable::parse('2026-09-30');
        CarbonImmutable::setTestNow($this->today->setTime(12, 0));

        $this->household = Household::factory()->create(['timezone' => 'Europe/Vienna']);
        app(HouseholdContext::class)->setId((int) $this->household->id);
    }

    /**
     * @return array<int, Photo>
     */
    private function photos(int $amount, ?string $takenOn, string $prefix): array
    {
        return array_map(
            static fn (int $number): Photo => new Photo(id: "{$prefix}{$number}", takenOn: $takenOn),
            range(1, $amount),
        );
    }

    /**
     * @param  array<int, Photo>  $photos
     * @param  array<int, int>  $picked
     * @return array<string, int> prefix => how many
     */
    private function countByPrefix(array $photos, array $picked): array
    {
        $counts = [];

        foreach ($picked as $position) {
            $prefix = preg_replace('/\d+$/', '', $photos[$position]->id);
            $counts[$prefix] = ($counts[$prefix] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /** Counted out, not rolled: forty pictures at 80/20 are exactly 32 and 8. */
    public function test_look_back_takes_four_in_five_from_the_last_year(): void
    {
        $photos = [
            ...$this->photos(100, '2026-03-01', 'recent'),
            ...$this->photos(100, '2023-06-01', 'older'),
            ...$this->photos(100, '2018-06-01', 'ancient'),
            ...$this->photos(50, null, 'undated'),
        ];

        $picked = (new LookBackRule)->pick($photos, 40, $this->today);

        $this->assertCount(40, array_unique($picked));
        $this->assertSame(['older' => 8, 'recent' => 32], $this->countByPrefix($photos, $picked));
    }

    /** A thin year does not leave the frame half empty. */
    public function test_other_periods_fill_in_where_one_is_short(): void
    {
        $photos = [
            ...$this->photos(5, '2026-08-01', 'recent'),
            ...$this->photos(100, '2024-01-01', 'older'),
        ];

        $picked = (new LookBackRule)->pick($photos, 40, $this->today);

        $this->assertSame(['older' => 35, 'recent' => 5], $this->countByPrefix($photos, $picked));
    }

    /** Pictures without a date or older than five years come last, but they come. */
    public function test_pictures_outside_every_period_are_the_last_resort(): void
    {
        $photos = [
            ...$this->photos(3, null, 'undated'),
            ...$this->photos(3, '2010-01-01', 'ancient'),
        ];

        $picked = (new LookBackRule)->pick($photos, 10, $this->today);

        $this->assertSame(['ancient' => 3, 'undated' => 3], $this->countByPrefix($photos, $picked));
    }

    /** Twelve months back to the day: a year ago today already counts as older. */
    public function test_the_last_year_ends_exactly_one_year_back(): void
    {
        $photos = [new Photo('year-ago', takenOn: '2025-09-30'), new Photo('day-after', takenOn: '2025-10-01')];

        $picked = (new LookBackRule)->pick($photos, 1, $this->today);

        $this->assertSame(['day-after'], array_map(fn (int $position): string => $photos[$position]->id, $picked));
    }

    public function test_the_day_comes_from_the_file_name_when_exif_is_missing(): void
    {
        $this->assertSame('2024-05-12', TakenOn::fromName('IMG_20240512_183000.jpg'));
        $this->assertSame('2023-12-24', TakenOn::fromName('2023-12-24 18.03.11.jpg'));
        $this->assertSame('2022-07-01', TakenOn::fromName('PXL_20220701_090000123.jpg'));
        $this->assertNull(TakenOn::fromName('IMG_1234.jpg'));
        $this->assertNull(TakenOn::fromName('20241399.jpg'), 'No 99th of the 13th month.');
    }

    /** A download carries the day of the download — that is no date to go by. */
    public function test_the_modification_time_is_never_taken(): void
    {
        $file = sys_get_temp_dir().'/fahoba-'.bin2hex(random_bytes(4)).'.jpg';
        $this->writeJpeg($file);

        $this->assertNull(TakenOn::read($file));

        unlink($file);
    }

    public function test_a_folder_remembers_its_dates_and_forgets_vanished_pictures(): void
    {
        [$root, $folder] = $this->folder();
        $this->writeJpeg($folder.'/IMG_20260301_120000.jpg');
        $this->writeJpeg($folder.'/strand.jpg');

        $account = $this->folderAccount();
        $source = app(FolderPhotoSource::class);

        $dates = collect($source->all($account))->mapWithKeys(fn (Photo $photo): array => [$photo->id => $photo->takenOn]);

        $this->assertEqualsCanonicalizing(['2026-03-01', null], $dates->values()->all());
        $this->assertSame(2, PhotoDate::query()->count(), 'Undated pictures are remembered too, so they are not read again.');

        unlink($folder.'/strand.jpg');
        Cache::flush();
        $source->all($account);

        $this->assertSame(['IMG_20260301_120000.jpg'], PhotoDate::query()->pluck('path')->all());

        unlink($folder.'/IMG_20260301_120000.jpg');
        rmdir($folder);
        rmdir($root);
    }

    public function test_the_wall_gets_its_pictures_by_the_households_rule(): void
    {
        PhotoRow::factory()->count(20)->create(['household_id' => $this->household->id, 'taken_on' => '2026-05-01']);
        PhotoRow::factory()->count(20)->create(['household_id' => $this->household->id, 'taken_on' => '2023-05-01']);
        $this->household->update(['photo_rule' => LookBackRule::KEY]);

        $years = collect(app(PhotoLibrary::class)->random(10))->countBy(fn (array $photo): string => substr((string) $photo['taken_on'], 0, 4));

        $this->assertSame(['2023' => 2, '2026' => 8], $years->sortKeys()->all());
    }

    /** A rule whose plugin was removed must not break the frame. */
    public function test_an_unknown_rule_falls_back_to_evenly(): void
    {
        PhotoRow::factory()->count(3)->create(['household_id' => $this->household->id]);
        $this->household->update(['photo_rule' => 'acme/gone']);

        $this->assertCount(3, app(PhotoLibrary::class)->random(10));
    }

    public function test_plugins_bring_their_own_rules(): void
    {
        app(Catalog::class)->offer('photo_rules', 'acme/recent', RecentYearsRule::class);

        $rules = app(PhotoRules::class);

        $this->assertSame([LookBackRule::KEY, 'acme/recent'], array_keys($rules->all()));
        $this->assertSame('Letzte drei Jahre', $rules->find('acme/recent')?->label());

        app(Catalog::class)->offer('photo_rules', 'acme/broken', stdClass::class);
        $this->assertArrayNotHasKey('acme/broken', app(PhotoRules::class)->all());
        $this->assertTrue(app(LoadReport::class)->failed(PluginCatalog::unowned('acme/broken')));
    }

    public function test_parents_choose_the_rule_in_the_household_settings(): void
    {
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        Livewire::test(EditHousehold::class, ['record' => $this->household->getRouteKey()])
            ->fillForm(['photo_rule' => 'acme/unknown'])
            ->call('save')
            ->assertHasFormErrors(['photo_rule']);

        Livewire::test(EditHousehold::class, ['record' => $this->household->getRouteKey()])
            ->fillForm(['photo_rule' => LookBackRule::KEY])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(LookBackRule::KEY, $this->household->refresh()->photo_rule);
    }

    /** @return array{0: string, 1: string} */
    private function folder(): array
    {
        $root = sys_get_temp_dir().'/fahoba-rules-'.bin2hex(random_bytes(4));
        $folder = $root.'/'.$this->household->id;
        mkdir($folder, 0700, true);
        config(['fahoba.photo_folders' => $root]);

        return [$root, $folder];
    }

    private function folderAccount(): ProviderAccount
    {
        return ProviderAccount::factory()->create([
            'household_id' => $this->household->id,
            'area' => ProviderArea::Photos,
            'provider' => 'folder',
            'credentials' => ['path' => ''],
            'status' => ConnectionStatus::Active,
        ]);
    }

    private function writeJpeg(string $path): void
    {
        $canvas = imagecreatetruecolor(4, 3);
        ob_start();
        imagejpeg($canvas);
        file_put_contents($path, (string) ob_get_clean());
    }
}

/** A plugin's rule: the last three years only. */
final class RecentYearsRule extends WeightedRule
{
    public function key(): string
    {
        return 'acme/recent';
    }

    public function label(): string
    {
        return 'Letzte drei Jahre';
    }

    public function description(): string
    {
        return 'Beispiel.';
    }

    protected function periods(): array
    {
        return [[0, 3, 100]];
    }
}

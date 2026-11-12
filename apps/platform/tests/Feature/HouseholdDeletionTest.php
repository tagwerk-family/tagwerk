<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\ManagedHouseholds\Pages\ListManagedHouseholds;
use App\Models\Household;
use App\Models\Person;
use App\Models\Photo;
use App\Models\Recipe;
use App\Models\Task;
use App\Models\TaskCompletion;
use App\Models\User;
use App\Services\HouseholdDeletion;
use App\Services\PhotoImage;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Households\HouseholdDeleting;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * A deleted household leaves nothing behind — rows, files, accounts of its
 * own — and keeps what belongs to someone else.
 */
final class HouseholdDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_rows_files_and_own_accounts_go(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $household = Household::factory()->create();
        $other = Household::factory()->create();

        $person = Person::factory()->create(['household_id' => $household->id, 'avatar_path' => 'avatars/lena.webp']);
        Storage::disk('public')->put('avatars/lena.webp', 'x');

        $photo = Photo::factory()->create(['household_id' => $household->id]);
        Storage::disk('local')->put($photo->path, 'x');
        Storage::disk('local')->put(PhotoImage::thumbnailPath($photo->path), 'x');

        $recipe = Recipe::factory()->create(['household_id' => $household->id, 'photo_path' => 'recipes/a.webp']);
        Storage::disk('local')->put('recipes/a.webp', 'x');

        $task = Task::factory()->create(['household_id' => $household->id]);
        TaskCompletion::factory()->create(['household_id' => $household->id, 'task_id' => $task->id, 'person_id' => $person->id]);

        $own = User::factory()->create(['household_id' => $household->id]);
        $shared = User::factory()->create(['household_id' => $other->id]);
        $shared->memberships()->create(['household_id' => $household->id, 'accepted_at' => now()]);
        $outsider = User::factory()->create(['household_id' => $other->id]);

        app(HouseholdDeletion::class)->delete($household);

        $this->assertModelMissing($household);
        $this->assertModelMissing($recipe);
        $this->assertSame(0, Task::withoutHouseholdScope()->withTrashed()->where('household_id', $household->id)->count());
        $this->assertModelMissing($own);
        $this->assertModelExists($shared);
        $this->assertSame(1, $shared->memberships()->count());
        $this->assertModelExists($outsider);
        $this->assertModelExists($other);

        Storage::disk('public')->assertMissing('avatars/lena.webp');
        Storage::disk('local')->assertMissing($photo->path);
        Storage::disk('local')->assertMissing(PhotoImage::thumbnailPath($photo->path));
        Storage::disk('local')->assertMissing('recipes/a.webp');
    }

    public function test_the_server_photo_folder_goes_but_a_link_is_not_followed(): void
    {
        $root = sys_get_temp_dir().'/tw-folders-'.uniqid();
        $archive = sys_get_temp_dir().'/tw-archive-'.uniqid();
        mkdir($archive);
        file_put_contents($archive.'/keep.jpg', 'x');
        config(['fahoba.photo_folders' => $root]);

        $household = Household::factory()->create();
        $linked = Household::factory()->create();
        mkdir($root.'/'.$household->id.'/Urlaub', 0o777, true);
        file_put_contents($root.'/'.$household->id.'/Urlaub/a.jpg', 'x');
        symlink($archive, $root.'/'.$linked->id);

        app(HouseholdDeletion::class)->delete($household);
        app(HouseholdDeletion::class)->delete($linked);

        $this->assertDirectoryDoesNotExist($root.'/'.$household->id);
        $this->assertFileDoesNotExist($root.'/'.$linked->id);
        $this->assertFileExists($archive.'/keep.jpg');
    }

    public function test_a_listener_that_throws_keeps_the_household(): void
    {
        $household = Household::factory()->create();

        Event::listen(HouseholdDeleting::class, function (HouseholdDeleting $event) use ($household): void {
            $this->assertSame((int) $household->id, $event->householdId);

            throw new RuntimeException('The subscription could not be cancelled.');
        });

        try {
            app(HouseholdDeletion::class)->delete($household);
            $this->fail('The deletion went through.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertModelExists($household);
    }

    public function test_the_operator_deletes_after_typing_the_name(): void
    {
        $household = Household::factory()->create(['name' => 'Familie Huber']);
        $operator = User::factory()->create(['household_id' => Household::factory()->create()->id]);
        $operator->forceFill(['is_operator' => true])->save();

        $this->actingAs($operator);
        Filament::setCurrentPanel('admin');
        app(HouseholdContext::class)->setId((int) $operator->memberships()->sole()->household_id);

        Livewire::test(ListManagedHouseholds::class)
            ->callTableAction('delete', $household, ['name' => 'Familie Maier'])
            ->assertHasTableActionErrors(['name']);
        $this->assertModelExists($household);

        Livewire::test(ListManagedHouseholds::class)
            ->callTableAction('delete', $household, ['name' => 'Familie Huber'])
            ->assertHasNoTableActionErrors();
        $this->assertModelMissing($household);
    }
}

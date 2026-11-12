<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use App\Enums\Permission;
use App\Models\Household;
use App\Models\Person;
use App\Models\Photo;
use App\Models\User;
use App\Services\PhotoImage;
use App\Support\HouseholdContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The phone's photo grid: delete into the trash, restore, purge. */
final class PhotoManageTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->household = Household::factory()->create();
        app(HouseholdContext::class)->setId((int) $this->household->id);
    }

    /** A parent (all permissions) or someone who may only upload. */
    private function account(bool $manager = true): User
    {
        $person = Person::factory()->for($this->household)->create(
            $manager ? [] : ['permissions' => [Permission::PhotosUpload->value]],
        );

        return User::factory()->create(['household_id' => $this->household->id, 'person_id' => $person->id]);
    }

    private function photo(?User $owner, array $extra = []): Photo
    {
        $path = PhotoImage::DIRECTORY.'/'.fake()->uuid().'.webp';
        $canvas = imagecreatetruecolor(800, 600);
        ob_start();
        imagewebp($canvas);
        Storage::disk('local')->put($path, (string) ob_get_clean());

        return Photo::factory()->create([
            'household_id' => $this->household->id,
            'path' => $path,
            'user_id' => $owner?->id,
            ...$extra,
        ]);
    }

    public function test_the_grid_lists_newest_first_and_says_what_may_be_deleted(): void
    {
        $uploader = $this->account(manager: false);
        $other = $this->account();
        $mine = $this->photo($uploader, ['created_at' => now()]);
        $theirs = $this->photo($other, ['created_at' => now()->subDay()]);

        $photos = $this->actingAs($uploader)->getJson('/api/photos')->assertOk()->json('photos');

        $this->assertSame([$mine->id, $theirs->id], array_column($photos, 'id'));
        $this->assertTrue($photos[0]['can_delete']);
        $this->assertTrue($photos[0]['mine']);
        $this->assertFalse($photos[1]['can_delete'], 'Someone else’s upload is not theirs to delete.');

        $photos = $this->actingAs($other)->getJson('/api/photos')->json('photos');
        $this->assertTrue($photos[0]['can_delete'], 'A parent may delete any photo.');
    }

    public function test_deleting_moves_to_the_trash_and_off_the_wall(): void
    {
        $parent = $this->account();
        $photo = $this->photo($parent);

        $this->actingAs($parent)->postJson('/api/photos/delete', ['ids' => [$photo->id]])
            ->assertOk()->assertJson(['deleted' => 1]);

        $this->assertSoftDeleted($photo);
        Storage::disk('local')->assertExists($photo->path);
        $this->assertSame([], $this->getJson('/api/photos')->json('photos'));
        $this->assertSame([], $this->getJson('/api/wall/photos')->json('photos'), 'The wall stops showing it at once.');

        $trash = $this->getJson('/api/photos/trash')->assertOk()->json('photos');
        $this->assertSame([$photo->id], array_column($trash, 'id'));
        $this->assertSame(Photo::TRASH_DAYS, $trash[0]['days_left']);
    }

    public function test_a_restored_photo_is_back(): void
    {
        $parent = $this->account();
        $photo = $this->photo($parent, ['deleted_at' => now()->subDays(3)]);

        $this->actingAs($parent)->getJson('/api/photos/trash')->assertJsonPath('photos.0.days_left', 27);

        $this->postJson('/api/photos/restore', ['ids' => [$photo->id]])->assertOk()->assertJson(['restored' => 1]);

        $this->assertNotSoftDeleted($photo);
        $this->assertSame([$photo->id], array_column($this->getJson('/api/photos')->json('photos'), 'id'));
    }

    public function test_purging_removes_file_and_row_but_only_from_the_trash(): void
    {
        $parent = $this->account();
        $kept = $this->photo($parent);
        $trashed = $this->photo($parent, ['deleted_at' => now()]);
        // The trash shows it, so its small copy exists and must go too.
        $this->actingAs($parent)->get("/api/photos/{$trashed->id}/image")->assertOk();

        $this->postJson('/api/photos/purge', ['ids' => [$kept->id, $trashed->id]])
            ->assertOk()->assertJson(['purged' => 1]);

        $this->assertModelMissing($trashed);
        $this->assertModelExists($kept);
        Storage::disk('local')->assertMissing($trashed->path);
        Storage::disk('local')->assertMissing(PhotoImage::thumbnailPath($trashed->path));
        Storage::disk('local')->assertExists($kept->path);
    }

    public function test_one_foreign_photo_refuses_the_whole_request(): void
    {
        $uploader = $this->account(manager: false);
        $mine = $this->photo($uploader);
        $theirs = $this->photo($this->account());

        $this->actingAs($uploader)->postJson('/api/photos/delete', ['ids' => [$mine->id, $theirs->id]])
            ->assertForbidden();

        $this->assertNotSoftDeleted($mine);
        $this->assertNotSoftDeleted($theirs);
    }

    public function test_the_trash_shows_an_uploader_only_their_own(): void
    {
        $uploader = $this->account(manager: false);
        $mine = $this->photo($uploader, ['deleted_at' => now()]);
        $this->photo($this->account(), ['deleted_at' => now()]);

        $trash = $this->actingAs($uploader)->getJson('/api/photos/trash')->json('photos');

        $this->assertSame([$mine->id], array_column($trash, 'id'));
    }

    public function test_without_upload_permission_nothing_is_managed(): void
    {
        $child = Person::factory()->child()->for($this->household)->create();
        $user = User::factory()->create(['household_id' => $this->household->id, 'person_id' => $child->id]);
        $photo = $this->photo(null);

        $this->actingAs($user)->getJson('/api/photos')->assertForbidden();
        $this->postJson('/api/photos/delete', ['ids' => [$photo->id]])->assertForbidden();
    }

    public function test_another_household_is_out_of_reach(): void
    {
        $parent = $this->account();
        $foreign = Household::factory()->create();
        $photo = Photo::factory()->create(['household_id' => $foreign->id]);

        $this->actingAs($parent)->postJson('/api/photos/delete', ['ids' => [$photo->id]])
            ->assertOk()->assertJson(['deleted' => 0]);
        $this->get("/api/photos/{$photo->id}/image")->assertNotFound();

        $this->assertNotSoftDeleted($photo);
    }

    public function test_the_grid_gets_a_small_copy_and_the_large_view_the_full_picture(): void
    {
        $parent = $this->account();
        $photo = $this->photo($parent);

        $this->actingAs($parent)->get("/api/photos/{$photo->id}/image")->assertOk();
        Storage::disk('local')->assertExists(PhotoImage::thumbnailPath($photo->path));
        [$width] = getimagesizefromstring((string) Storage::disk('local')->get(PhotoImage::thumbnailPath($photo->path))) ?: [0];
        $this->assertSame(PhotoImage::THUMB_EDGE, $width);

        $this->get("/api/photos/{$photo->id}/image?full=1")->assertOk();
    }

    public function test_a_trashed_original_is_known_and_not_uploaded_again(): void
    {
        $parent = $this->account();
        $fingerprint = str_repeat('d', 64);
        $this->photo($parent, ['fingerprint' => $fingerprint, 'deleted_at' => now()]);

        $this->actingAs($parent)->postJson('/api/photos/known', ['fingerprints' => [$fingerprint]])
            ->assertExactJson(['known' => [$fingerprint]]);
    }

    public function test_the_nightly_run_empties_the_trash_after_thirty_days(): void
    {
        $parent = $this->account();
        $old = $this->photo($parent, ['deleted_at' => now()->subDays(Photo::TRASH_DAYS + 1)]);
        $recent = $this->photo($parent, ['deleted_at' => now()->subDays(Photo::TRASH_DAYS - 1)]);
        $active = $this->photo($parent);

        // No household context: the run covers every household.
        app(HouseholdContext::class)->setId(null);

        $this->artisan('fahoba:purge-photo-trash')->assertSuccessful();

        $this->assertDatabaseMissing('photos', ['id' => $old->id]);
        $this->assertDatabaseHas('photos', ['id' => $recent->id]);
        $this->assertDatabaseHas('photos', ['id' => $active->id]);
        Storage::disk('local')->assertMissing($old->path);
        Storage::disk('local')->assertExists($recent->path);
    }
}

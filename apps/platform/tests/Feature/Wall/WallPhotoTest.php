<?php

declare(strict_types=1);

namespace Tests\Feature\Wall;

use App\Enums\ProviderArea;
use App\Integrations\Photos\Folder\FolderPhotoSource;
use App\Models\Household;
use App\Models\Photo;
use App\Models\ProviderAccount;
use App\Services\DevicePairingService;
use App\Services\PhotoImage;
use App\Support\HouseholdContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class WallPhotoTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private string $token;

    /** The operator's photo directory, one folder per household. */
    private string $root;

    /** This household's folder inside it. */
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();

        app(HouseholdContext::class)->setId((int) $this->household->id);

        $device = app(DevicePairingService::class)->createCode($this->household, 'Küche');
        $this->token = app(DevicePairingService::class)->redeem($device->pairing_code)['token'];

        $this->root = sys_get_temp_dir().'/fahoba-photos-'.bin2hex(random_bytes(4));
        $this->folder = $this->root.'/'.$this->household->id;
        mkdir($this->folder.'/Urlaub', 0700, true);
        config(['fahoba.photo_folders' => $this->root]);
    }

    protected function tearDown(): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($this->root);

        parent::tearDown();
    }

    /** A tiny, real image — getimagesize must recognise it. */
    private function image(): string
    {
        $canvas = imagecreatetruecolor(4, 3);

        ob_start();
        imagejpeg($canvas);

        return (string) ob_get_clean();
    }

    private function folderSource(string $path): ProviderAccount
    {
        return ProviderAccount::factory()->create([
            'household_id' => $this->household->id,
            'area' => ProviderArea::Photos,
            'provider' => 'folder',
            'account_label' => 'Fotos',
            'credentials' => ['path' => $path],
        ]);
    }

    public function test_no_access_without_token(): void
    {
        $this->getJson('/api/wall/photos')->assertUnauthorized();
    }

    public function test_without_configured_source_the_batch_stays_empty(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/wall/photos')
            ->assertOk()
            ->assertJsonCount(0, 'photos');
    }

    public function test_uploaded_photos_come_with_url(): void
    {
        Storage::fake('local');

        $path = app(PhotoImage::class)->store($this->image());

        $photo = Photo::factory()->create([
            'household_id' => $this->household->id,
            'path' => $path,
            'caption' => 'Am See',
        ]);

        $this->withToken($this->token)
            ->getJson('/api/wall/photos')
            ->assertOk()
            ->assertJsonCount(1, 'photos')
            ->assertJsonPath('photos.0.source', 'builtin')
            ->assertJsonPath('photos.0.caption', 'Am See')
            ->assertJsonPath('photos.0.url', "/api/wall/photos/builtin/{$photo->id}");
    }

    public function test_a_photo_of_a_foreign_household_is_not_served(): void
    {
        Storage::fake('local');

        $foreign = Household::factory()->create();
        $photo = Photo::factory()->create([
            'household_id' => $foreign->id,
            'path' => app(PhotoImage::class)->store($this->image()),
        ]);

        $this->withToken($this->token)
            ->get("/api/wall/photos/builtin/{$photo->id}")
            ->assertNotFound();
    }

    public function test_the_folder_is_read_recursively(): void
    {
        file_put_contents($this->folder.'/strand.jpg', $this->image());
        file_put_contents($this->folder.'/Urlaub/berg.jpg', $this->image());
        file_put_contents($this->folder.'/notiz.txt', 'kein Bild');

        $this->folderSource('');

        $this->withToken($this->token)
            ->getJson('/api/wall/photos')
            ->assertOk()
            ->assertJsonCount(2, 'photos')
            ->assertJsonPath('photos.0.source', 'folder');
    }

    public function test_an_image_from_the_folder_comes_as_file(): void
    {
        file_put_contents($this->folder.'/strand.jpg', $this->image());

        $this->folderSource('');

        $response = $this->withToken($this->token)->getJson('/api/wall/photos');
        $url = (string) $response->json('photos.0.url');

        $this->withToken($this->token)
            ->get($url)
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
    }

    /**
     * The most important test of this stage.
     *
     * A source that serves arbitrary server paths would be a hole — and the
     * identifier comes from the device, so it is freely made up.
     */
    public function test_a_path_outside_the_folder_is_rejected(): void
    {
        file_put_contents($this->folder.'/strand.jpg', $this->image());

        $this->folderSource('Urlaub');

        $escape = rtrim(strtr(base64_encode('../strand.jpg'), '+/', '-_'), '=');

        $this->withToken($this->token)
            ->get("/api/wall/photos/folder/{$escape}")
            ->assertNotFound();
    }

    /** Neither `..` nor an absolute path leads out of the household's own folder. */
    public function test_the_subfolder_cannot_leave_the_household_folder(): void
    {
        $neighbour = $this->root.'/'.($this->household->id + 1);
        mkdir($neighbour, 0700, true);
        file_put_contents($neighbour.'/fremd.jpg', $this->image());

        foreach (['../'.($this->household->id + 1), $neighbour] as $path) {
            ProviderAccount::query()->delete();
            $this->folderSource($path);

            $this->withToken($this->token)
                ->getJson('/api/wall/photos')
                ->assertOk()
                ->assertJsonCount(0, 'photos');
        }
    }

    public function test_connecting_needs_the_household_folder_on_the_server(): void
    {
        $source = app(FolderPhotoSource::class);

        $this->assertNull($source->verifyCredentials(['path' => 'Urlaub']));
        $this->assertNotNull($source->verifyCredentials(['path' => '../../etc']));

        config(['fahoba.photo_folders' => null]);
        $this->assertNotNull($source->verifyCredentials(['path' => '']));
    }

    public function test_a_folder_that_does_not_exist_is_no_source(): void
    {
        $this->folderSource('missing');

        $this->withToken($this->token)
            ->getJson('/api/wall/photos')
            ->assertOk()
            ->assertJsonCount(0, 'photos');
    }

    public function test_disabled_area_does_not_know_the_frame(): void
    {
        $this->household->update(['disabled_features' => ['photos']]);

        $this->withToken($this->token)
            ->getJson('/api/wall/photos')
            ->assertNotFound();
    }
}

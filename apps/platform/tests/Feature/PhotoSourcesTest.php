<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConnectionStatus;
use App\Enums\ProviderArea;
use App\Integrations\Photos\Amazon\AmazonPhotoSource;
use App\Integrations\Photos\Builtin\BuiltinPhotoSource;
use App\Integrations\Photos\Folder\FolderPhotoSource;
use App\Integrations\ProviderRegistry;
use App\Models\Household;
use App\Models\ProviderAccount;
use App\Services\Photos\PhotoLibrary;
use App\Support\HouseholdContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The choice of photo sources.
 *
 * Amazon is a separate entry in the list even though the same folder route
 * sits behind it — exactly as intended: people search for „Amazon Photos",
 * not „Ordner am Server".
 */
final class PhotoSourcesTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();

        app(HouseholdContext::class)->setId((int) $this->household->id);
    }

    public function test_three_sources_are_available(): void
    {
        $sources = app(ProviderRegistry::class)->photoSources();

        $this->assertSame(['builtin', 'folder', 'amazon'], array_keys($sources));
        $this->assertInstanceOf(BuiltinPhotoSource::class, $sources['builtin']);
        $this->assertInstanceOf(AmazonPhotoSource::class, $sources['amazon']);
        $this->assertSame('Amazon Photos', $sources['amazon']->label());
    }

    public function test_amazon_is_connectable_and_brings_instructions(): void
    {
        $connectable = app(ProviderRegistry::class)->connectableProviders(ProviderArea::Photos);

        $this->assertArrayHasKey('amazon', $connectable);
        $this->assertArrayNotHasKey('builtin', $connectable, 'The builtin frame is never connected.');

        $amazon = $connectable['amazon'];

        $this->assertInstanceOf(FolderPhotoSource::class, $amazon);
        $this->assertSame('path', $amazon->credentialFields()[0]->name);
        $this->assertNotEmpty($amazon->setupNotes());
    }

    public function test_a_missing_folder_is_rejected_when_connecting(): void
    {
        $this->assertNotNull(app(FolderPhotoSource::class)->verifyCredentials(['path' => '/does/not/exist']));
    }

    public function test_a_disconnected_source_returns_nothing(): void
    {
        $root = sys_get_temp_dir().'/fahoba-sources-'.bin2hex(random_bytes(4));
        $folder = $root.'/'.$this->household->id;
        mkdir($folder, 0700, true);
        config(['fahoba.photo_folders' => $root]);

        $canvas = imagecreatetruecolor(4, 3);
        ob_start();
        imagejpeg($canvas);
        file_put_contents($folder.'/strand.jpg', (string) ob_get_clean());

        $account = ProviderAccount::factory()->create([
            'household_id' => $this->household->id,
            'area' => ProviderArea::Photos,
            'provider' => 'folder',
            'credentials' => ['path' => ''],
            'status' => ConnectionStatus::Active,
        ]);

        $this->assertArrayHasKey('folder', app(PhotoLibrary::class)->sources());

        $account->update(['status' => ConnectionStatus::InvalidGrant]);

        $this->assertArrayNotHasKey('folder', app(PhotoLibrary::class)->sources());

        unlink($folder.'/strand.jpg');
        rmdir($folder);
        rmdir($root);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\FrontendPackage;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A frontend package is checked before nginx serves it: a page the
 * Content Security Policy would leave blank stops the start instead.
 */
final class FrontendPackageTest extends TestCase
{
    private string $package;

    protected function setUp(): void
    {
        parent::setUp();

        $this->package = sys_get_temp_dir().'/fahoba-frontend-'.uniqid();
        File::copyDirectory(base_path('tests/Fixtures/Frontend'), $this->package);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->package);

        parent::tearDown();
    }

    public function test_a_built_app_is_served_from_its_directory(): void
    {
        $this->assertSame(realpath($this->package.'/dist'), FrontendPackage::check('acme/frontend', $this->package));
    }

    /** @return array<string, array{string}> */
    public static function blankPages(): array
    {
        return [
            'an inline script' => ['<script>alert(1)</script>'],
            'a foreign script' => ['<script src="https://cdn.example.org/app.js"></script>'],
            'a protocol-relative script' => ['<script src="//cdn.example.org/app.js"></script>'],
            'an import map' => ['<script type="importmap">{"imports":{}}</script>'],
        ];
    }

    #[DataProvider('blankPages')]
    public function test_a_page_the_policy_would_block_is_refused(string $script): void
    {
        file_put_contents($this->package.'/dist/index.html', "<!doctype html><html><head>{$script}</head></html>");

        $this->assertStringContainsString('index.html', FrontendPackage::check('acme/frontend', $this->package));
    }

    public function test_json_data_in_the_page_is_fine(): void
    {
        file_put_contents($this->package.'/dist/index.html', '<script type="application/json" id="data">{"a":1}</script><script type="module" src="/assets/x.js"></script>');

        $this->assertStringStartsWith('/', FrontendPackage::check('acme/frontend', $this->package));
    }

    public function test_the_app_must_be_named_and_inside_the_package(): void
    {
        file_put_contents($this->package.'/composer.json', '{"name": "acme/frontend"}');
        $this->assertStringContainsString('extra.fahoba.app', FrontendPackage::check('acme/frontend', $this->package));

        file_put_contents($this->package.'/composer.json', '{"extra": {"fahoba": {"app": "../.."}}}');
        $this->assertStringContainsString('does not exist inside the package', FrontendPackage::check('acme/frontend', $this->package));

        file_put_contents($this->package.'/composer.json', '{"extra": {"fahoba": {"app": "dist"}}}');
        unlink($this->package.'/dist/index.html');
        $this->assertStringContainsString('does not exist', FrontendPackage::check('acme/frontend', $this->package));
    }

    public function test_the_command_fails_for_a_package_that_is_not_installed(): void
    {
        $this->artisan('fahoba:frontend', ['package' => 'acme/nowhere'])
            ->expectsOutputToContain('not installed')
            ->assertFailed();
    }
}

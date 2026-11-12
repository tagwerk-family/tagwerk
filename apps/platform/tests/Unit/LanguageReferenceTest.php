<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\LanguageReference;
use Fahoba\PluginApi\Languages\Reference;
use Tests\TestCase;

/**
 * The reference shipped with `fahoba/plugin-api` is what language packs
 * are measured against — on the server and in a translator's own tests.
 * Stale, a new text would never show up as missing.
 */
final class LanguageReferenceTest extends TestCase
{
    public function test_the_shipped_reference_matches_the_core_texts(): void
    {
        foreach (LanguageReference::build() as $file => $content) {
            $path = Reference::path()."/{$file}";

            $this->assertFileExists($path, "{$file} is missing: run php artisan fahoba:language-reference");
            $this->assertSame(
                $content,
                (string) file_get_contents($path),
                "{$file} is stale: run php artisan fahoba:language-reference",
            );
        }
    }
}

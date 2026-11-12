<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DesignReference;
use Fahoba\PluginApi\Themes\Tokens;
use Tests\TestCase;

/**
 * The token list shipped with `fahoba/plugin-api` is what designs are
 * checked against — on the server and in a designer's own tests. Stale, a
 * new token would be refused and a removed one accepted.
 */
final class DesignReferenceTest extends TestCase
{
    public function test_the_shipped_tokens_match_the_core_stylesheet(): void
    {
        $this->assertFileExists(Tokens::path(), 'Run php artisan fahoba:design-reference');
        $this->assertSame(DesignReference::build(), (string) file_get_contents(Tokens::path()), 'Stale: run php artisan fahoba:design-reference');
    }

    public function test_the_tokens_themes_rely_on_are_there(): void
    {
        foreach (['--canvas', '--surface', '--ink', '--accent', '--font-body', '--corner-2xl', '--backdrop'] as $token) {
            $this->assertArrayHasKey($token, Tokens::load());
        }
    }
}

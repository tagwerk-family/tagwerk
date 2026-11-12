<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\ApiContract;
use Tests\TestCase;

/**
 * Community frontends call the API without anyone here seeing them. A
 * route that disappears breaks them silently — so the list of routes is a
 * file, and only a deliberate change of it lets a route go.
 */
final class ApiContractTest extends TestCase
{
    public function test_no_route_frontends_rely_on_went_missing(): void
    {
        $promised = array_values(array_filter(
            explode("\n", (string) file_get_contents(ApiContract::path())),
            static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
        ));
        $current = ApiContract::routes();

        $this->assertSame(
            [],
            array_values(array_diff($promised, $current)),
            'These routes are gone or renamed. Frontends rely on them: keep them until a major release, then run php artisan fahoba:api-contract.',
        );
        $this->assertSame(
            [],
            array_values(array_diff($current, $promised)),
            'New routes: add them to the contract with php artisan fahoba:api-contract (docs/frontends.md).',
        );
    }

    public function test_plugin_routes_are_not_part_of_it(): void
    {
        $this->assertNotContains('GET /api/fahoba/example-area/greeting', ApiContract::routes());
        $this->assertContains('GET /api/wall/agenda', ApiContract::routes());
        $this->assertContains('POST /broadcasting/auth', ApiContract::routes());
    }
}

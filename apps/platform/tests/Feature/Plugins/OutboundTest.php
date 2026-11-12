<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Support\PublicAddressResolver;
use Fahoba\PluginApi\Http\Outbound;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeResolver;
use Tests\TestCase;

/** Outbound calls a plugin makes: limits, a polite name, retries, no private addresses. */
final class OutboundTest extends TestCase
{
    public function test_the_server_client_names_fahoba(): void
    {
        config(['app.version' => '1.2.3', 'app.url' => 'https://fahoba.example']);
        Http::fake(['*' => Http::response('ok')]);

        app(Outbound::class)->server()->get('https://api.example.org/x');

        Http::assertSent(fn (Request $request): bool => $request->header('User-Agent')[0] === 'Fahoba/1.2.3 (+https://fahoba.example)');
    }

    public function test_a_busy_service_is_asked_again(): void
    {
        Http::fakeSequence('api.example.org/*')
            ->push('busy', 503)
            ->push('slow down', 429)
            ->push('ok', 200);

        $response = app(Outbound::class)->server()->get('https://api.example.org/x');

        $this->assertSame(200, $response->status());
        Http::assertSentCount(3);
    }

    public function test_a_missing_page_is_not_asked_again(): void
    {
        Http::fake(['*' => Http::response('gone', 404)]);

        $response = app(Outbound::class)->server()->get('https://api.example.org/x');

        $this->assertSame(404, $response->status());
        Http::assertSentCount(1);
    }

    /** A 304 to an ETag request is an answer, not a failure. */
    public function test_a_redirect_or_not_modified_comes_back_as_it_is(): void
    {
        Http::fake(['*' => Http::response('', 304)]);

        $this->assertSame(304, app(Outbound::class)->server()->get('https://api.example.org/x')->status());
        Http::assertSentCount(1);
    }

    public function test_an_address_a_household_typed_in_never_reaches_the_private_network(): void
    {
        $this->app->instance(PublicAddressResolver::class, new FakeResolver(['intern.example' => ['10.0.0.5'], 'public.example' => ['93.184.216.34']]));
        Http::fake(['*' => Http::response('ok')]);

        $this->assertNull(app(Outbound::class)->householdGet('http://intern.example/'));
        $this->assertNull(app(Outbound::class)->householdSend('POST', 'http://127.0.0.1/'));
        $this->assertSame('ok', app(Outbound::class)->householdGet('https://public.example/')?->body());
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->header('User-Agent')[0], 'Fahoba/'));
    }
}

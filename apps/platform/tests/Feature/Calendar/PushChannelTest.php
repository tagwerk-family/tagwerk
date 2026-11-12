<?php

declare(strict_types=1);

namespace Tests\Feature\Calendar;

use App\Models\Calendar;
use App\Models\Household;
use App\Models\ProviderAccount;
use App\Services\Calendar\PushChannelService;
use App\Support\HouseholdContext;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Mandatory path 3: channel renewal before expiry, including overlap.
 */
final class PushChannelTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private ProviderAccount $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        app(HouseholdContext::class)->setId((int) $this->household->id);

        $this->source = ProviderAccount::factory()->create([
            'household_id' => $this->household->id,
        ]);

        config([
            'services.google.webhook_url' => 'https://fahoba.test/api/calendar/google/webhook',
            'services.google.webhook_token' => 'channel-secret',
        ]);
    }

    private function calendar(array $attributes = []): Calendar
    {
        return Calendar::factory()->create([
            'household_id' => $this->household->id,
            'provider_account_id' => $this->source->id,
            ...$attributes,
        ]);
    }

    private function watchResponse(string $resourceId = 'resource-new'): PromiseInterface
    {
        return Http::response([
            'kind' => 'api#channel',
            'resourceId' => $resourceId,
            'expiration' => (string) (now()->addDays(7)->getTimestamp() * 1000),
        ]);
    }

    public function test_channel_is_created_when_none_exists(): void
    {
        $calendar = $this->calendar();

        Http::fake(['www.googleapis.com/*' => $this->watchResponse()]);

        $created = app(PushChannelService::class)->ensure($calendar);

        $this->assertTrue($created);

        $fresh = $calendar->fresh();
        $this->assertNotNull($fresh->push_channel_id);
        $this->assertSame('resource-new', $fresh->push_resource_id);
        $this->assertTrue($fresh->push_expires_at->isFuture());

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/events/watch')
            && $request['address'] === 'https://fahoba.test/api/calendar/google/webhook'
            && $request['token'] === 'channel-secret');
    }

    public function test_fresh_channel_is_not_touched(): void
    {
        $calendar = $this->calendar([
            'push_channel_id' => 'channel-old',
            'push_resource_id' => 'resource-old',
            'push_expires_at' => now()->addDays(6),
        ]);

        Http::fake();

        $this->assertFalse(app(PushChannelService::class)->ensure($calendar));
        $this->assertSame('channel-old', $calendar->fresh()->push_channel_id);

        Http::assertNothingSent();
    }

    public function test_expiring_channel_is_replaced_and_the_old_one_stopped(): void
    {
        $calendar = $this->calendar([
            'push_channel_id' => 'channel-old',
            'push_resource_id' => 'resource-old',
            'push_expires_at' => now()->addHours(6),
        ]);

        Http::fake([
            'www.googleapis.com/calendar/v3/channels/stop' => Http::response([], 204),
            'www.googleapis.com/*' => $this->watchResponse(),
        ]);

        $this->assertTrue(app(PushChannelService::class)->ensure($calendar));

        $fresh = $calendar->fresh();
        $this->assertNotSame('channel-old', $fresh->push_channel_id);
        $this->assertSame('resource-new', $fresh->push_resource_id);

        // The new channel is created before the old one is stopped: an
        // overlap is harmless, a gap would not be.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/channels/stop')
            && $request['id'] === 'channel-old'
            && $request['resourceId'] === 'resource-old');
    }

    public function test_failure_to_stop_has_no_consequences(): void
    {
        $calendar = $this->calendar([
            'push_channel_id' => 'channel-old',
            'push_resource_id' => 'resource-old',
            'push_expires_at' => now()->addHours(6),
        ]);

        Http::fake([
            'www.googleapis.com/calendar/v3/channels/stop' => Http::response(['error' => 'not found'], 404),
            'www.googleapis.com/*' => $this->watchResponse(),
        ]);

        // An expired channel can no longer be stopped — the new one is in
        // place regardless.
        $this->assertTrue(app(PushChannelService::class)->ensure($calendar));
        $this->assertSame('resource-new', $calendar->fresh()->push_resource_id);
    }
}

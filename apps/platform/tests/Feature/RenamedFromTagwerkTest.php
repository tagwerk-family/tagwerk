<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\DeviceTokenFromCookie;
use App\Models\Calendar;
use App\Models\Household;
use App\Models\ProviderAccount;
use App\Services\Calendar\BuiltinCalendar;
use App\Services\DevicePairingService;
use App\Support\HouseholdContext;
use App\Support\RenamedFromTagwerk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * What a server, a tablet or an export kept from before the rename to Fahoba
 * must keep working: the device cookie, the bundled plugins' keys and the
 * built-in calendar's id.
 */
final class RenamedFromTagwerkTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create(['timezone' => 'Europe/Vienna']);
        app(HouseholdContext::class)->setId((int) $this->household->id);
    }

    public function test_a_paired_tablet_keeps_its_sign_in_under_the_new_cookie(): void
    {
        $device = app(DevicePairingService::class)->createCode($this->household, 'Küche');
        $token = (string) $this->cookie($this->postJson('/api/devices/pair', ['code' => $device->pairing_code]), DeviceTokenFromCookie::COOKIE)?->getValue();

        $response = $this->withCredentials()->withUnencryptedCookie('tagwerk_device_token', $token)
            ->getJson('/api/devices/me')
            ->assertOk();

        $this->assertSame($token, $this->cookie($response, DeviceTokenFromCookie::COOKIE)?->getValue());
        $this->assertTrue($this->cookie($response, DeviceTokenFromCookie::COOKIE)?->isHttpOnly());
        $this->assertTrue($this->cookie($response, 'tagwerk_device_token')?->isCleared());
    }

    public function test_a_dead_old_cookie_is_cleared_not_carried_over(): void
    {
        $response = $this->withCredentials()->withUnencryptedCookie('tagwerk_device_token', 'made-up')
            ->getJson('/api/devices/me')
            ->assertUnauthorized();

        $this->assertTrue($this->cookie($response, DeviceTokenFromCookie::COOKIE)?->isCleared());
        $this->assertTrue($this->cookie($response, 'tagwerk_device_token')?->isCleared());
    }

    public function test_the_migration_renames_stored_keys_and_keeps_one_family_calendar(): void
    {
        $calendar = app(BuiltinCalendar::class)->ensureFor($this->household);
        $calendar->forceFill(['external_id' => 'tagwerk'])->save();
        ProviderAccount::withoutHouseholdScope()->whereKey($calendar->provider_account_id)->update(['account_label' => 'Tagwerk']);

        $bring = ProviderAccount::factory()->create([
            'household_id' => $this->household->id,
            'area' => 'lists',
            'provider' => 'tagwerk/bring',
        ]);
        DB::table('households')->where('id', $this->household->id)->update([
            'design' => 'tagwerk/example-theme',
            'opted_in_features' => json_encode(['tagwerk/webhooks', 'api']),
        ]);

        (require database_path('migrations/2026_11_10_100000_rename_tagwerk_keys_to_fahoba.php'))->up();

        $this->assertSame('fahoba/bring', $bring->fresh()->provider);
        $household = DB::table('households')->find($this->household->id);
        $this->assertSame('fahoba/example-theme', $household->design);
        $this->assertSame(['fahoba/webhooks', 'api'], json_decode($household->opted_in_features, true));

        app(BuiltinCalendar::class)->ensureFor($this->household);
        $this->assertSame(1, Calendar::withoutHouseholdScope()->where('household_id', $this->household->id)->count());
        $this->assertSame('Fahoba', ProviderAccount::withoutHouseholdScope()->find($calendar->provider_account_id)?->account_label);
    }

    public function test_rows_of_an_older_export_are_renamed(): void
    {
        $this->assertSame(
            ['provider' => 'fahoba/kochwiki', 'preferences' => '{"design":"fahoba/example-theme"}'],
            RenamedFromTagwerk::row('recipe_favorites', ['provider' => 'tagwerk/kochwiki', 'preferences' => '{"design":"tagwerk/example-theme"}']),
        );
        $this->assertSame('["fahoba\\/webhooks"]', RenamedFromTagwerk::row('households', ['opted_in_features' => '["tagwerk\\/webhooks"]'])['opted_in_features']);
        $this->assertSame('fahoba', RenamedFromTagwerk::row('calendars', ['external_id' => 'tagwerk'])['external_id']);
    }

    private function cookie(TestResponse $response, string $name): ?Cookie
    {
        foreach ($response->baseResponse->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }
}

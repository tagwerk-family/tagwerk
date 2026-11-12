<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\DeviceTokenFromCookie;
use App\Http\Middleware\UserTokenFromCookie;
use App\Models\Device;
use App\Models\Household;
use App\Models\User;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Plugins\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Fixtures\Languages\Italian;
use Tests\TestCase;

/**
 * The one question the app asks before its first text: which language,
 * and where its texts are. The cookies are what the browser sends — a test
 * without `withCredentials()` would pass without any arriving.
 */
final class LanguageEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        app(Catalog::class)->during('acme/italian', static fn () => app(Catalog::class)->offer('languages', 'acme/italian', Italian::class));
        $this->household = Household::factory()->create(['locale' => 'it']);
        app(HouseholdContext::class)->setId((int) $this->household->id);
    }

    public function test_a_device_gets_its_households_pack(): void
    {
        $device = Device::factory()->create(['household_id' => $this->household->id]);
        $token = $device->createToken('wall')->plainTextToken;
        app(HouseholdContext::class)->setId(null);

        $response = $this->withCredentials()->withUnencryptedCookie(DeviceTokenFromCookie::COOKIE, $token)
            ->getJson('/api/language')
            ->assertOk()
            ->assertJsonPath('code', 'it')
            ->assertJsonPath('intl', 'it-IT');

        $this->assertMatchesRegularExpression('#^/api/language/it/[0-9a-f]{12}\.json$#', (string) $response->json('messages'));
    }

    public function test_an_account_follows_its_household_and_its_own_choice(): void
    {
        $user = User::factory()->create(['household_id' => $this->household->id]);
        $token = $user->createToken('app')->plainTextToken;
        app(HouseholdContext::class)->setId(null);

        $this->withCredentials()->withUnencryptedCookie(UserTokenFromCookie::COOKIE, $token)
            ->getJson('/api/language')
            ->assertJsonPath('code', 'it');

        $user->update(['locale' => 'en']);
        Auth::forgetGuards();

        $this->withCredentials()->withUnencryptedCookie(UserTokenFromCookie::COOKIE, $token)
            ->getJson('/api/language')
            ->assertJsonPath('code', 'en')
            ->assertJsonPath('intl', 'en-US')
            ->assertJsonPath('messages', null);
    }

    public function test_nobody_gets_the_best_installed_browser_language(): void
    {
        $this->getJson('/api/language', ['Accept-Language' => 'it-CH,it;q=0.9,en;q=0.5'])->assertJsonPath('code', 'it');
        $this->getJson('/api/language', ['Accept-Language' => 'en-GB,en;q=0.9'])->assertJsonPath('code', 'en');
        $this->getJson('/api/language', ['Accept-Language' => 'ja,ko;q=0.8'])->assertJsonPath('code', config('app.locale'));
    }

    public function test_the_texts_are_cached_forever_under_their_hash(): void
    {
        $url = (string) $this->getJson('/api/language', ['Accept-Language' => 'it'])->json('messages');

        $response = $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('tasks.nothing', 'Niente da fare.');

        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));

        $this->getJson('/api/language/it/000000000000.json')->assertNotFound();
        $this->getJson('/api/language/es/000000000000.json')->assertNotFound();
    }

    public function test_the_shell_answers_carry_the_language(): void
    {
        $user = User::factory()->create(['household_id' => $this->household->id]);

        $this->withToken($user->createToken('app')->plainTextToken)
            ->getJson('/api/auth/me')
            ->assertJsonPath('locale', 'it')
            ->assertJsonPath('language.code', 'it')
            ->assertJsonPath('language.intl', 'it-IT');
    }
}

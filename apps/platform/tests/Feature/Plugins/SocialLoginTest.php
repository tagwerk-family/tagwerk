<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Http\Middleware\UserTokenFromCookie;
use App\Integrations\Plugins\Extensions;
use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use Fahoba\SocialLogin\SocialIdentity;
use Fahoba\SocialLogin\SocialLoginExtension;
use Fahoba\TwoFactor\TwoFactorExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as RemoteUser;
use Mockery;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Signing in through a provider: only into accounts that exist, found once
 * by the confirmed address and then by the provider's id, under the same
 * rules as a password. The provider itself is a mock — Google is not asked.
 */
final class SocialLoginTest extends TestCase
{
    use RefreshDatabase;

    private User $parent;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'social-login.providers.google.client_id' => 'client',
            'social-login.providers.google.client_secret' => 'secret',
        ]);

        $household = Household::factory()->create();
        $this->parent = User::factory()->create([
            'household_id' => $household->id,
            'person_id' => Person::factory()->create(['household_id' => $household->id])->id,
            'email' => 'Kim@Example.test',
        ]);
    }

    private function switchOn(): void
    {
        app(Extensions::class)->save(SocialLoginExtension::KEY, true, []);
    }

    /** @param  array<string, mixed>  $raw */
    private function google(string $id, ?string $email, array $raw = ['email_verified' => true]): void
    {
        $remote = (new RemoteUser)->setRaw($raw)->map(['id' => $id, 'email' => $email, 'name' => 'Kim']);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($remote);
        $provider->shouldReceive('redirect')->andReturn(new RedirectResponse('https://accounts.google.com/o/oauth2/auth'));

        Socialite::shouldReceive('buildProvider')->andReturn($provider);
    }

    public function test_it_is_installed_but_off(): void
    {
        $this->assertArrayHasKey(SocialLoginExtension::KEY, app(Extensions::class)->installed());

        $this->getJson('/api/auth/social')->assertOk()->assertExactJson(['providers' => []]);
        $this->get('/social/google/redirect')->assertNotFound();
        $this->get('/social/google/callback')->assertNotFound();
    }

    public function test_switched_on_it_offers_the_configured_providers(): void
    {
        $this->switchOn();

        $this->getJson('/api/auth/social')
            ->assertOk()
            ->assertExactJson(['providers' => [['key' => 'google', 'label' => 'Google']]]);
    }

    public function test_a_provider_without_credentials_does_not_appear(): void
    {
        $this->switchOn();
        config(['social-login.providers.google.client_secret' => null]);

        $this->getJson('/api/auth/social')->assertExactJson(['providers' => []]);
        $this->get('/social/google/redirect')->assertNotFound();
    }

    public function test_the_operator_cannot_switch_it_on_without_a_provider(): void
    {
        config(['social-login.providers.google.client_id' => null]);

        $this->assertNotNull(app(SocialLoginExtension::class)->refuse(true, [], $this->parent));
        $this->assertNull(app(SocialLoginExtension::class)->refuse(false, [], $this->parent));
    }

    public function test_it_signs_in_the_account_with_the_confirmed_address(): void
    {
        $this->switchOn();
        $this->google('g-1', 'kim@example.test');

        $this->get('/social/google/redirect')->assertRedirect('https://accounts.google.com/o/oauth2/auth');

        $response = $this->get('/social/google/callback')->assertRedirect('/');
        $token = $response->getCookie(UserTokenFromCookie::COOKIE, false)?->getValue();

        $this->assertNotNull($token);
        $this->assertDatabaseHas('social_identities', ['user_id' => $this->parent->id, 'provider' => 'google', 'subject' => 'g-1']);

        Auth::forgetGuards();
        $this->withCredentials()
            ->withUnencryptedCookie(UserTokenFromCookie::COOKIE, $token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $this->parent->id);
    }

    public function test_once_linked_the_providers_id_counts_not_the_address(): void
    {
        $this->switchOn();
        SocialIdentity::query()->create(['user_id' => $this->parent->id, 'provider' => 'google', 'subject' => 'g-1']);
        $this->google('g-1', 'kim.new@example.test', ['email_verified' => false]);

        $this->get('/social/google/callback')
            ->assertRedirect('/')
            ->assertCookie(UserTokenFromCookie::COOKIE);
    }

    public function test_an_unknown_address_signs_in_nobody(): void
    {
        $this->switchOn();
        $this->google('g-2', 'stranger@example.test');

        $this->get('/social/google/callback')
            ->assertRedirect('/?social=unknown')
            ->assertCookieMissing(UserTokenFromCookie::COOKIE);

        $this->assertDatabaseCount('social_identities', 0);
    }

    public function test_an_unconfirmed_address_signs_in_nobody(): void
    {
        $this->switchOn();
        $this->google('g-1', 'kim@example.test', ['email_verified' => false]);

        $this->get('/social/google/callback')
            ->assertRedirect('/?social=unconfirmed')
            ->assertCookieMissing(UserTokenFromCookie::COOKIE);
    }

    public function test_a_failure_at_the_provider_leads_back_with_a_reason(): void
    {
        $this->switchOn();
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andThrow(new InvalidStateException);
        Socialite::shouldReceive('buildProvider')->andReturn($provider);

        $this->get('/social/google/callback')->assertRedirect('/?social=failed');
    }

    public function test_it_accepts_an_open_invitation(): void
    {
        $this->switchOn();
        $this->parent->forceFill(['email_verified_at' => null])->save();
        $this->google('g-1', 'kim@example.test');

        $this->get('/social/google/callback')->assertRedirect('/');

        $this->assertNotNull($this->parent->fresh()?->email_verified_at);
    }

    public function test_a_second_factor_is_still_asked_for(): void
    {
        $this->switchOn();
        app(Extensions::class)->save(TwoFactorExtension::KEY, true, ['required_for' => 'nobody']);
        $this->parent->saveAppAuthenticationSecret($secret = app(Google2FA::class)->generateSecretKey());
        $this->google('g-1', 'kim@example.test');

        $response = $this->get('/social/google/callback')->assertCookieMissing(UserTokenFromCookie::COOKIE);
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('?two-factor=', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->postJson('/api/auth/two-factor', [
            'ticket' => $query['two-factor'],
            'code' => app(Google2FA::class)->getCurrentOtp($secret),
        ])->assertOk()->assertCookie(UserTokenFromCookie::COOKIE);
    }

    public function test_from_the_admin_it_signs_into_the_admin(): void
    {
        $this->switchOn();
        $this->google('g-1', 'kim@example.test');

        $this->get('/social/google/redirect?to=admin');
        $this->get('/social/google/callback')
            ->assertRedirect('/admin')
            ->assertCookieMissing(UserTokenFromCookie::COOKIE);

        $this->assertAuthenticatedAs($this->parent, 'web');
    }

    public function test_the_admin_login_page_shows_the_buttons_only_when_switched_on(): void
    {
        $this->get('/admin/login')->assertOk()->assertDontSee('/social/google/redirect', escape: false);

        $this->switchOn();

        $this->get('/admin/login')->assertOk()->assertSee('/social/google/redirect?to=admin', escape: false);
    }
}

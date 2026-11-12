<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Filament\Pages\Extensions;
use App\Http\Middleware\UserTokenFromCookie;
use App\Integrations\Plugins\Extensions as InstalledExtensions;
use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use App\Notifications\LoginLink;
use App\Services\LoginLinks;
use App\Support\HouseholdContext;
use Fahoba\TwoFactor\TwoFactorExtension;
use Filament\Facades\Filament;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * The two-factor extension: off until the operator switches it on, then a
 * code after the password — in the admin, on the phone and after a link.
 */
final class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private User $parent;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $person = Person::factory()->create(['household_id' => $this->household->id]);
        $this->parent = User::factory()->create([
            'household_id' => $this->household->id,
            'person_id' => $person->id,
            'email' => 'kim@example.test',
            'password' => 'password-1',
        ]);

        $this->secret = app(Google2FA::class)->generateSecretKey();
    }

    private function switchOn(string $requiredFor = 'nobody'): void
    {
        app(InstalledExtensions::class)->save(TwoFactorExtension::KEY, true, ['required_for' => $requiredFor]);
    }

    private function setUpFactor(User $user): void
    {
        $user->saveAppAuthenticationSecret($this->secret);
        $user->saveAppAuthenticationRecoveryCodes([Hash::make('recover-me-1')]);
    }

    private function code(): string
    {
        return app(Google2FA::class)->getCurrentOtp($this->secret);
    }

    private function login(): TestResponse
    {
        return $this->postJson('/api/auth/login', ['email' => 'kim@example.test', 'password' => 'password-1']);
    }

    public function test_it_is_installed_but_off(): void
    {
        $this->assertArrayHasKey(TwoFactorExtension::KEY, app(InstalledExtensions::class)->installed());
        $this->assertFalse(app(InstalledExtensions::class)->enabled(TwoFactorExtension::KEY));

        // A secret from before does not matter while it is off.
        $this->setUpFactor($this->parent);
        $this->login()->assertOk()->assertCookie(UserTokenFromCookie::COOKIE);
    }

    public function test_the_phone_asks_for_the_code_after_the_password(): void
    {
        $this->setUpFactor($this->parent);
        $this->switchOn();

        $ticket = $this->login()->assertStatus(202)->assertCookieMissing(UserTokenFromCookie::COOKIE)->json('two_factor');
        $this->assertIsString($ticket);

        $this->postJson('/api/auth/two-factor', ['ticket' => $ticket, 'code' => '000000'])->assertUnprocessable();
        $this->postJson('/api/auth/two-factor', ['ticket' => $ticket, 'code' => $this->code()])
            ->assertOk()
            ->assertJsonPath('email', 'kim@example.test')
            ->assertCookie(UserTokenFromCookie::COOKIE);

        // The ticket is spent.
        $this->postJson('/api/auth/two-factor', ['ticket' => $ticket, 'code' => $this->code()])->assertUnprocessable();
    }

    public function test_a_recovery_code_works_once(): void
    {
        $this->setUpFactor($this->parent);
        $this->switchOn();

        $ticket = $this->login()->json('two_factor');
        $this->postJson('/api/auth/two-factor', ['ticket' => $ticket, 'code' => 'recover-me-1'])->assertOk();

        $this->travel(61)->seconds();
        $ticket = $this->login()->json('two_factor');
        $this->postJson('/api/auth/two-factor', ['ticket' => $ticket, 'code' => 'recover-me-1'])->assertUnprocessable();
    }

    public function test_five_wrong_codes_spend_the_ticket(): void
    {
        $this->setUpFactor($this->parent);
        $this->switchOn();

        $ticket = $this->login()->json('two_factor');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/two-factor', ['ticket' => $ticket, 'code' => '000000'])->assertUnprocessable();
        }

        $this->postJson('/api/auth/two-factor', ['ticket' => $ticket, 'code' => $this->code()])->assertUnprocessable();
    }

    public function test_a_required_factor_that_is_missing_blocks_the_phone(): void
    {
        $this->switchOn('managers');

        $this->login()->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_children_are_never_required(): void
    {
        $this->switchOn('managers');
        $child = User::factory()->create([
            'household_id' => $this->household->id,
            'person_id' => Person::factory()->child()->create(['household_id' => $this->household->id])->id,
            'email' => 'lena@example.test',
            'password' => 'password-1',
        ]);

        $this->postJson('/api/auth/login', ['email' => $child->email, 'password' => 'password-1'])->assertOk();
    }

    public function test_the_admin_sends_a_required_account_to_the_set_up(): void
    {
        $this->switchOn('managers');
        app(HouseholdContext::class)->setId((int) $this->household->id);

        $this->actingAs($this->parent)->get('/admin')
            ->assertRedirect(Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl());

        $this->setUpFactor($this->parent);
        $this->actingAs($this->parent->fresh())->get('/admin')->assertOk();
    }

    public function test_an_admin_login_link_does_not_skip_the_code(): void
    {
        $this->setUpFactor($this->parent);
        $this->switchOn();

        Notification::fake();
        app(LoginLinks::class)->issue('kim@example.test', 'admin');

        $url = null;
        Notification::assertSentTo($this->parent, LoginLink::class, function (LoginLink $notification) use (&$url): bool {
            $url = (string) $notification->toMail($this->parent)->actionUrl;

            return true;
        });

        $this->get((string) $url)
            ->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
        $this->assertGuest();
    }

    public function test_setting_up_a_factor_signs_the_phones_out(): void
    {
        $this->parent->createToken('mobile');

        $this->setUpFactor($this->parent);

        $this->assertSame(0, $this->parent->tokens()->count());
    }

    public function test_the_profile_offers_it_only_while_switched_on(): void
    {
        app(HouseholdContext::class)->setId((int) $this->household->id);
        $this->actingAs($this->parent);

        $this->get('/admin/profile')->assertOk()->assertDontSee('Authenticator');

        $this->switchOn();
        $this->get('/admin/profile')->assertOk()->assertSee('Authenticator');
    }

    public function test_a_parent_does_not_reach_the_extensions(): void
    {
        app(HouseholdContext::class)->setId((int) $this->household->id);

        $this->actingAs($this->parent)->get('/admin/operator/extensions')->assertForbidden();
    }

    public function test_the_operator_finds_the_extension_there(): void
    {
        $operator = User::factory()->create(['household_id' => null]);
        $operator->forceFill(['is_operator' => true])->save();

        $this->actingAs($operator)->get('/admin/operator/extensions')
            ->assertOk()
            ->assertSee('Zwei-Faktor-Anmeldung');
    }

    /** The contract speaks of any authenticated party; only an account can hold a factor. */
    public function test_a_requirement_is_refused_for_someone_who_cannot_hold_a_factor(): void
    {
        $someone = new GenericUser(['id' => 1]);

        $this->assertNotNull(app(TwoFactorExtension::class)->refuse(true, ['required_for' => 'operators'], $someone));
        $this->assertNull(app(TwoFactorExtension::class)->refuse(true, ['required_for' => 'nobody'], $someone));
    }

    public function test_the_operator_cannot_require_what_they_have_not_set_up(): void
    {
        $operator = User::factory()->create(['household_id' => null]);
        $operator->forceFill(['is_operator' => true])->save();
        $this->actingAs($operator);
        Filament::setCurrentPanel('admin');

        Livewire::test(Extensions::class)
            ->set('data.fahoba_two_factor.enabled', true)
            ->set('data.fahoba_two_factor.settings.required_for', 'operators')
            ->call('save')
            ->assertNotified();
        $this->assertFalse(app(InstalledExtensions::class)->enabled(TwoFactorExtension::KEY));

        $this->setUpFactor($operator);
        Livewire::test(Extensions::class)
            ->set('data.fahoba_two_factor.enabled', true)
            ->set('data.fahoba_two_factor.settings.required_for', 'operators')
            ->call('save');

        $this->assertTrue(app(InstalledExtensions::class)->enabled(TwoFactorExtension::KEY));
        $this->assertSame('operators', app(InstalledExtensions::class)->settings(TwoFactorExtension::KEY)['required_for']);
    }
}

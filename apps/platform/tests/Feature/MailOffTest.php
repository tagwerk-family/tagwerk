<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PersonRole;
use App\Filament\Auth\Login;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\ManagedHouseholds\Pages\ListManagedHouseholds;
use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use App\Notifications\PasswordChanged;
use App\Services\Invitations;
use App\Support\HouseholdContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A server without outgoing mail: every way that would send one is closed,
 * and passwords come from a parent or the operator instead.
 *
 * The switch is set in the environment before the application boots — the
 * admin panel, and with it Filament's password reset, is built at boot.
 */
final class MailOffTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array{0: string|false, 1: ?string, 2: ?string}> */
    private array $previousEnvironment = [];

    private User $user;

    protected function setUp(): void
    {
        // $_SERVER and $_ENV take precedence over putenv(); see RealtimeTest.
        $this->setEnvironment(['FAHOBA_MAIL' => 'off']);

        parent::setUp();

        Notification::fake();

        $household = Household::factory()->create();
        $this->user = User::factory()->create([
            'household_id' => $household->id,
            'person_id' => Person::factory()->create(['household_id' => $household->id])->id,
            'email' => 'kim@example.test',
            'password' => 'old-password-1',
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->previousEnvironment as $name => [$inProcess, $inEnv, $inServer]) {
            $inProcess === false ? putenv($name) : putenv("{$name}={$inProcess}");

            if ($inEnv === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $inEnv;
            }

            if ($inServer === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $inServer;
            }
        }
    }

    /** @param  array<string, string>  $values */
    private function setEnvironment(array $values): void
    {
        foreach ($values as $name => $value) {
            $this->previousEnvironment[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];

            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    public function test_the_app_learns_that_there_is_no_mail(): void
    {
        $this->getJson('/api/auth/methods')->assertOk()->assertExactJson(['mail' => false]);
    }

    public function test_every_mail_path_of_the_app_is_closed(): void
    {
        $this->postJson('/api/auth/link', ['email' => 'kim@example.test'])->assertNotFound();
        $this->postJson('/api/auth/link/redeem', ['email' => 'kim@example.test', 'code' => 'ABCDEF'])->assertNotFound();
        $this->postJson('/api/auth/password/forgot', ['email' => 'kim@example.test'])->assertNotFound();
        $this->postJson('/api/auth/password/reset', ['email' => 'kim@example.test'])->assertNotFound();
        $this->postJson('/api/auth/invitation', ['email' => 'kim@example.test'])->assertNotFound();
        $this->get('/login-link?login=x&email=kim@example.test')->assertNotFound();

        Notification::assertNothingSent();
    }

    public function test_signing_in_with_the_password_still_works(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'kim@example.test', 'password' => 'old-password-1'])
            ->assertSuccessful();
    }

    public function test_the_admin_login_offers_neither_reset_nor_link(): void
    {
        $this->assertFalse(Route::has('filament.admin.auth.password-reset.request'));
        $this->assertFalse(Filament::getPanel('admin')->hasPasswordReset());

        Filament::setCurrentPanel('admin');

        Livewire::test(Login::class)
            ->assertDontSee(__('Sign-in link by e-mail instead'))
            ->set('data.email', 'kim@example.test')
            ->call('sendLoginLink')
            ->assertNotFound();

        Notification::assertNothingSent();
    }

    public function test_a_new_email_applies_without_confirmation(): void
    {
        $this->actingAs($this->user);
        Filament::setCurrentPanel('admin');
        app(HouseholdContext::class)->setId((int) $this->user->memberships()->sole()->household_id);

        Livewire::test(EditProfile::class)
            ->fillForm(['email' => 'neu@example.test', 'currentPassword' => 'old-password-1'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('neu@example.test', $this->user->fresh()->email);
        Notification::assertNothingSent();
    }

    public function test_a_new_password_sends_no_notice(): void
    {
        $this->user->forceFill(['email_verified_at' => now()])->save();

        $this->user->update(['password' => 'new-password-1']);

        Notification::assertNotSentTo($this->user, PasswordChanged::class);
    }

    private function inAdmin(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        app(HouseholdContext::class)->setId((int) $user->memberships()->sole()->household_id);
    }

    public function test_a_parent_gives_a_new_account_its_password(): void
    {
        $this->inAdmin($this->user);
        $child = Person::factory()->create(['household_id' => $this->user->memberships()->sole()->household_id, 'role' => PersonRole::Child]);

        Livewire::test(CreateAccount::class)
            ->assertFormFieldIsVisible('password')
            ->fillForm(['person_id' => $child->id, 'email' => 'lena@example.test', 'password' => 'lena-password-1'])
            ->call('create')
            ->assertHasNoFormErrors();

        $account = User::query()->where('email', 'lena@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('lena-password-1', $account->password));
        $this->assertNotNull($account->email_verified_at);
        $this->assertFalse(Invitations::isPending($account));
        Notification::assertNothingSent();
    }

    public function test_a_new_account_needs_a_password(): void
    {
        $this->inAdmin($this->user);
        $child = Person::factory()->create(['household_id' => $this->user->memberships()->sole()->household_id]);

        Livewire::test(CreateAccount::class)
            ->fillForm(['person_id' => $child->id, 'email' => 'lena@example.test'])
            ->call('create')
            ->assertHasFormErrors(['password' => 'required']);
    }

    public function test_a_pending_invitation_becomes_active_when_a_parent_sets_the_password(): void
    {
        $this->inAdmin($this->user);
        $invited = User::factory()->create([
            'household_id' => $this->user->memberships()->sole()->household_id,
            'person_id' => Person::factory()->create(['household_id' => $this->user->memberships()->sole()->household_id])->id,
            'password' => Invitations::placeholderPassword(),
            'email_verified_at' => null,
        ]);

        // The page edits the membership, not the account: their ids drift apart
        // once any test has made an account without one (sequences ignore rollbacks).
        Livewire::test(EditAccount::class, ['record' => $invited->memberships()->sole()->getRouteKey()])
            ->fillForm(['password' => 'set-by-parent-1'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNotNull($invited->fresh()->email_verified_at);
        Notification::assertNothingSent();
    }

    public function test_the_operator_gives_the_first_parent_a_password(): void
    {
        $this->user->forceFill(['is_operator' => true])->save();
        $this->inAdmin($this->user);

        Livewire::test(ListManagedHouseholds::class)
            ->callAction('create', [
                'household' => 'Familie Neu',
                'timezone' => 'Europe/Vienna',
                'locale' => 'de',
                'parent' => 'Alex',
                'email' => 'alex@example.test',
                'password' => 'alex-password-1',
            ])
            ->assertHasNoActionErrors();

        $parent = User::query()->where('email', 'alex@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('alex-password-1', $parent->password));
        $this->assertNotNull($parent->email_verified_at);
        Notification::assertNothingSent();
    }
}

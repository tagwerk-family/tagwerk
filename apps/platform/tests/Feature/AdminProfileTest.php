<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\Auth\EditProfile as FahobaEditProfile;
use App\Models\Household;
use App\Models\User;
use App\Notifications\PasswordChanged;
use App\Support\HouseholdContext;
use Filament\Auth\Notifications\NoticeOfEmailChangeRequest;
use Filament\Auth\Notifications\VerifyEmailChange;
use Filament\Auth\Pages\EditProfile;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Everyone in the admin panel keeps their own name, email and password there.
 */
final class AdminProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $household = Household::factory()->create();
        $this->user = User::factory()->create([
            'household_id' => $household->id,
            'password' => 'old-password-1',
        ]);

        $this->actingAs($this->user);
        Filament::setCurrentPanel('admin');
        app(HouseholdContext::class)->setId($household->id);
    }

    public function test_the_profile_page_is_reachable(): void
    {
        $this->get('/admin/profile')->assertSuccessful()->assertSee($this->user->email);
    }

    public function test_the_operator_without_household_reaches_it_too(): void
    {
        $operator = User::factory()->create(['household_id' => null]);
        $operator->forceFill(['is_operator' => true])->save();

        $this->actingAs($operator)->get('/admin/profile')->assertSuccessful();
    }

    public function test_the_name_changes_after_confirming_the_password(): void
    {
        Livewire::test(EditProfile::class)
            ->fillForm(['name' => 'Neuer Name', 'currentPassword' => 'old-password-1'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Neuer Name', $this->user->fresh()->name);
    }

    public function test_the_language_is_chosen_in_the_profile(): void
    {
        Livewire::test(FahobaEditProfile::class)
            ->fillForm(['locale' => 'en', 'currentPassword' => 'old-password-1'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('en', $this->user->fresh()->locale);
        // Only one panel request per test: Filament keeps the built navigation.
        $this->actingAs($this->user->fresh())->get('/admin')->assertSee('Tasks')->assertDontSee('Aufgaben');
    }

    /** The new address counts once its owner confirms; the old one is warned. */
    public function test_a_new_email_needs_confirmation_from_the_new_address(): void
    {
        Notification::fake();
        $old = $this->user->email;

        Livewire::test(EditProfile::class)
            ->fillForm(['email' => 'neu@example.test', 'currentPassword' => 'old-password-1'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($old, $this->user->fresh()->email);
        Notification::assertSentTo($this->user, NoticeOfEmailChangeRequest::class);

        $url = null;
        Notification::assertSentOnDemand(VerifyEmailChange::class, function (VerifyEmailChange $notification, array $channels, AnonymousNotifiable $notifiable) use (&$url): bool {
            $url = $notification->url;

            return $notifiable->routeNotificationFor('mail') === 'neu@example.test';
        });

        // Filament uses Laravel's stock texts; lang/de.json turns them German.
        $mail = new VerifyEmailChange;
        $mail->url = (string) $url;
        $this->assertSame('Neue E-Mail-Adresse bestätigen', $mail->toMail($this->user)->subject);

        $this->get((string) $url)->assertRedirect();

        $this->assertSame('neu@example.test', $this->user->fresh()->email);
    }

    public function test_a_new_password_needs_the_current_one_and_logs_out_the_phones(): void
    {
        Notification::fake();
        $this->user->createToken('phone');

        Livewire::test(EditProfile::class)
            ->fillForm([
                'password' => 'new-password-1',
                'passwordConfirmation' => 'new-password-1',
                'currentPassword' => 'wrong',
            ])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);

        $this->assertTrue(Hash::check('old-password-1', $this->user->fresh()->password));

        Livewire::test(EditProfile::class)
            ->fillForm([
                'password' => 'new-password-1',
                'passwordConfirmation' => 'new-password-1',
                'currentPassword' => 'old-password-1',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('new-password-1', $this->user->fresh()->password));
        $this->assertSame(0, $this->user->tokens()->count());
        Notification::assertSentTo($this->user, PasswordChanged::class);
    }
}

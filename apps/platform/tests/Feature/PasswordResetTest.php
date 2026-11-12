<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Household;
use App\Models\User;
use App\Notifications\PasswordResetLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A forgotten password is reset from the phone app, by mail.
 */
final class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->user = User::factory()->create([
            'household_id' => Household::factory()->create()->id,
            'email' => 'kim@example.test',
            'password' => 'old-password-1',
        ]);
    }

    private function requestToken(): string
    {
        $this->postJson('/api/auth/password/forgot', ['email' => 'kim@example.test'])->assertStatus(202);

        $token = null;
        Notification::assertSentTo($this->user, PasswordResetLink::class, function (PasswordResetLink $notification) use (&$token): bool {
            $url = $notification->toMail($this->user)->actionUrl;
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $token = $query['reset'] ?? null;

            return str_starts_with($url, url('/?')) && ($query['email'] ?? null) === 'kim@example.test';
        });

        return (string) $token;
    }

    public function test_an_unknown_address_gets_the_same_answer(): void
    {
        $this->postJson('/api/auth/password/forgot', ['email' => 'nobody@example.test'])->assertStatus(202);

        Notification::assertNothingSent();
    }

    public function test_the_link_sets_a_new_password_once_and_logs_out_the_phones(): void
    {
        $this->user->createToken('phone');
        $token = $this->requestToken();
        $body = [
            'token' => $token,
            'email' => 'kim@example.test',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ];

        $this->postJson('/api/auth/password/reset', $body)->assertNoContent();

        $this->assertTrue(Hash::check('new-password-1', $this->user->fresh()->password));
        $this->assertSame(0, $this->user->tokens()->count());

        $this->postJson('/api/auth/password/reset', [...$body, 'password' => 'other-password-1', 'password_confirmation' => 'other-password-1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');
    }

    public function test_a_forged_token_changes_nothing(): void
    {
        $this->requestToken();

        $this->postJson('/api/auth/password/reset', [
            'token' => 'forged',
            'email' => 'kim@example.test',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertUnprocessable();

        $this->assertTrue(Hash::check('old-password-1', $this->user->fresh()->password));
    }

    public function test_the_mail_is_german(): void
    {
        $mail = (new PasswordResetLink('token'))->toMail($this->user);

        $this->assertSame('Neues Passwort für Fahoba', $mail->subject);
        $this->assertStringContainsString('kopiere diese Adresse', (string) $mail->render());
    }

    public function test_the_admin_login_offers_a_reset(): void
    {
        $this->get('/admin/login')->assertSee('Passwort vergessen');
    }
}

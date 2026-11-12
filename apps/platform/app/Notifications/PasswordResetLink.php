<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The link to a new password. It leads into the app, not into /admin:
 * the admin panel may be closed to the family's network, the app never is.
 */
final class PasswordResetLink extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    /** @return list<string> */
    public function via(User $user): array
    {
        return ['mail'];
    }

    public function toMail(User $user): MailMessage
    {
        $url = url('/?'.http_build_query(['reset' => $this->token, 'email' => $user->email]));
        $minutes = (int) config('auth.passwords.users.expire');

        return (new MailMessage)
            ->subject(__('New password for Family Home Base'))
            ->greeting(__('Hello :name,', ['name' => $user->name]))
            ->line(__('someone asked for a new password for your Family Home Base account.'))
            ->action(__('Set a new password'), $url)
            ->line(__('The link is valid for :minutes minutes and only once.', ['minutes' => $minutes]))
            ->line(__('If that was not you, just ignore this e-mail — your password stays as it is.'))
            ->salutation(__('Your Family Home Base'));
    }
}

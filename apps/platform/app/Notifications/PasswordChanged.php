<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Whoever did not change the password themselves learns it right away.
 */
final class PasswordChanged extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return list<string> */
    public function via(User $user): array
    {
        return ['mail'];
    }

    public function toMail(User $user): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your Family Home Base password was changed'))
            ->greeting(__('Hello :name,', ['name' => $user->name]))
            ->line(__('the password of your Family Home Base account was just changed. Every phone with this account is signed out and needs the new password.'))
            ->line(__('Was that not you? Then reset your password right away via “Forgot password?” and let your family know.'))
            ->action(__('Open Family Home Base'), url('/'))
            ->salutation(__('Your Family Home Base'));
    }
}

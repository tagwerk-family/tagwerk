<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Services\LoginLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class LoginLink extends Notification implements ShouldQueue
{
    use Queueable;

    /** Without a code for the admin panel: there the link is the whole way. */
    public function __construct(
        private readonly string $url,
        private readonly ?string $code,
    ) {}

    /** @return list<string> */
    public function via(User $user): array
    {
        return ['mail'];
    }

    public function toMail(User $user): MailMessage
    {
        $minutes = LoginLinks::LIFETIME_MINUTES;
        $mail = (new MailMessage)
            ->subject($this->code !== null ? __('Your Fahoba code: :code', ['code' => $this->code]) : __('Your sign-in link for Fahoba'))
            ->greeting(__('Hello :name,', ['name' => $user->name]))
            ->line(__('the button signs you in without a password.'))
            ->action(__('Sign in to Fahoba'), $this->url);

        if ($this->code !== null) {
            $mail->line(__('Do you use Fahoba as an app on your home screen? Then enter this code there instead:'))
                ->line("**{$this->code}**");
        }

        return $mail
            ->line(__('Valid for :minutes minutes and only once. If you did not ask for it, ignore this e-mail.', ['minutes' => $minutes]))
            ->salutation(__('Your Fahoba'));
    }
}

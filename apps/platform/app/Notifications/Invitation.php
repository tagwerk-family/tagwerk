<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Household;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A new account sets its own password — nobody else ever knows it.
 */
final class Invitation extends Notification implements ShouldQueue
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
        $url = url('/?'.http_build_query(['invite' => $this->token, 'email' => $user->email]));
        $household = Household::query()->whereKey($user->defaultMembership()?->household_id ?? $user->memberships()->value('household_id'))->value('name');

        return (new MailMessage)
            ->subject(__('Invitation to Family Home Base'))
            ->greeting(__('Hello :name,', ['name' => $user->name]))
            ->line($household !== null
                ? __('you now have an account in the family calendar “:household”.', ['household' => $household])
                : __('you now have an account with Family Home Base.'))
            ->line(__('Set your password with the button — you are signed in right after.'))
            ->action(__('Set password'), $url)
            ->line(__('The link is valid for seven days. On the phone you can then keep Family Home Base as an app via “Add to Home Screen”.'))
            ->salutation(__('Your Family Home Base'));
    }
}

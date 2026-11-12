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
 * An existing account is asked to join another household. No token: the
 * answer is given signed in, in the app, so the link only leads there.
 */
final class HouseholdInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $householdId) {}

    /** @return list<string> */
    public function via(User $user): array
    {
        return ['mail'];
    }

    public function toMail(User $user): MailMessage
    {
        $household = (string) Household::query()->whereKey($this->householdId)->value('name');

        return (new MailMessage)
            ->subject(__('Invitation to “:household”', ['household' => $household]))
            ->greeting(__('Hello :name,', ['name' => $user->name]))
            ->line(__('“:household” invites you to their family calendar. You keep your sign-in and switch between the households in the app.', ['household' => $household]))
            ->action(__('Open invitation'), url('/'))
            ->line(__('Nothing changes until you accept in the app.'))
            ->salutation(__('Your Fahoba'));
    }
}

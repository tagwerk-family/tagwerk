<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Jobs\BuildHouseholdArchiveJob;
use App\Models\Household;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

final class HouseholdArchiveReady extends HouseholdMessage
{
    public function __construct(public readonly Household $household, public readonly string $file, public readonly bool $secrets = false)
    {
        parent::__construct((int) $household->id);
    }

    public function title(): string
    {
        return __('Your Fahoba export is ready');
    }

    public function body(): string
    {
        return __('The link works for :days days.', ['days' => BuildHouseholdArchiveJob::DAYS]);
    }

    public function link(): string
    {
        return self::url($this->household, $this->file);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->title());

        if ($this->secrets) {
            $message->error()->line(__('This file contains the passwords and keys of your connections and dashboard tiles in plain text. Do not forward it, and delete it once the move is done.'));
        }

        return $message
            ->line(__('Everything of :household — calendars, tasks, stars, recipes, photos — in one file. Another Fahoba server can import it.', ['household' => $this->household->name]))
            ->action(__('Download'), $this->link())
            ->line($this->body());
    }

    public static function url(Household $household, string $file): string
    {
        return URL::temporarySignedRoute('household.archive', now()->addDays(BuildHouseholdArchiveJob::DAYS), [
            'household' => $household->id,
            'file' => basename($file, '.zip'),
        ]);
    }
}

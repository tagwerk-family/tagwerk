<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Services\HouseholdNotices;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Households\Notice;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The notices of the household in view, the same ones the app shows, plus
 * the server's own for the operator. Closing one is per account.
 */
final class HouseholdNoticeBar extends Component
{
    public function dismiss(string $key): void
    {
        $user = auth()->user();
        $context = app(HouseholdContext::class);

        if ($user instanceof User && $context->hasId()) {
            app(HouseholdNotices::class)->dismiss($user, (int) $context->id(), $key);
        }
    }

    public function render(): View
    {
        return view('livewire.household-notice-bar', ['notices' => self::notices()]);
    }

    /** @return list<Notice> */
    public static function notices(): array
    {
        $user = auth()->user();
        $context = app(HouseholdContext::class);
        $notices = app(HouseholdNotices::class);

        $household = $context->hasId() && $user instanceof User ? $notices->for((int) $context->id(), $user) : [];
        $server = $user instanceof User && $user->is_operator ? $notices->forOperator() : [];

        return [...$server, ...$household];
    }
}

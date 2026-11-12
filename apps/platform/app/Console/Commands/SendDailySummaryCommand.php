<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Feature;
use App\Jobs\SendPushNotificationJob;
use App\Models\Household;
use App\Services\DailySummary;
use App\Support\HouseholdContext;
use App\Support\Locale;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Sends the daily summary to the parents' phones.
 *
 * Runs every fifteen minutes and decides per household whether it is evening
 * there: a fixed time in the schedule would be the server's zone, which is
 * already wrong for two households in different countries.
 * `summary_sent_on` keeps the same summary from arriving four times an hour
 * until midnight.
 */
final class SendDailySummaryCommand extends Command
{
    protected $signature = 'fahoba:send-daily-summary';

    protected $description = "Sends the evening summary to the adults' phones";

    public function handle(DailySummary $summary, HouseholdContext $context): int
    {
        $sent = 0;

        Household::active()->each(function (Household $household) use ($summary, $context, &$sent): void {
            $zone = $household->timezone ?? (string) config('app.timezone');
            $now = CarbonImmutable::now($zone);
            $today = $now->toDateString();

            if ($household->summary_sent_on?->toDateString() === $today) {
                return;
            }

            [$hour, $minute] = array_map('intval', explode(':', (string) $household->summary_at));

            if ($now->lt($now->setTime($hour, $minute))) {
                return;
            }

            $texts = Locale::inEvery(fn (): ?string => $context->forId(
                (int) $household->id,
                fn (): ?string => $summary->text($now->startOfDay()),
            ));

            /*
             * The marker is set even without a message: otherwise the command
             * would check the same household every fifteen minutes until
             * midnight.
             */
            $household->forceFill(['summary_sent_on' => $today])->save();

            // Without tasks there is nothing to say, in any language.
            if (in_array(null, $texts, true)) {
                return;
            }

            SendPushNotificationJob::dispatch((int) $household->id, Locale::inEvery(fn (): array => [
                'title' => __('Daily summary'),
                'body' => $texts[app()->getLocale()],
                'tag' => 'daily-summary',
                'url' => '/?area='.Feature::Tasks->wallMode(),
            ]), adultsOnly: true);

            $sent++;
        });

        $this->info("Sent {$sent} daily summaries.");

        return self::SUCCESS;
    }
}

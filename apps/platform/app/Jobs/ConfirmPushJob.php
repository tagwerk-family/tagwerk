<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Device;
use App\Services\Push\WebPushService;
use App\Support\Locale;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A sample notification to the phone that has just switched notifications
 * on — only that one. Whoever taps "Einschalten" sees at once that it
 * works, instead of waiting until the evening to find out it did not.
 *
 * Failures are logged, not rethrown, as in SendPushNotificationJob.
 */
final class ConfirmPushJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $deviceId) {}

    public function handle(WebPushService $push): void
    {
        $device = Device::withoutHouseholdScope()->with('user')->find($this->deviceId);

        if ($device === null || $device->push_endpoint === null) {
            return;
        }

        try {
            $delivered = $push->send([$device], Locale::inEvery(fn (): array => [
                'title' => __('Notifications are on'),
                'body' => __('This is how Fahoba reaches you.'),
                'tag' => 'push-confirm',
            ]));

            if ($delivered === 0) {
                Log::info('Sample notification not delivered.', ['device_id' => $this->deviceId]);
            }
        } catch (Throwable $exception) {
            Log::warning('Could not send the sample notification.', [
                'device_id' => $this->deviceId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}

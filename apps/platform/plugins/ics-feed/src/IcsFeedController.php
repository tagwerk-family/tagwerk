<?php

declare(strict_types=1);

namespace Fahoba\IcsFeed;

use App\Models\Household;
use App\Support\HouseholdContext;
use Illuminate\Http\Response;

/**
 * `GET /ics/{token}`: the address a calendar app subscribes to. No sign-in —
 * the secret in the address is the permission, which is why a revoked or
 * switched-off feed answers 404 and not 403.
 */
final class IcsFeedController
{
    public function __invoke(string $token, HouseholdContext $context, IcsCalendar $calendar): Response
    {
        $feed = IcsFeed::withoutHouseholdScope()->where('token_hash', IcsFeed::hash($token))->first();
        $household = $feed === null ? null : Household::query()->active()->find($feed->household_id);

        abort_if($feed === null || $household === null || ! $household->has(IcsFeedArea::KEY), 404);

        $body = $context->forId((int) $household->id, function () use ($feed, $household, $calendar): string {
            $feed->forceFill(['last_used_at' => now()])->saveQuietly();

            return $calendar->build($feed, $household);
        });

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Cache-Control' => 'private, max-age=900',
        ]);
    }
}

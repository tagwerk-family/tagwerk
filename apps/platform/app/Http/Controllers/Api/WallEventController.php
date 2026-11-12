<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Calendar;
use App\Models\Event;
use App\Models\ProviderAccount;
use App\Services\Calendar\EventWriteService;
use App\Support\Abilities;
use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Calendar\EventConflict;
use Fahoba\PluginApi\Connections\AuthorizationLost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Creates, changes and deletes events on the tablet and the phone.
 *
 * With "own" only events the actor takes part in — before and after the
 * change, so nobody hands an event over to a sibling.
 */
final class WallEventController extends Controller
{
    public function __construct(
        private readonly EventWriteService $writer,
        private readonly Abilities $abilities,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, creating: true);

        // findOrFail goes through the household scope: a foreign calendar
        // yields 404 instead of a write into someone else's data.
        $calendar = Calendar::query()->findOrFail($data['calendar_id']);

        if (! $calendar->is_active) {
            throw ValidationException::withMessages([
                'calendar_id' => __('This calendar is not written to.'),
            ]);
        }

        $this->abilities->authorizeOn(
            $request->user(),
            Permission::CalendarEditOwn,
            $this->peopleAfter($calendar, $data['people'] ?? []),
        );

        $event = $this->withoutConnectionErrors(fn (): Event => $this->writer->create(
            $calendar,
            $this->attributes($data),
            $data['people'] ?? [],
        ));

        return response()->json($this->payload($event, $request), 201);
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        $data = $this->validated($request, creating: false);

        $this->abilities->authorizeOn($request->user(), Permission::CalendarEditOwn, $this->peopleOf($event));

        if (isset($data['people'])) {
            $this->abilities->authorizeOn(
                $request->user(),
                Permission::CalendarEditOwn,
                $this->peopleAfter($event->calendar, $data['people']),
            );
        }

        try {
            $updated = $this->withoutConnectionErrors(fn (): Event => $this->writer->update(
                $event,
                $this->attributes($data),
                $data['people'] ?? null,
            ));
        } catch (EventConflict) {
            /*
             * The provider won. We fetch its state and return it so the wall
             * can show it instead of writing its own input over it.
             */
            return response()->json([
                'message' => __('The event has been changed at the provider in the meantime.'),
                'event' => $this->payload($this->writer->refresh($event), $request),
            ], 409);
        }

        return response()->json($this->payload($updated, $request));
    }

    public function destroy(Request $request, Event $event): JsonResponse
    {
        $this->abilities->authorizeOn($request->user(), Permission::CalendarEditOwn, $this->peopleOf($event));

        $this->withoutConnectionErrors(function () use ($event): Event {
            $this->writer->delete($event);

            return $event;
        });

        return response()->json(status: 204);
    }

    /**
     * A disconnected source is a user error, not a crash.
     *
     * Without this translation the exception would surface as a 500 and the
     * wall would report "the server did not respond" — sending the household
     * in entirely the wrong direction.
     *
     * @param  callable(): Event  $write
     */
    private function withoutConnectionErrors(callable $write): Event
    {
        try {
            return $write();
        } catch (AuthorizationLost $exception) {
            throw ValidationException::withMessages([
                'calendar_id' => __('The account :account is not connected. Please connect it again in the admin panel.', [
                    'account' => ProviderAccount::of($exception->source)->account_label,
                ]),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'calendar_id' => [$creating ? 'required' : 'prohibited', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:8000'],
            'location' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'all_day' => ['boolean'],
            'people' => ['array'],
            'people.*' => ['integer'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'location' => $data['location'] ?? null,
            'starts_at' => CarbonImmutable::parse($data['starts_at']),
            'ends_at' => CarbonImmutable::parse($data['ends_at']),
            'all_day' => (bool) ($data['all_day'] ?? false),
        ];
    }

    /** @return list<int> */
    private function peopleOf(Event $event): array
    {
        return $event->resolvedPeople()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * Who an event will concern: the chosen people, otherwise those of the
     * calendar.
     *
     * @param  array<int, int>  $chosen
     * @return list<int>
     */
    private function peopleAfter(?Calendar $calendar, array $chosen): array
    {
        $people = $chosen !== [] ? $chosen : ($calendar?->people()->pluck('people.id')->all() ?? []);

        return array_values(array_map(fn (mixed $id): int => (int) $id, $people));
    }

    /** @return array<string, mixed> */
    private function payload(Event $event, Request $request): array
    {
        $people = $event->resolvedPeople();

        return [
            'id' => $event->id,
            'title' => $event->title,
            'description' => $event->description,
            'location' => $event->location,
            'starts_at' => $event->starts_at->toIso8601String(),
            'ends_at' => $event->ends_at->toIso8601String(),
            'all_day' => $event->all_day,
            'calendar_id' => $event->calendar_id,
            'people' => $people->pluck('id')->all(),
            'color' => $people->first()?->color ?? $event->calendar?->color,
            'can' => ['edit' => $this->abilities->mayActOn(
                $request->user(),
                Permission::CalendarEditOwn,
                $people->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            )],
        ];
    }
}

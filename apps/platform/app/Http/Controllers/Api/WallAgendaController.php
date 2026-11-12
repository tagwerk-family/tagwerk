<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Areas\AreaDefinition;
use App\Areas\Areas;
use App\Enums\ConnectionStatus;
use App\Enums\Feature;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Calendar;
use App\Models\Device;
use App\Models\Event;
use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use App\Services\Holidays\HolidayService;
use App\Services\HouseholdNotices;
use App\Services\Photos\PhotoCast;
use App\Services\School\LessonOccurrence;
use App\Services\School\LessonOccurrenceService;
use App\Support\Abilities;
use App\Support\HouseholdContext;
use App\Support\Locale;
use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Households\Notice;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Everything the wall needs to show for a period, in one response.
 *
 * Colours and person assignment are resolved here, not on the tablet: the
 * device stays a pure display, and the rule from section 5.2 has only one
 * implementation.
 */
final class WallAgendaController extends Controller
{
    private const MAX_DAYS = 400;

    /**
     * How far the calendar band reaches.
     *
     * Week, three days and month stay below it; a yearly fetch would otherwise
     * pull thousands of lessons nobody ever sees.
     */
    private const MAX_LESSON_DAYS = 62;

    public function __construct(
        private readonly HolidayService $holidays,
        private readonly LessonOccurrenceService $lessons,
        private readonly HouseholdContext $context,
        private readonly Abilities $abilities,
        private readonly Areas $areas,
        private readonly PhotoCast $casts,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $household = Household::findOrFail($this->context->id());
        $zone = $household->timezone ?? config('app.timezone');

        $from = CarbonImmutable::parse($data['from'], $zone)->startOfDay();
        $to = CarbonImmutable::parse($data['to'], $zone)->endOfDay();

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw ValidationException::withMessages([
                'to' => __('The period may span at most :days days.', ['days' => self::MAX_DAYS]),
            ]);
        }

        return response()->json([
            'household' => [
                'name' => $household->name,
                'timezone' => $zone,
                // What the household should know (trial, read-only, a lost connection); the wall shows each once a day.
                'notices' => array_map(
                    static fn (Notice $notice): array => $notice->toArray(),
                    app(HouseholdNotices::class)->for((int) $household->id, $request->user()),
                ),

                /*
                 * Which areas the bar may show. From the response and not
                 * hard-coded in the frontend, so that a toggle in the admin
                 * reaches the tablet with the next fetch.
                 */
                'features' => $household->features(),
                // How the bar shows them — the wall keeps no list of its own.
                // Areas without a view of their own (a plugin's webhooks) are switches, not entries.
                'areas' => array_values(array_map(static fn (AreaDefinition $area): array => [
                    'feature' => $area->key,
                    'mode' => $area->key,
                    'label' => $area->shortLabel,
                    'icon' => $area->icon,
                ], array_filter(
                    array_map(fn (string $key): ?AreaDefinition => $this->areas->find($key), $household->features()),
                    static fn (?AreaDefinition $area): bool => $area?->inMenu === true,
                ))),
            ],

            // The tablet's own language or the household's; the wall switches to it.
            'locale' => app()->getLocale(),
            'language' => Locale::describe(app()->getLocale()),

            // For the wall's ground rule: "as of 14:32" instead of an empty page.
            'generated_at' => CarbonImmutable::now($zone)->toIso8601String(),

            /*
             * Version check: when the value changes the wall reloads itself.
             * Without it an old build would stick there after a deploy,
             * because nobody ever reloads a wall tablet by hand.
             */
            'app_version' => (string) config('app.version'),

            // Device and account share the endpoint; the app sets a device's
            // actor from the agenda only when the agenda says it is one.
            'viewer' => $request->user() instanceof Device ? 'device' : 'user',

            'range' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'people' => $this->people(),
            'calendars' => $this->calendars($hidden = $this->hiddenCalendars($request)),
            'events' => $this->events($from, $to, $request, $hidden),
            'holidays' => $this->holidays->between($household, $from, $to)
                ->map(fn ($holiday): array => $holiday->toArray())
                ->all(),
            'lessons' => $this->lessons($household, $from, $to),

            // A picture a phone sent to the walls; only walls show it.
            'shown_photo' => $request->user() instanceof Device && $household->has(Feature::Photos)
                ? $this->casts->current((int) $household->id)
                : null,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function people(): array
    {
        return Person::query()
            ->ordered()
            ->get()
            ->map(fn (Person $person): array => [
                'id' => $person->id,
                'name' => $person->name,
                'color' => $person->color,
                'avatar' => $person->avatarUrl(),
                'role' => $person->role->value,
            ])
            ->all();
    }

    /**
     * What this account or wall chose not to show. Both calendar list and
     * events leave them out, so nobody offers to write into a hidden one.
     *
     * @return array<int, int>
     */
    private function hiddenCalendars(Request $request): array
    {
        $actor = $request->user();

        return $actor instanceof User || $actor instanceof Device
            ? array_map('intval', $actor->hidden_calendar_ids ?? [])
            : [];
    }

    /**
     * @param  array<int, int>  $hidden
     * @return array<int, array<string, mixed>>
     */
    private function calendars(array $hidden): array
    {
        return Calendar::query()
            ->where('is_active', true)
            ->whereNotIn('id', $hidden)
            ->with(['source', 'people'])
            ->orderBy('summary')
            ->get()
            ->map(fn (Calendar $calendar): array => [
                'id' => $calendar->id,
                'summary' => $calendar->summary,
                'color' => $calendar->color,
                'person_ids' => $calendar->people->pluck('id')->all(),

                /*
                 * The calendar of a disconnected account cannot be written to.
                 * Without this flag the wall would offer it and only reject
                 * the fully typed input on saving — with a message nobody in
                 * front of the tablet can do anything about.
                 */
                'writable' => $calendar->source === null
                    || $calendar->source->status === ConnectionStatus::Active,
            ])
            ->all();
    }

    /**
     * @param  array<int, int>  $hidden
     * @return array<int, array<string, mixed>>
     */
    private function events(CarbonImmutable $from, CarbonImmutable $to, Request $request, array $hidden): array
    {
        return Event::query()
            ->whereHas('calendar', fn (Builder $query) => $query->where('is_active', true))
            ->whereNotIn('calendar_id', $hidden)

            // Overlap with the period, not just a start inside it.
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->with(['people', 'calendar.people'])
            ->orderBy('starts_at')
            ->get()
            ->map(function (Event $event) use ($request): array {
                $people = $event->resolvedPeople();
                $ids = $people->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

                return [
                    'id' => $event->id,
                    'title' => $event->title,
                    'location' => $event->location,
                    'starts_at' => $event->starts_at->toIso8601String(),
                    'ends_at' => $event->ends_at->toIso8601String(),
                    'all_day' => $event->all_day,
                    'calendar_id' => $event->calendar_id,
                    'people' => $people->pluck('id')->all(),

                    // Person wins over calendar — otherwise the assignment
                    // could not be read from the colour on the wall.
                    'color' => $people->first()?->color ?? $event->calendar?->color,

                    // Decided here, never recomputed by the interface.
                    'can' => ['edit' => $this->abilities->mayActOn($request->user(), Permission::CalendarEditOwn, $ids)],
                ];
            })
            ->all();
    }

    /**
     * The school lessons of the period for the calendar band.
     *
     * They travel with the agenda so the band needs no second fetch — and
     * because the wall fetches the whole agenda every few minutes anyway.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lessons(Household $household, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! $household->has(Feature::Timetable) || $from->diffInDays($to) > self::MAX_LESSON_DAYS) {
            return [];
        }

        return $this->lessons
            ->between($household, $from, $to)
            ->map(fn (LessonOccurrence $occurrence): array => $occurrence->toArray())
            ->all();
    }
}

<?php

declare(strict_types=1);

namespace Fahoba\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Lists the household\'s events between two dates, with its calendars and people. Times are ISO 8601 in the household\'s time zone.')]
final class ListEvents extends ApiTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->format('date')->description('First day, YYYY-MM-DD.')->required(),
            'to' => $schema->string()->format('date')->description('Last day, YYYY-MM-DD.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->api('GET', 'wall/agenda', $request->only(['from', 'to']), static fn (array $body): array => [
            'timezone' => $body['household']['timezone'] ?? null,
            'people' => array_map(static fn (array $person): array => ['id' => $person['id'], 'name' => $person['name']], $body['people'] ?? []),
            'calendars' => array_map(static fn (array $calendar): array => ['id' => $calendar['id'], 'name' => $calendar['summary'], 'writable' => $calendar['writable']], $body['calendars'] ?? []),
            'events' => array_map(static fn (array $event): array => array_intersect_key($event, array_flip([
                'id', 'title', 'description', 'location', 'starts_at', 'ends_at', 'all_day', 'calendar_id', 'people',
            ])), $body['events'] ?? []),
        ]);
    }
}

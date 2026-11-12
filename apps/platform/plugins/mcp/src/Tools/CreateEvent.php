<?php

declare(strict_types=1);

namespace Fahoba\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Creates an event in one of the household\'s calendars. Find calendar and people ids with list-events first.')]
final class CreateEvent extends ApiTool
{
    protected bool $writes = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'calendar_id' => $schema->integer()->required(),
            'title' => $schema->string()->required(),
            'starts_at' => $schema->string()->format('date-time')->description('ISO 8601 with offset.')->required(),
            'ends_at' => $schema->string()->format('date-time')->description('ISO 8601 with offset; after starts_at.')->required(),
            'all_day' => $schema->boolean(),
            'location' => $schema->string(),
            'description' => $schema->string(),
            'people' => $schema->array()->items($schema->integer())->description('Person ids; empty means the calendar\'s people.'),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->api('POST', 'wall/events', $request->all(), static fn (array $event): array => $event);
    }
}

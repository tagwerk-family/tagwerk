<?php

declare(strict_types=1);

namespace Fahoba\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Lists task occurrences per day: who is responsible, whether done, stars. Without dates: today and the next six days.')]
final class ListTasks extends ApiTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->format('date')->description('First day, YYYY-MM-DD.'),
            'to' => $schema->string()->format('date')->description('Last day, YYYY-MM-DD.'),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->api('GET', 'wall/tasks', $request->only(['from', 'to']), static fn (array $body): array => [
            'range' => $body['range'] ?? null,
            'occurrences' => array_map(static fn (array $occurrence): array => array_intersect_key($occurrence, array_flip([
                'task_id', 'title', 'date', 'for_person_id', 'daypart', 'points', 'person_id', 'person_name', 'done', 'done_by',
            ])), $body['occurrences'] ?? []),
        ]);
    }
}

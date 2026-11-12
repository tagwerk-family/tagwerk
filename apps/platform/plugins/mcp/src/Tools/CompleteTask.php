<?php

declare(strict_types=1);

namespace Fahoba\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Ticks off one occurrence of a task, as listed by list-tasks. Pass for_person_id and daypart exactly as listed.')]
final class CompleteTask extends ApiTool
{
    protected bool $writes = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->required(),
            'date' => $schema->string()->format('date')->required(),
            'for_person_id' => $schema->integer(),
            'daypart' => $schema->string(),
            'person_id' => $schema->integer()->description('Who did it; otherwise whose turn it was.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $task = (int) $request->get('task_id');

        return $this->api('POST', "wall/tasks/{$task}/completion", $request->except(['task_id']), static fn (array $body): array => [
            'done' => true,
            ...$body,
        ]);
    }
}

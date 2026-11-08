<?php

declare(strict_types=1);

namespace Tagwerk\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The statistics in the assistant's terms: names instead of ids, so that
 * "how often did Max take out the bin" needs no second lookup.
 */
#[IsReadOnly]
#[Description('Statistics of done tasks: how often each task was done by whom, per person the share of their own tasks done, stars earned and spent, and on which weekdays. Without dates: the last 28 days. Parents only.')]
final class TaskStatistics extends ApiTool
{
    private const string NOBODY = 'without a name';

    private const array WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->format('date')->description('First day, YYYY-MM-DD. Needs `to`.'),
            'to' => $schema->string()->format('date')->description('Last day, YYYY-MM-DD, at most two years after `from`.'),
            'task_id' => $schema->integer()->description('Only this task (ids from list-tasks).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = [
            'period' => $request->get('from') !== null || $request->get('to') !== null ? 'custom' : null,
            'from' => $request->get('from'),
            'until' => $request->get('to'),
            'task' => $request->get('task_id'),
        ];

        return $this->api('GET', 'statistics', $query, static function (array $body): array {
            $names = array_column($body['people'] ?? [], 'name', 'id');
            $name = static fn (int|string $key): string => $key === 'none' ? self::NOBODY : (string) ($names[$key] ?? $key);
            $named = static function (array $byKey) use ($name): array {
                $result = [];

                foreach ($byKey as $key => $value) {
                    $result[$name($key)] = $value;
                }

                return $result;
            };

            $people = [];

            foreach ($body['people'] ?? [] as $person) {
                $id = $person['id'];
                $reliability = $body['reliability'][$id] ?? null;
                $people[] = [
                    'name' => $person['name'],
                    'own_tasks_due' => $reliability['due'] ?? 0,
                    'own_tasks_done' => $reliability['done'] ?? 0,
                    'own_tasks_done_percent' => ($reliability['due'] ?? 0) > 0 ? round($reliability['done'] / $reliability['due'] * 100) : null,
                    'stars_earned' => $body['stars'][$id]['earned'] ?? 0,
                    'stars_spent' => $body['stars'][$id]['spent'] ?? 0,
                ];
            }

            return [
                'period' => $body['period'] ?? null,
                'totals' => $body['totals'] ?? null,
                'people' => $people,
                'tasks' => array_map(static fn (array $task): array => [
                    'task_id' => $task['id'],
                    'title' => $task['title'],
                    'total' => $task['total'],
                    'done_by' => $named($task['counts']),
                ], $body['tasks'] ?? []),
                'weekdays' => array_map(
                    static fn (array $days): array => array_combine(self::WEEKDAYS, $days),
                    $named($body['weekdays'] ?? []),
                ),
            ];
        });
    }
}

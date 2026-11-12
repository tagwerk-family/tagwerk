<?php

declare(strict_types=1);

namespace Fahoba\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Shows the meal plan between two dates. Without dates: this week and the next.')]
final class MealPlan extends ApiTool
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
        return $this->api('GET', 'wall/meals', $request->only(['from', 'to']), static fn (array $body): array => [
            'range' => $body['range'] ?? null,
            'meals' => array_map(static fn (array $meal): array => array_intersect_key($meal, array_flip([
                'id', 'date', 'kind', 'kind_label', 'title', 'notes', 'servings',
            ])), $body['meals'] ?? []),
        ]);
    }
}

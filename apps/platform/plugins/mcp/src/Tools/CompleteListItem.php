<?php

declare(strict_types=1);

namespace Fahoba\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Ticks off a list item, or opens it again with done false.')]
final class CompleteListItem extends ApiTool
{
    protected bool $writes = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'item_id' => $schema->integer()->required(),
            'done' => $schema->boolean()->description('Default true.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $item = (int) $request->get('item_id');

        return $this->api('PATCH', "wall/items/{$item}", ['done' => (bool) $request->get('done', true)], static fn (array $item): array => $item);
    }
}

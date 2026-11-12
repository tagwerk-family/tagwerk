<?php

declare(strict_types=1);

namespace Fahoba\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Lists the household\'s lists — shopping and others — with their items, open ones first.')]
final class ListLists extends ApiTool
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        return $this->api('GET', 'wall/lists', [], static fn (array $body): array => array_map(static fn (array $list): array => [
            'id' => $list['id'],
            'name' => $list['name'],
            'items' => array_map(static fn (array $item): array => array_intersect_key($item, array_flip(['id', 'title', 'quantity', 'done'])), $list['items']),
        ], $body['lists'] ?? []));
    }
}

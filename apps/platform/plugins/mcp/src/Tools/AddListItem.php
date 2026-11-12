<?php

declare(strict_types=1);

namespace Fahoba\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Adds an item to a list; find the list id with list-lists.')]
final class AddListItem extends ApiTool
{
    protected bool $writes = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'list_id' => $schema->integer()->required(),
            'title' => $schema->string()->required(),
            'quantity' => $schema->string()->description('Free text such as "2 kg".'),
        ];
    }

    public function handle(Request $request): Response
    {
        $list = (int) $request->get('list_id');

        return $this->api('POST', "wall/lists/{$list}/items", $request->only(['title', 'quantity']), static fn (array $item): array => $item);
    }
}

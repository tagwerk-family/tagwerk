<?php

declare(strict_types=1);

namespace Fahoba\Mcp;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/**
 * Runs a tool's call through the app's own API, with the caller's token.
 *
 * The tools translate, they do not decide: permissions, the token's scope,
 * a switched-off area and validation stay where wall and phone meet them.
 * A tool written against the models would be a second set of rules that
 * drifts from the first.
 */
final class Api
{
    public function __construct(private readonly Kernel $kernel) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{status: int, body: array<string, mixed>}
     */
    public function call(string $method, string $path, array $data = []): array
    {
        $outer = request();
        $query = $method === 'GET' ? $data : [];
        $inner = Request::create('/api/'.ltrim($path, '/'), $method, $query, server: [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => (string) $outer->header('Authorization'),
            'REMOTE_ADDR' => (string) $outer->ip(),
        ], content: $method === 'GET' ? null : (string) json_encode($data));

        try {
            $response = $this->kernel->handle($inner);
        } finally {
            // The kernel binds the inner request; the MCP answer belongs to the outer one.
            app()->instance('request', $outer);
            Facade::clearResolvedInstance('request');
        }

        $body = json_decode((string) $response->getContent(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($body) ? $body : []];
    }
}

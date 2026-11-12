<?php

declare(strict_types=1);

namespace Fahoba\Mcp\Tools;

use App\Auth\ApiTokens;
use Fahoba\Mcp\Api;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Arr;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/** A tool that is one call of the app's API, answered in the assistant's terms. */
abstract class ApiTool extends Tool
{
    /** Writing tools are offered only to a token that may write. */
    protected bool $writes = false;

    public function shouldRegister(HttpRequest $request): bool
    {
        return ! $this->writes || ApiTokens::allows($request->user()?->currentAccessToken(), ApiTokens::WRITE);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  callable(array<string, mixed>): mixed  $shape
     */
    protected function api(string $method, string $path, array $data, callable $shape): Response
    {
        $data = array_filter($data, static fn (mixed $value): bool => $value !== null);
        ['status' => $status, 'body' => $body] = app(Api::class)->call($method, $path, $data);

        return match (true) {
            $status < 300 => Response::json($shape($body)),
            $status === 422 => Response::error(implode(' ', Arr::flatten($body['errors'] ?? [$body['message'] ?? '']))),
            in_array($status, [403, 423], true) => Response::error(__('This account may not do that.')),
            $status === 404 => Response::error(__('Not found, or the area is switched off for this household.')),
            default => Response::error(__('The server could not do that (:status).', ['status' => $status])),
        };
    }
}

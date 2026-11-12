<?php

declare(strict_types=1);

namespace App\Integrations\Plugins;

use App\Support\OutboundHttp;
use Fahoba\PluginApi\Http\Outbound;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/** The core's side of the plugins' HTTP helper. */
final class CoreOutbound implements Outbound
{
    private const TRIES = 3;

    private const PAUSE_MS = 200;

    public function __construct(private readonly OutboundHttp $household) {}

    public function server(): PendingRequest
    {
        return Http::withUserAgent($this->userAgent())
            ->retry(self::TRIES, self::PAUSE_MS, static fn (?Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && in_array($exception->response->status(), [429, 500, 502, 503, 504], true)), throw: false);
    }

    public function householdGet(string $url, int $timeout = 10): ?Response
    {
        return $this->household->get($url, $timeout, ['User-Agent' => $this->userAgent()]);
    }

    public function householdSend(string $method, string $url, array $options = [], int $timeout = 10): ?Response
    {
        $options['headers'] = ['User-Agent' => $this->userAgent(), ...(array) ($options['headers'] ?? [])];

        return $this->household->send($method, $url, $options, $timeout);
    }

    private function userAgent(): string
    {
        return 'Fahoba/'.config('app.version').' (+'.config('app.url').')';
    }
}

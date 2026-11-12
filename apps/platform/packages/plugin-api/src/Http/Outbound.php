<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Http;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Outbound HTTP for plugins, bound by the core.
 *
 * Every request carries Fahoba's limits (20 s, 5 s to connect) and a
 * User-Agent naming the server, so a service can tell who asks.
 */
interface Outbound
{
    /**
     * For services the plugin itself knows (an API with a fixed host).
     * Asks again on 429, 5xx and connection errors, three tries in all. An
     * error status comes back as a response; a connection that fails all
     * three times throws `ConnectionException`. Inside a widget that costs
     * the tile, not the wall.
     */
    public function server(): PendingRequest;

    /**
     * GET for an address a household typed in. Private addresses (the
     * server's own network, cloud metadata) are refused, redirects are
     * checked hop by hop. Null when refused or unreachable.
     */
    public function householdGet(string $url, int $timeout = 10): ?Response;

    /**
     * Any method for an address a household typed in, without following
     * redirects. Null when refused or unreachable.
     *
     * @param  array<string, mixed>  $options  Guzzle request options (headers, body, auth)
     */
    public function householdSend(string $method, string $url, array $options = [], int $timeout = 10): ?Response;
}

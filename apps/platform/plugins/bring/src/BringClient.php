<?php

declare(strict_types=1);

namespace Fahoba\Bring;

use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Connections\AuthorizationLost;
use Fahoba\PluginApi\Connections\ConnectedAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The Bring REST API as the Bring apps use it.
 *
 * Bring publishes no API. Endpoints and the client key follow the
 * maintained `bring-api` library (Python, used by Home Assistant); if Bring
 * changes them, only the sync breaks — the list in Fahoba stays.
 *
 * Bring knows no OAuth: the household's email and password stay in the
 * account, encrypted, and the tokens from the login are kept next to them so
 * not every call logs in again.
 */
final class BringClient
{
    private const BASE_URL = 'https://api.getbring.com/rest/';

    /** The key every Bring client sends; it identifies the app, not a user. */
    private const API_KEY = 'cof4Nc6D8saplXjE3h3HXqHH8m7VU2i1Gs0g85Sp';

    public const OPERATION_ADD = 'TO_PURCHASE';

    public const OPERATION_COMPLETE = 'TO_RECENTLY';

    public const OPERATION_REMOVE = 'REMOVE';

    /**
     * @return array<string, mixed> The login response: uuid, bringListUUID, access_token, …
     *
     * @throws BringLoginFailed
     */
    public function login(string $email, string $password): array
    {
        $response = $this->http()
            ->asForm()
            ->post('v2/bringauth', ['email' => $email, 'password' => $password]);

        if (in_array($response->status(), [400, 401, 404], true)) {
            throw new BringLoginFailed($response->status());
        }

        return (array) $response->throw()->json();
    }

    /** @return array<int, array{listUuid: string, name: string}> */
    public function lists(ConnectedAccount $account): array
    {
        $uuid = (string) $this->session($account)['uuid'];

        return (array) ($this->call($account, 'get', "bringusers/{$uuid}/lists")->json('lists') ?? []);
    }

    /**
     * Open items under `purchase`, ticked ones under `recently`.
     *
     * @return array{purchase: array<int, array<string, mixed>>, recently: array<int, array<string, mixed>>}
     */
    public function items(ConnectedAccount $account, string $listUuid): array
    {
        $items = (array) ($this->call($account, 'get', "v2/bringlists/{$listUuid}")->json('items') ?? []);

        return [
            'purchase' => (array) ($items['purchase'] ?? []),
            'recently' => (array) ($items['recently'] ?? []),
        ];
    }

    /**
     * Several items in one request.
     *
     * @param  array<int, array{itemId: string, spec: string, uuid: string, operation: string}>  $changes
     */
    public function change(ConnectedAccount $account, string $listUuid, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $this->call($account, 'put', "v2/bringlists/{$listUuid}/items", [
            'changes' => array_map(
                // The apps send a location with every change; zeros are what they send without one.
                static fn (array $change): array => [
                    'accuracy' => '0.0',
                    'altitude' => '0.0',
                    'latitude' => '0.0',
                    'longitude' => '0.0',
                    ...$change,
                ],
                $changes,
            ),
            'sender' => '',
        ]);
    }

    /** The list Bring opens by default; the fallback when no list matches. */
    public function defaultList(ConnectedAccount $account): ?string
    {
        $list = $this->session($account)['default_list'] ?? null;

        return is_string($list) && $list !== '' ? $list : null;
    }

    /**
     * One call with a valid token; a rejected token gets one fresh login.
     *
     * @param  array<string, mixed>  $body
     */
    private function call(ConnectedAccount $account, string $method, string $path, array $body = []): Response
    {
        $response = $this->send($account, $method, $path, $body);

        if ($response->status() === 401) {
            $this->authenticate($account);
            $response = $this->send($account, $method, $path, $body);
        }

        return $response->throw();
    }

    /**
     * @param  'get'|'put'  $method
     * @param  array<string, mixed>  $body
     */
    private function send(ConnectedAccount $account, string $method, string $path, array $body): Response
    {
        $session = $this->session($account);
        $request = $this->http()
            ->withToken((string) $account->accessToken())
            ->withHeaders([
                'X-BRING-USER-UUID' => (string) $session['uuid'],
                'X-BRING-PUBLIC-USER-UUID' => (string) ($session['public_uuid'] ?? ''),
            ]);

        return $method === 'get' ? $request->get($path) : $request->put($path, $body);
    }

    /**
     * The session from the account; logs in when there is none or it ran out.
     *
     * @return array<string, mixed>
     */
    private function session(ConnectedAccount $account): array
    {
        $session = (array) ($account->settings()['bring'] ?? []);
        $expired = $account->tokenExpiresAt() === null
            || $account->tokenExpiresAt()->isBefore(Carbon::now()->addMinute());

        if (($session['uuid'] ?? null) === null || $account->accessToken() === null || $expired) {
            $this->authenticate($account);
            $session = (array) $account->settings()['bring'];
        }

        return $session;
    }

    /**
     * A full login rather than the refresh token: Bring's tokens last a week,
     * and one path is easier to trust than two.
     */
    private function authenticate(ConnectedAccount $account): void
    {
        $credentials = $account->credentials();

        try {
            $login = $this->login((string) ($credentials['email'] ?? ''), (string) ($credentials['password'] ?? ''));
        } catch (BringLoginFailed) {
            // The password was changed at Bring: only the household can fix that.
            $account->markInvalid();

            throw new AuthorizationLost($account);
        }

        $settings = $account->settings();
        $settings['bring'] = [
            'uuid' => (string) ($login['uuid'] ?? ''),
            'public_uuid' => (string) ($login['publicUuid'] ?? ''),
            'default_list' => (string) ($login['bringListUUID'] ?? ''),
        ];

        $account->storeSettings($settings);
        $account->storeToken(
            (string) ($login['access_token'] ?? ''),
            CarbonImmutable::now()->addSeconds((int) ($login['expires_in'] ?? 3600)),
        );
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->acceptJson()
            ->timeout(10)
            ->withHeaders([
                'X-BRING-API-KEY' => self::API_KEY,
                'X-BRING-CLIENT' => 'android',
                'X-BRING-APPLICATION' => 'bring',
                'X-BRING-COUNTRY' => 'DE',
            ])
            // A gateway hiccup at Bring is common enough to try once more.
            ->retry(2, 500, fn (Throwable $exception): bool => $exception instanceof RequestException
                && in_array($exception->response->status(), [502, 503, 504], true), throw: false);
    }
}

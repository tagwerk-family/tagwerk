<?php

declare(strict_types=1);

namespace App\Integrations\Lists\GoogleTasks;

use App\Integrations\Google\GoogleOAuth;
use App\Integrations\Google\GoogleTasksClient;
use Fahoba\PluginApi\Connections\ConnectedAccount;
use Fahoba\PluginApi\Connections\DescribesSetup;
use Fahoba\PluginApi\Connections\TokenSet;
use Fahoba\PluginApi\Connections\UsesOAuth;
use Fahoba\PluginApi\Lists\ListItemDraft;
use Fahoba\PluginApi\Lists\ListProvider;
use Fahoba\PluginApi\Lists\ListsTargets;
use Fahoba\PluginApi\Lists\RemoteList;

/**
 * Google Tasks as a target for shopping items.
 *
 * Shares the client ID with the calendar provider, but not the consent: the
 * scopes here cover only `tasks`, and whoever connected the calendar has not
 * connected their task lists along with it. They are two rows among the
 * connections, to be disconnected separately.
 *
 * One way like every list provider: Fahoba reads nothing back, so an outage
 * only costs a convenience.
 */
final class GoogleTasksListProvider implements DescribesSetup, ListProvider, ListsTargets, UsesOAuth
{
    public function __construct(
        private readonly GoogleOAuth $oauth,
        private readonly GoogleTasksClient $client,
    ) {}

    public function key(): string
    {
        return 'google';
    }

    public function label(): string
    {
        return 'Google Tasks';
    }

    public function icon(): string
    {
        return 'heroicon-o-shopping-bag';
    }

    public function description(): string
    {
        return __('Pushes the shopping list into a Google Tasks list.');
    }

    public function setupNotes(): array
    {
        return [
            __('Items only go out — ticking off happens in Family Home Base.'),
            __('You choose the target list afterwards in the overview.'),
            __('This approval only covers lists. To bring in your tasks, connect Google Tasks a second time as tasks.'),
        ];
    }

    public function isConfigured(): bool
    {
        return (string) config('services.google.client_id') !== ''
            && (string) config('services.google.client_secret') !== '';
    }

    /**
     * Deliberately only `tasks`: without userinfo.email the connection would
     * stay nameless, so that one comes along — calendar scopes do not.
     *
     * @return array<int, string>
     */
    public function scopes(): array
    {
        return [
            'https://www.googleapis.com/auth/tasks',
            'https://www.googleapis.com/auth/userinfo.email',
        ];
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return $this->oauth->authorizationUrl($state, $this->scopes(), $redirectUri);
    }

    public function exchangeCode(string $code, string $redirectUri): TokenSet
    {
        $tokens = $this->oauth->exchangeCode($code, $redirectUri);
        $accessToken = (string) ($tokens['access_token'] ?? '');

        return new TokenSet(
            accessToken: $accessToken,
            refreshToken: $tokens['refresh_token'] ?? null,
            expiresIn: (int) ($tokens['expires_in'] ?? 3600),
            accountLabel: $this->oauth->accountEmail($accessToken),
        );
    }

    public function freshAccessToken(ConnectedAccount $account): string
    {
        return $this->oauth->freshAccessToken($account);
    }

    public function markAuthorizationLost(ConnectedAccount $account): never
    {
        $this->oauth->markAuthorizationLost($account);
    }

    /** @return array<int, RemoteList> */
    public function targets(ConnectedAccount $account): array
    {
        return array_map(
            static fn (array $list): RemoteList => new RemoteList(
                id: (string) $list['id'],
                name: (string) ($list['title'] ?? ''),
            ),
            $this->client->taskLists($account),
        );
    }

    /**
     * @param  array<int, ListItemDraft>  $items
     */
    public function push(array $items, ?ConnectedAccount $account = null, ?string $target = null): void
    {
        if ($items === [] || $account === null || $target === null) {
            return;
        }

        foreach ($items as $item) {
            $this->client->createTask($account, $target, array_filter([
                'title' => $item->name,

                // Google has no field of its own for the quantity.
                'notes' => $item->spec,
            ], static fn (mixed $value): bool => $value !== null));
        }
    }
}

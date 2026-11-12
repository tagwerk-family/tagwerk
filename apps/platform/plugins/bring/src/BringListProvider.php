<?php

declare(strict_types=1);

namespace Fahoba\Bring;

use Closure;
use Fahoba\PluginApi\Connections\ConnectedAccount;
use Fahoba\PluginApi\Connections\CredentialField;
use Fahoba\PluginApi\Connections\DescribesSetup;
use Fahoba\PluginApi\Connections\UsesCredentials;
use Fahoba\PluginApi\Lists\ListItemDraft;
use Fahoba\PluginApi\Lists\ListProvider;
use Fahoba\PluginApi\Lists\ListsTargets;
use Fahoba\PluginApi\Lists\RemoteList;
use Fahoba\PluginApi\Lists\RemoteListItem;
use Fahoba\PluginApi\Lists\SyncsItems;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Bring! as a shopping list that syncs both ways.
 *
 * Both ways because Bring is where voice assistants write: whoever tells
 * Alexa to add milk expects it on the wall. Fahoba stays the list the
 * household works with; Bring is a second door into it.
 */
final class BringListProvider implements DescribesSetup, ListProvider, ListsTargets, SyncsItems, UsesCredentials
{
    public function __construct(private readonly BringClient $client) {}

    public function key(): string
    {
        return 'fahoba/bring';
    }

    public function label(): string
    {
        return 'Bring!';
    }

    public function icon(): string
    {
        return 'heroicon-o-shopping-cart';
    }

    public function description(): string
    {
        return __('Syncs a shopping list with Bring — both ways, including what Alexa adds there.');
    }

    public function setupNotes(): array
    {
        return [
            __('Sign in with the e-mail and password of your Bring account. If you sign in to Bring with Google or Apple, first set a password via “Forgot password” in the Bring app.'),
            __('You choose afterwards, on the list in the admin panel, which Bring list belongs to which Family Home Base list.'),
            __('New items and ticks go both ways. Whatever is added there shows up here within minutes.'),
            __('Bring has no official interface. If Bring changes something, only the sync fails — the list in Family Home Base stays.'),
        ];
    }

    /** Everything the provider needs comes with the household's connection. */
    public function isConfigured(): bool
    {
        return true;
    }

    public function credentialFields(): array
    {
        return [
            new CredentialField(
                name: 'email',
                label: 'E-Mail',
                placeholder: 'name@example.org',
            ),
            new CredentialField(
                name: 'password',
                label: __('Password'),
                secret: true,
            ),
        ];
    }

    public function verifyCredentials(array $credentials): ?string
    {
        $email = trim((string) ($credentials['email'] ?? ''));
        $password = (string) ($credentials['password'] ?? '');

        if ($email === '' || $password === '') {
            return __('E-mail and password are both needed.');
        }

        try {
            $this->client->login($email, $password);
        } catch (BringLoginFailed $exception) {
            return $exception->status === 401
                ? __('E-mail or password is wrong.')
                : __('Bring does not know this e-mail address.');
        } catch (Throwable) {
            return __('Bring is not answering right now. Please try again later.');
        }

        return null;
    }

    public function accountLabel(array $credentials): string
    {
        return 'Bring! – '.trim((string) ($credentials['email'] ?? ''));
    }

    /** @return array<int, RemoteList> */
    public function targets(ConnectedAccount $account): array
    {
        $lists = $this->client->lists($account);
        $default = $this->client->defaultList($account);

        return array_map(
            static fn (array $list): RemoteList => new RemoteList(
                id: (string) $list['listUuid'],
                name: (string) ($list['name'] ?? ''),
                default: $list['listUuid'] === $default,
            ),
            $lists,
        );
    }

    /** @param  array<int, ListItemDraft>  $items */
    public function push(array $items, ?ConnectedAccount $account = null, ?string $target = null): void
    {
        if ($items === [] || $account === null) {
            return;
        }

        $this->client->change($account, $this->list($account, $target), array_map(
            static fn (ListItemDraft $item): array => [
                'itemId' => $item->name,
                'spec' => (string) $item->spec,
                'uuid' => (string) Str::uuid(),
                'operation' => BringClient::OPERATION_ADD,
            ],
            $items,
        ));
    }

    /** @return array<int, RemoteListItem> */
    public function items(ConnectedAccount $account, string $target): array
    {
        $items = $this->client->items($account, $this->list($account, $target));

        $map = static fn (bool $done): Closure => static fn (array $item): RemoteListItem => new RemoteListItem(
            name: (string) $item['itemId'],
            spec: ($item['specification'] ?? '') === '' ? null : (string) $item['specification'],
            done: $done,
        );

        return [
            ...array_map($map(false), $items['purchase']),
            ...array_map($map(true), $items['recently']),
        ];
    }

    public function markDone(ConnectedAccount $account, string $target, ListItemDraft $item, bool $done): void
    {
        $list = $this->list($account, $target);
        $items = $this->client->items($account, $list);
        $match = $this->find($items[$done ? 'purchase' : 'recently'], $item->name);

        // Ticking something Bring does not have open changes nothing there.
        if ($done && $match === null) {
            return;
        }

        $this->client->change($account, $list, [[
            'itemId' => (string) ($match['itemId'] ?? $item->name),
            'spec' => (string) ($match['specification'] ?? $item->spec),
            'uuid' => (string) ($match['uuid'] ?? Str::uuid()),
            'operation' => $done ? BringClient::OPERATION_COMPLETE : BringClient::OPERATION_ADD,
        ]]);
    }

    public function remove(ConnectedAccount $account, string $target, ListItemDraft $item): void
    {
        $list = $this->list($account, $target);
        $items = $this->client->items($account, $list);
        $match = $this->find([...$items['purchase'], ...$items['recently']], $item->name);

        if ($match === null) {
            return;
        }

        $this->client->change($account, $list, [[
            'itemId' => (string) $match['itemId'],
            'spec' => (string) ($match['specification'] ?? ''),
            'uuid' => (string) ($match['uuid'] ?? ''),
            'operation' => BringClient::OPERATION_REMOVE,
        ]]);
    }

    /**
     * The Bring list for a target: its ID, else a list of that name, else
     * the one Bring opens by default. Fahoba's "Einkaufsliste" and Bring's
     * "Zuhause" rarely share a name, and the default is almost always meant.
     */
    private function list(ConnectedAccount $account, ?string $target): string
    {
        $lists = $this->client->lists($account);

        foreach ($lists as $list) {
            if ($list['listUuid'] === $target) {
                return $target;
            }
        }

        foreach ($lists as $list) {
            if ($target !== null && mb_strtolower((string) $list['name']) === mb_strtolower(trim($target))) {
                return (string) $list['listUuid'];
            }
        }

        return $this->client->defaultList($account)
            ?? (string) ($lists[0]['listUuid'] ?? throw new RuntimeException('The Bring account has no list.'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>|null
     */
    private function find(array $items, string $name): ?array
    {
        foreach ($items as $item) {
            if (mb_strtolower(trim((string) ($item['itemId'] ?? ''))) === mb_strtolower(trim($name))) {
                return $item;
            }
        }

        return null;
    }
}

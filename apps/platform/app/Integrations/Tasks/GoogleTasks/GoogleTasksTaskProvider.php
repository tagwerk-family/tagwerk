<?php

declare(strict_types=1);

namespace App\Integrations\Tasks\GoogleTasks;

use App\Integrations\Google\GoogleOAuth;
use App\Integrations\Google\GoogleTasksClient;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Connections\ConnectedAccount;
use Fahoba\PluginApi\Connections\DescribesSetup;
use Fahoba\PluginApi\Connections\TokenSet;
use Fahoba\PluginApi\Connections\UsesOAuth;
use Fahoba\PluginApi\Lists\RemoteList;
use Fahoba\PluginApi\Tasks\ImportsTasks;
use Fahoba\PluginApi\Tasks\ListsTaskLists;
use Fahoba\PluginApi\Tasks\RemoteTask;
use Fahoba\PluginApi\Tasks\TaskProvider;
use Fahoba\PluginApi\Tasks\WritesTasks;

/**
 * Google Tasks as a source of tasks.
 *
 * The same service as the list provider, but an area and a consent of its
 * own: whoever pushes their shopping list there has not pulled in their tasks
 * along with it.
 *
 * Imported tasks are ordinary rows with an origin. Points, assignee and
 * rotation are laid on top by the household — Google knows none of that, and
 * the sync never touches these fields.
 */
final class GoogleTasksTaskProvider implements DescribesSetup, ImportsTasks, ListsTaskLists, TaskProvider, UsesOAuth, WritesTasks
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
        return 'heroicon-o-check-circle';
    }

    public function description(): string
    {
        return __('Brings in tasks from Google Tasks; Family Home Base assigns stars and people.');
    }

    public function setupNotes(): array
    {
        return [
            __('Tasks brought in keep their title from the provider — stars, assignees and repetition belong to Family Home Base.'),
            __('A sync never overwrites these additions.'),
            __('When a task is ticked off in Family Home Base, that goes back to Google.'),
        ];
    }

    /**
     * The same scopes as the list service, but a consent of its own: two
     * connections, two tokens, to be disconnected separately.
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
    public function taskLists(ConnectedAccount $account): array
    {
        return array_map(
            static fn (array $list): RemoteList => new RemoteList(
                id: (string) $list['id'],
                name: (string) ($list['title'] ?? ''),
            ),
            $this->client->taskLists($account),
        );
    }

    /** @return array<int, RemoteTask> */
    public function tasks(ConnectedAccount $account, ?string $list = null): array
    {
        $source = $list ?? $this->chosenList($account);

        return array_values(array_filter(array_map(
            static function (array $entry): ?RemoteTask {
                $title = $entry['title'] ?? null;

                // Google allows empty titles; on the wall that would be a row
                // nobody can place.
                if (! is_string($title) || trim($title) === '') {
                    return null;
                }

                return new RemoteTask(
                    externalId: (string) $entry['id'],
                    title: $title,
                    notes: $entry['notes'] ?? null,
                    dueOn: isset($entry['due'])
                        ? CarbonImmutable::parse((string) $entry['due'])
                        : null,
                    completedAt: isset($entry['completed'])
                        ? CarbonImmutable::parse((string) $entry['completed'])
                        : null,
                    version: $entry['updated'] ?? null,
                );
            },
            $this->client->tasks($account, $source),
        )));
    }

    public function markDone(ConnectedAccount $account, string $externalId, bool $done): void
    {
        $this->client->patchTask($account, $this->chosenList($account), $externalId, [
            'status' => $done ? 'completed' : 'needsAction',

            /*
             * When undoing, completed has to be cleared explicitly; Google
             * keeps the timestamp otherwise and still shows the task as done.
             */
            'completed' => $done ? now()->toRfc3339String() : null,
        ]);
    }

    /** Which list the household chose — otherwise Google's default list. */
    private function chosenList(ConnectedAccount $account): string
    {
        $chosen = $account->settings()['import_list'] ?? null;

        return is_string($chosen) && $chosen !== ''
            ? $chosen
            : GoogleTasksClient::DEFAULT_LIST;
    }
}

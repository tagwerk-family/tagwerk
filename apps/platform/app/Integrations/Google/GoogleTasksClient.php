<?php

declare(strict_types=1);

namespace App\Integrations\Google;

use Fahoba\PluginApi\Connections\ConnectedAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the Tasks API.
 *
 * Shared by two providers: the list service pushes shopping items in, the
 * task service pulls tasks out. The same endpoints, two areas — and two
 * separate consents.
 */
final class GoogleTasksClient
{
    private const BASE = 'https://tasks.googleapis.com/tasks/v1';

    /** Google's own alias for an account's default list. */
    public const DEFAULT_LIST = '@default';

    public function __construct(private readonly GoogleOAuth $oauth) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function taskLists(ConnectedAccount $account): array
    {
        return $this->request($account)
            ->get(self::BASE.'/users/@me/lists', ['maxResults' => 100])
            ->throw()
            ->json('items') ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function tasks(ConnectedAccount $account, string $list): array
    {
        return $this->request($account)
            ->get(self::BASE."/lists/{$list}/tasks", [
                'maxResults' => 100,

                // Without showCompleted, what was already done outside would be missing.
                'showCompleted' => 'true',
                'showHidden' => 'true',
            ])
            ->throw()
            ->json('items') ?? [];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function createTask(ConnectedAccount $account, string $list, array $fields): void
    {
        $this->request($account)
            ->post(self::BASE."/lists/{$list}/tasks", $fields)
            ->throw();
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function patchTask(ConnectedAccount $account, string $list, string $id, array $fields): void
    {
        $this->request($account)
            ->patch(self::BASE."/lists/{$list}/tasks/{$id}", $fields)
            ->throw();
    }

    private function request(ConnectedAccount $account): PendingRequest
    {
        return Http::withToken($this->oauth->freshAccessToken($account))
            ->acceptJson()
            ->timeout(10);
    }
}

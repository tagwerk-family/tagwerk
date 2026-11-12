<?php

declare(strict_types=1);

namespace App\Services\Lists;

use App\Enums\ConnectionStatus;
use App\Integrations\ProviderRegistry;
use App\Models\Checklist;
use App\Models\ListItem;
use App\Models\ProviderAccount;
use Fahoba\PluginApi\Lists\ListItemDraft;
use Fahoba\PluginApi\Lists\ListProvider;
use Fahoba\PluginApi\Lists\RemoteListItem;
use Fahoba\PluginApi\Lists\SyncsItems;
use Illuminate\Support\Carbon;

/**
 * Brings what changed at a two-way provider into a Fahoba list.
 *
 * The provider wins for every item it holds: something re-added there by
 * voice opens again here. What it does not hold stays untouched — it may be
 * on its way out, or the household keeps it only here.
 */
final class ListSyncService
{
    /**
     * A local change this fresh is still on its way to the provider. Without
     * the grace a pull between tick and mirror would undo the tick.
     */
    private const GRACE_MINUTES = 2;

    public function __construct(private readonly ProviderRegistry $registry) {}

    /**
     * `$first` is the pull right after linking: what is open only here goes
     * over before anything comes back. Without it, an item Bring once had
     * ticked would tick the open one here instead of reopening it there.
     */
    public function pull(Checklist $checklist, bool $first = false): void
    {
        $provider = $this->registry->listProvider((string) $checklist->list_provider);
        $account = $checklist->list_account_id === null
            ? null
            : ProviderAccount::find($checklist->list_account_id);

        if (! $provider instanceof SyncsItems
            || ! $provider instanceof ListProvider
            || $account === null
            || $account->status !== ConnectionStatus::Active) {
            return;
        }

        $remote = $provider->items($account, $checklist->remoteTarget());
        $local = $checklist->items()->orderBy('id')->get()->groupBy(fn (ListItem $item): string => self::key($item->title));
        $position = (int) $checklist->items()->max('position');
        $fresh = Carbon::now()->subMinutes(self::GRACE_MINUTES);
        $sent = $first ? $this->sendOpen($provider, $account, $checklist, $remote) : [];

        foreach ($remote as $item) {
            if (in_array(self::key($item->name), $sent, true)) {
                continue;
            }

            $matches = $local->get(self::key($item->name));

            if ($matches === null) {
                // The provider's history of ticked items is not ours to import.
                if (! $item->done) {
                    $checklist->items()->create([
                        'title' => mb_substr($item->name, 0, 255),
                        'quantity' => $item->spec === null ? null : mb_substr($item->spec, 0, 64),
                        'position' => ++$position,
                    ]);
                }

                continue;
            }

            if ($matches->contains(fn (ListItem $match): bool => $match->isDone() === $item->done)) {
                continue;
            }

            $match = $matches->last();

            if ($match->updated_at !== null && $match->updated_at->greaterThan($fresh)) {
                continue;
            }

            $match->done_at = $item->done ? Carbon::now() : null;
            $match->save();
        }

        $account->forceFill(['synced_at' => Carbon::now()])->save();
    }

    /**
     * @param  array<int, RemoteListItem>  $remote
     * @return array<int, string> The keys of what went over.
     */
    private function sendOpen(ListProvider $provider, ProviderAccount $account, Checklist $checklist, array $remote): array
    {
        $open = array_map(
            static fn (RemoteListItem $item): string => self::key($item->name),
            array_filter($remote, static fn (RemoteListItem $item): bool => ! $item->done),
        );

        $missing = $checklist->items()->whereNull('done_at')->orderBy('position')->get()
            ->reject(fn (ListItem $item): bool => in_array(self::key($item->title), $open, true))
            ->unique(fn (ListItem $item): string => self::key($item->title));

        $provider->push(
            $missing->map(fn (ListItem $item): ListItemDraft => new ListItemDraft($item->title, $item->quantity))->values()->all(),
            $account,
            $checklist->remoteTarget(),
        );

        return $missing->map(fn (ListItem $item): string => self::key($item->title))->values()->all();
    }

    private static function key(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}

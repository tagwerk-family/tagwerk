<?php

declare(strict_types=1);

namespace App\Services\Lists;

use App\Enums\ListKind;
use App\Integrations\ProviderRegistry;
use App\Jobs\SyncListJob;
use App\Models\Checklist;
use App\Models\ProviderAccount;
use Fahoba\PluginApi\Connections\ConnectedAccount;
use Fahoba\PluginApi\Connections\FinishesConnection;
use Fahoba\PluginApi\Lists\ListsTargets;
use Fahoba\PluginApi\Lists\RemoteList;
use Fahoba\PluginApi\Lists\SyncsItems;
use Illuminate\Database\Eloquent\Builder;

/**
 * Remembers which lists exist on the connected account.
 *
 * In the connection's settings instead of a table of their own: the names
 * only serve the picker in the form. The list in Fahoba stays
 * authoritative, and a table for a few names would also have to be
 * maintained.
 *
 * A two-way provider also gets its lists paired right away: whoever connects
 * Bring expects the Bring list on the wall, not a second trip to the admin.
 */
final class ListImportService implements FinishesConnection
{
    public function __construct(private readonly ProviderRegistry $registry) {}

    public function finish(ConnectedAccount $connected): void
    {
        $account = ProviderAccount::of($connected);
        $provider = $this->registry->forAccount($account);

        if (! $provider instanceof ListsTargets) {
            return;
        }

        $targets = $provider->targets($account);
        $settings = (array) ($account->settings ?? []);

        $settings['targets'] = array_map(
            static fn (RemoteList $list): array => ['id' => $list->id, 'name' => $list->name],
            $targets,
        );

        $account->forceFill([
            'settings' => $settings,
            'synced_at' => now(),
        ])->save();

        if ($provider instanceof SyncsItems) {
            $this->pair($account, $targets);
        }
    }

    /**
     * One Fahoba list per remote list: one of the same name, for the
     * default list the household's shopping list, otherwise a new one.
     * Lists already paired with this account stay as they are.
     *
     * @param  array<int, RemoteList>  $targets
     */
    private function pair(ProviderAccount $account, array $targets): void
    {
        $paired = Checklist::query()->where('list_account_id', $account->id)->pluck('list_target')->all();

        // Default first: it claims the shopping list before a namesake could.
        usort($targets, static fn (RemoteList $a, RemoteList $b): int => $b->default <=> $a->default);

        foreach ($targets as $target) {
            if (in_array($target->id, $paired, true)) {
                continue;
            }

            $list = $this->free($account)
                ->whereRaw('lower(name) = ?', [mb_strtolower(trim($target->name))])
                ->first()
                ?? ($target->default ? $this->free($account)->where('kind', ListKind::Shopping)->first() : null)
                ?? new Checklist([
                    'name' => $target->name,
                    'kind' => ListKind::Shopping,
                    'position' => (int) Checklist::query()->max('position') + 1,
                ]);

            $list->fill([
                'list_provider' => $account->provider,
                'list_account_id' => $account->id,
                'list_target' => $target->id,
            ])->save();

            // What was only here goes over, what was only there comes here.
            SyncListJob::dispatch((int) $list->id, first: true);
        }
    }

    /**
     * Lists not yet going anywhere, or meant for this provider without an
     * account (from before connections were per household).
     *
     * @return Builder<Checklist>
     */
    private function free(ProviderAccount $account): Builder
    {
        return Checklist::query()
            ->where(fn (Builder $query) => $query
                ->whereNull('list_provider')
                ->orWhere(fn (Builder $query) => $query
                    ->where('list_provider', $account->provider)
                    ->whereNull('list_account_id')))
            ->orderBy('position')
            ->orderBy('id');
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Permission;
use App\Integrations\Plugins\PluginCatalog;
use App\Integrations\ProviderRegistry;
use App\Models\Device;
use App\Models\Household;
use App\Models\ProviderAccount;
use App\Models\User;
use App\Support\Abilities;
use App\Support\Locale;
use Fahoba\PluginApi\Households\HouseholdNotice;
use Fahoba\PluginApi\Households\Notice;
use Fahoba\PluginApi\Households\NoticeAudience;
use Fahoba\PluginApi\Households\NoticeLevel;
use Fahoba\PluginApi\Plugins\LoadReport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What a household should know right now — states, computed on asking,
 * never stored: what plugins offer, the core's sentence for a read-only
 * household no plugin explains, and connections that lost their consent.
 *
 * Every notice shows, the most severe first, each to its audience. Kept a
 * minute per household and language — every agenda fetch asks.
 */
final class HouseholdNotices
{
    public const SECONDS = 60;

    public function __construct(
        private readonly PluginCatalog $plugins,
        private readonly ProviderRegistry $registry,
        private readonly Abilities $abilities,
        private readonly LoadReport $loads,
    ) {}

    /**
     * What this viewer sees: a device only the household's notices, an
     * account also the managers' where it manages, minus what it closed.
     *
     * @return list<Notice>
     */
    public function for(int $householdId, User|Device|null $viewer = null): array
    {
        $manages = $viewer instanceof User && $this->abilities->allows($viewer, Permission::HouseholdManage);
        $dismissed = $viewer instanceof User ? ($viewer->membershipIn($householdId)?->dismissed_notices ?? []) : [];

        return array_values(array_filter(
            $this->all($householdId),
            static fn (Notice $notice): bool => match ($notice->audience) {
                NoticeAudience::Household => true,
                NoticeAudience::Managers => $manages,
                NoticeAudience::Operator => $viewer instanceof User && $viewer->is_operator,
            } && ! ($notice->dismissible() && in_array($notice->key, $dismissed, true)),
        ));
    }

    /** The notice that explains a refused write, if any. */
    public function blocking(int $householdId): ?Notice
    {
        foreach ($this->all($householdId) as $notice) {
            if ($notice->level === NoticeLevel::Blocking) {
                return $notice;
            }
        }

        return null;
    }

    /**
     * The server's own state, for the operator in the admin, whatever
     * household they look at.
     *
     * @return list<Notice>
     */
    public function forOperator(): array
    {
        $failed = array_keys($this->loads->failures());

        if ($failed === []) {
            return [];
        }

        return [new Notice(
            __('Some plugins could not be loaded: :list.', ['list' => implode(', ', $failed)]),
            NoticeLevel::Warning,
            '/admin/plugins',
            __('Show plugins'),
            audience: NoticeAudience::Operator,
        )];
    }

    /**
     * Closes a notice for this account. Only keys showing now are kept: one
     * that went away and comes back later is news again.
     */
    public function dismiss(User $user, int $householdId, string $key): bool
    {
        $membership = $user->membershipIn($householdId);
        $current = array_map(static fn (Notice $notice): ?string => $notice->key, array_filter(
            $this->all($householdId),
            static fn (Notice $notice): bool => $notice->dismissible(),
        ));

        if ($membership === null || ! in_array($key, $current, true)) {
            return false;
        }

        $kept = array_values(array_intersect($membership->dismissed_notices ?? [], $current));
        $membership->forceFill(['dismissed_notices' => array_values(array_unique([...$kept, $key]))])->save();

        return true;
    }

    /** @return list<Notice> */
    public function all(int $householdId): array
    {
        $cached = Cache::remember(
            "household-notices:{$householdId}:".app()->getLocale(),
            self::SECONDS,
            fn (): array => array_map(static fn (Notice $notice): array => $notice->toArray(), $this->gather($householdId)),
        );

        return array_map(static fn (array $notice): Notice => Notice::fromArray($notice), $cached);
    }

    public static function forget(int $householdId): void
    {
        foreach (array_keys(Locale::supported()) as $locale) {
            Cache::forget("household-notices:{$householdId}:{$locale}");
        }
    }

    /** @return list<Notice> */
    private function gather(int $householdId): array
    {
        $notices = [...$this->fromPlugins($householdId), ...$this->connections($householdId)];

        $explained = array_filter($notices, static fn (Notice $notice): bool => $notice->level === NoticeLevel::Blocking) !== [];

        if (! $explained && Household::query()->whereKey($householdId)->whereNotNull('read_only_since')->exists()) {
            $notices[] = new Notice(
                __('This household is read-only.').' '.__('Wall and phones still show everything, but nothing can be changed. Whoever runs this server can lift it.'),
                NoticeLevel::Blocking,
                key: 'read-only',
            );
        }

        usort($notices, static fn (Notice $a, Notice $b): int => $b->level->weight() <=> $a->level->weight());

        return $notices;
    }

    /** @return list<Notice> */
    private function fromPlugins(int $householdId): array
    {
        $notices = [];

        foreach ($this->plugins->offered('notices') as $key => $class) {
            try {
                /** @var HouseholdNotice $source */
                $source = app($class);
                $notice = $source->notice($householdId);
            } catch (Throwable $e) {
                Log::warning('Household notice failed', ['plugin' => $key, 'error' => $e->getMessage()]);

                continue;
            }

            if ($notice !== null) {
                $notices[] = $notice;
            }
        }

        return $notices;
    }

    /**
     * A connection whose consent was withdrawn silently delivers nothing
     * more. Without this the wall would look normal and show old events
     * for days.
     *
     * @return list<Notice>
     */
    private function connections(int $householdId): array
    {
        return ProviderAccount::withoutHouseholdScope()
            ->where('household_id', $householdId)
            ->get()
            ->reject(fn (ProviderAccount $account): bool => $account->isActive())
            ->map(function (ProviderAccount $account): Notice {
                try {
                    $provider = $this->registry->forAccount($account);
                    $label = method_exists($provider, 'label') ? (string) $provider->label() : (string) $account->provider;
                } catch (Throwable) {
                    $label = (string) $account->provider;
                }

                $name = $account->account_label === null ? $label : "{$label} ({$account->account_label})";

                return new Notice(
                    __('The connection to :name has expired and delivers no more changes. Connect it again in the administration.', ['name' => $name]),
                    NoticeLevel::Warning,
                    '/admin/connections',
                    __('Connections'),
                    // The time it broke: losing it again later is news again.
                    key: "reconnect:{$account->id}:".($account->updated_at?->getTimestamp() ?? 0),
                );
            })
            ->values()
            ->all();
    }
}

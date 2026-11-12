<?php

declare(strict_types=1);

namespace App\Support;

use App\Integrations\Plugins\PluginManifest;
use Fahoba\PluginApi\Languages\Coverage;
use Fahoba\PluginApi\Languages\LanguagePack;
use Fahoba\PluginApi\Languages\Reference;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Arr;

/**
 * A language pack's texts as Fahoba uses them: without what would show
 * broken, measured against the reference that ships with
 * `fahoba/plugin-api` — the same one a translator's tests use.
 */
#[Singleton]
final class PackTexts
{
    /** @var array{backend: array<string, string>, groups: array<string, array<string, string>>, frontend: array<string, string>}|null */
    private ?array $reference = null;

    public function __construct(
        private readonly Container $container,
        private readonly PluginManifest $manifest,
    ) {}

    /**
     * What Laravel loads for the pack's code: `*` is the JSON file, any
     * other group the PHP file of that name.
     *
     * @return array<string, mixed>
     */
    public function backend(LanguagePack $pack, string $group): array
    {
        $own = Coverage::backendTexts($pack, $group);

        if ($group === '*') {
            foreach (Coverage::broken($this->reference()['backend'], $own, Coverage::BACKEND) as $key) {
                unset($own[$key]);
            }

            return $own;
        }

        Arr::forget($own, Coverage::broken($this->reference()['groups'][$group] ?? [], Coverage::flatten($own), Coverage::BACKEND));

        return $own;
    }

    /**
     * The messages for wall and phone, nested, without broken texts — those
     * fall back to English in the browser.
     *
     * @return array<string, mixed>
     */
    public function frontend(LanguagePack $pack): array
    {
        $own = Coverage::frontendTexts($pack);
        Arr::forget($own, Coverage::broken($this->reference()['frontend'], Coverage::flatten($own), Coverage::FRONTEND));

        return $own;
    }

    /**
     * What wall and phone load for a pack's code: the pack's messages plus
     * every plugin layer's own `<code>.json`, and a hash of the result for
     * an address that may be cached forever.
     *
     * @return array{hash: string, messages: array<string, mixed>}|null
     */
    public function served(string $code): ?array
    {
        $pack = $this->container->make(Languages::class)->pack($code);

        if ($pack === null) {
            return null;
        }

        $messages = $this->frontend($pack);

        foreach ($this->manifest->packages() as $package) {
            if (($package['locales'] ?? null) !== null) {
                $messages = array_replace_recursive($messages, Coverage::read("{$package['locales']}/{$code}.json"));
            }
        }

        $encoded = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return ['hash' => substr(hash('sha256', $encoded), 0, 12), 'messages' => $messages];
    }

    /** @return array{total: int, missing: list<string>, broken: list<string>} */
    public function coverage(LanguagePack $pack): array
    {
        return Coverage::of($pack, $this->reference());
    }

    /** @return array{backend: array<string, string>, groups: array<string, array<string, string>>, frontend: array<string, string>} */
    private function reference(): array
    {
        return $this->reference ??= Reference::load();
    }
}

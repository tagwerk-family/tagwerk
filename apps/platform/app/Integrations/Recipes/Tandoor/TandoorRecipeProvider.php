<?php

declare(strict_types=1);

namespace App\Integrations\Recipes\Tandoor;

use App\Enums\ConnectionStatus;
use App\Enums\ProviderArea;
use App\Models\ProviderAccount;
use App\Support\OutboundHttp;
use Fahoba\PluginApi\Connections\CredentialField;
use Fahoba\PluginApi\Connections\DescribesSetup;
use Fahoba\PluginApi\Connections\UsesCredentials;
use Fahoba\PluginApi\Recipes\RecipeProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Reads recipes from Tandoor.
 *
 * Read-only: the weekly plan belongs to Fahoba. Tandoor does have a meal plan
 * API of its own, but then there would be two answers to "what's for dinner
 * on Monday".
 *
 * Access is a fixed token from the Tandoor settings, not a login flow:
 * Tandoor throttles logins hard (roughly ten attempts per day and address),
 * and a token per call would inevitably run into that.
 *
 * Address and token belong to the household's connection: two families do
 * not share a recipe book. Read on every call, never in the constructor.
 */
final class TandoorRecipeProvider implements DescribesSetup, RecipeProvider, UsesCredentials
{
    public function key(): string
    {
        return 'tandoor';
    }

    public function label(): string
    {
        return 'Tandoor';
    }

    public function isConfigured(): bool
    {
        return $this->url() !== '' && $this->token() !== '';
    }

    public function icon(): string
    {
        return 'heroicon-o-book-open';
    }

    public function description(): string
    {
        return __('Recipes from your Tandoor, for planning and the shopping list.');
    }

    /** @return array<int, string> */
    public function setupNotes(): array
    {
        return [
            __('The token is in Tandoor under Settings → API. It does not expire.'),
            __('Fahoba only reads; the weekly plan stays in Fahoba.'),
        ];
    }

    /** @return array<int, CredentialField> */
    public function credentialFields(): array
    {
        return [
            new CredentialField(
                name: 'url',
                label: __('Address'),
                placeholder: 'https://rezepte.example.org',
                hint: __('The address of your Tandoor installation.'),
            ),
            new CredentialField(
                name: 'token',
                label: 'Token',
                secret: true,
                hint: __('From the Tandoor settings, not your password.'),
            ),
        ];
    }

    /** @param  array<string, string|null>  $credentials */
    public function verifyCredentials(array $credentials): ?string
    {
        $url = rtrim(trim((string) ($credentials['url'] ?? '')), '/');
        $token = trim((string) ($credentials['token'] ?? ''));

        if ($url === '' || $token === '') {
            return __('Address and token are both needed.');
        }

        if (! app(OutboundHttp::class)->isPublic($url)) {
            return __('The address must point to the public internet.');
        }

        try {
            $response = Http::withToken($token)->acceptJson()->timeout(5)->get($url.'/api/recipe/', ['page_size' => 1]);
        } catch (\Throwable) {
            return __('Nothing answers at this address.');
        }

        if ($response->unauthorized() || $response->forbidden()) {
            return __('The address is right, the token is not.');
        }

        return $response->successful() ? null : __('Tandoor answers, but not as expected.');
    }

    /** @param  array<string, string|null>  $credentials */
    public function accountLabel(array $credentials): string
    {
        $host = parse_url(trim((string) ($credentials['url'] ?? '')), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? "Tandoor – {$host}" : 'Tandoor';
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function search(string $term = '', int $limit = 25): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $response = $this->request()->get('/api/recipe/', array_filter([
            'query' => $term === '' ? null : $term,
            'page_size' => $limit,
        ]));

        if ($response->failed()) {
            return [];
        }

        // Tandoor pages its results; without pagination the list would be at the top level.
        $hits = $response->json('results') ?? $response->json() ?? [];

        return array_values(array_filter(array_map(
            fn (mixed $recipe): ?array => is_array($recipe) && isset($recipe['id'], $recipe['name'])
                ? ['id' => (string) $recipe['id'], 'name' => (string) $recipe['name']]
                : null,
            is_array($hits) ? $hits : [],
        )));
    }

    /**
     * A recipe's ingredients, flattened across all steps.
     *
     * @return array<int, array{name: string, spec: string|null}>
     */
    public function ingredients(string $recipeId): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $response = $this->request()->get('/api/recipe/'.rawurlencode($recipeId).'/');

        if ($response->failed()) {
            return [];
        }

        $ingredients = [];

        foreach ($response->json('steps') ?? [] as $step) {
            foreach ($step['ingredients'] ?? [] as $ingredient) {
                $name = $ingredient['food']['name'] ?? null;

                if (! is_string($name) || $name === '') {
                    continue;
                }

                $ingredients[] = ['name' => $name, 'spec' => $this->quantity($ingredient)];
            }
        }

        return $ingredients;
    }

    /**
     * Amount and unit as one text, the way a shopping list needs it.
     *
     * Tandoor keeps both separately and omits both when missing — "to taste"
     * has neither number nor unit.
     *
     * @param  array<string, mixed>  $ingredient
     */
    private function quantity(array $ingredient): ?string
    {
        $amount = $ingredient['amount'] ?? null;
        $unit = $ingredient['unit']['name'] ?? null;

        // Tandoor stores amounts as decimals: 2.0 should become "2", but 0.5
        // stays "0.5".
        $number = is_numeric($amount) && (float) $amount > 0
            ? rtrim(rtrim(number_format((float) $amount, 2, '.', ''), '0'), '.')
            : null;

        $text = trim(implode(' ', array_filter([$number, is_string($unit) ? $unit : null])));

        return $text === '' ? null : $text;
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->token())
            ->acceptJson()
            ->timeout(10)
            ->baseUrl($this->url());
    }

    /** Checked on every use, not only when connecting: DNS can change afterwards. */
    private function url(): string
    {
        $url = rtrim($this->credential('url'), '/');

        return $url !== '' && app(OutboundHttp::class)->isPublic($url) ? $url : '';
    }

    private function token(): string
    {
        return $this->credential('token');
    }

    /** From the connection of the household in context — none, no access. */
    private function credential(string $field): string
    {
        $account = ProviderAccount::query()
            ->forArea(ProviderArea::Recipes)
            ->where('provider', $this->key())
            ->where('status', ConnectionStatus::Active->value)
            ->first();

        $value = $account?->credentials[$field] ?? null;

        return is_string($value) ? trim($value) : '';
    }
}

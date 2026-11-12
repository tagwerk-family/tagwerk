<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConnectionStatus;
use App\Enums\ProviderArea;
use App\Models\Concerns\BelongsToHousehold;
use App\Models\Concerns\HasZonedTimestamps;
use Carbon\CarbonImmutable;
use Database\Factories\ProviderAccountFactory;
use Fahoba\PluginApi\Connections\ConnectedAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A connection to a provider, for exactly one area.
 *
 * The same account may appear several times — once for the calendar, once
 * for the lists. That is intended: they are two grants with different
 * scopes, granted and revoked separately.
 *
 * Tokens are stored encrypted; the application key is therefore also the key
 * to the connected services.
 */
final class ProviderAccount extends Model implements ConnectedAccount
{
    /** @use HasFactory<ProviderAccountFactory> */
    use BelongsToHousehold, HasFactory, HasZonedTimestamps;

    protected $fillable = [
        'household_id', 'area', 'provider', 'user_id', 'account_label',
        'access_token', 'refresh_token', 'token_expires_at', 'status',
        'credentials', 'settings',
    ];

    protected $hidden = ['access_token', 'refresh_token', 'credentials'];

    /**
     * The default lives here and not only in the database: otherwise a
     * freshly created connection would not know its area, because Eloquent
     * does not read column defaults back.
     *
     * There is deliberately no default provider: whoever creates a connection
     * names it — otherwise a forgotten value would silently end up at Google.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'area' => ProviderArea::Calendar->value,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'area' => ProviderArea::class,
            'settings' => 'array',
            'status' => ConnectionStatus::class,
            'credentials' => 'encrypted:array',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<Calendar, $this> */
    public function calendars(): HasMany
    {
        return $this->hasMany(Calendar::class);
    }

    /** Lists this account mirrors to its service. @return HasMany<Checklist, $this> */
    public function checklists(): HasMany
    {
        return $this->hasMany(Checklist::class, 'list_account_id');
    }

    /** Tasks fetched from this account. @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'task_account_id');
    }

    /** Folder pictures whose date is known. @return HasMany<PhotoDate, $this> */
    public function photoDates(): HasMany
    {
        return $this->hasMany(PhotoDate::class);
    }

    /**
     * @param  Builder<ProviderAccount>  $query
     */
    public function scopeForArea(Builder $query, ProviderArea $area): void
    {
        $query->where('area', $area->value);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** For core code that needs the row behind a provider's account. */
    public static function of(ConnectedAccount $account): self
    {
        return $account instanceof self
            ? $account
            : throw new LogicException('Expected a ProviderAccount, got '.$account::class.'.');
    }

    public function accountId(): int
    {
        return (int) $this->getKey();
    }

    public function householdId(): int
    {
        return (int) $this->household_id;
    }

    public function areaKey(): string
    {
        return $this->area->value;
    }

    public function providerKey(): string
    {
        return (string) $this->provider;
    }

    public function credentials(): array
    {
        return (array) ($this->credentials ?? []);
    }

    public function settings(): array
    {
        return (array) ($this->settings ?? []);
    }

    public function accessToken(): ?string
    {
        return $this->access_token;
    }

    public function refreshToken(): ?string
    {
        return $this->refresh_token;
    }

    public function tokenExpiresAt(): ?CarbonImmutable
    {
        return $this->token_expires_at;
    }

    public function storeSettings(array $settings): void
    {
        $this->forceFill(['settings' => $settings])->save();
    }

    public function storeToken(?string $accessToken, ?CarbonImmutable $expiresAt, ?string $refreshToken = null): void
    {
        $this->forceFill(array_filter([
            'access_token' => $accessToken,
            'token_expires_at' => $expiresAt,
            'refresh_token' => $refreshToken,
        ], fn (mixed $value, string $key): bool => $key !== 'refresh_token' || $value !== null, ARRAY_FILTER_USE_BOTH))->save();
    }

    public function markInvalid(): void
    {
        $this->forceFill([
            'status' => ConnectionStatus::InvalidGrant,
            'access_token' => null,
            'token_expires_at' => null,
        ])->save();
    }

    public function isActive(): bool
    {
        return $this->status === ConnectionStatus::Active;
    }

    /**
     * One minute of margin: a token expiring during a running request would
     * otherwise only show up as a 401.
     */
    public function accessTokenHasExpired(): bool
    {
        return $this->access_token === null
            || $this->token_expires_at === null
            || $this->token_expires_at->subMinute()->isPast();
    }
}

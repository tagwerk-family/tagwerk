<?php

declare(strict_types=1);

namespace Fahoba\SocialLogin;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An account's sign-in at a provider. No household of its own: it belongs to
 * the account, like its Sanctum tokens.
 *
 * @property int $user_id
 * @property string $provider
 * @property string $subject
 * @property string|null $email
 */
final class SocialIdentity extends Model
{
    protected $fillable = ['user_id', 'provider', 'subject', 'email'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

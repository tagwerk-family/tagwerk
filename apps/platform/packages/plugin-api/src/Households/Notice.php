<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Households;

use InvalidArgumentException;

/**
 * A sentence for one household — wall, phone and admin show it. The text
 * is already in the request's language; an optional link (`https://` or a
 * path on this server) leads to whatever resolves it.
 *
 * A `key` names the state, not the wording ("trial-ending", "reconnect:12"):
 * an account that closes the notice does not see that key again, whatever
 * the text says later. Without a key, or when blocking, it cannot be closed.
 */
final readonly class Notice
{
    public function __construct(
        public string $text,
        public NoticeLevel $level = NoticeLevel::Info,
        public ?string $url = null,
        public ?string $action = null,
        public ?string $key = null,
        public NoticeAudience $audience = NoticeAudience::Household,
    ) {
        if ($text === '') {
            throw new InvalidArgumentException('A notice needs a text.');
        }

        if ($url !== null && ! str_starts_with($url, 'https://') && ! str_starts_with($url, '/')) {
            throw new InvalidArgumentException('A notice links to https:// or a path on this server.');
        }

        if ($key !== null && preg_match('/^[a-z0-9][a-z0-9:._\/-]{0,99}$/', $key) !== 1) {
            throw new InvalidArgumentException('A notice key is lower-case letters, digits and :._/- (at most 100).');
        }
    }

    /** Whether a viewer may close it for good. */
    public function dismissible(): bool
    {
        return $this->key !== null && $this->level !== NoticeLevel::Blocking;
    }

    /** @return array{text: string, level: string, url: ?string, action: ?string, key: ?string, audience: string, dismissible: bool} */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'level' => $this->level->value,
            'url' => $this->url,
            'action' => $this->url === null ? null : $this->action,
            'key' => $this->key,
            'audience' => $this->audience->value,
            'dismissible' => $this->dismissible(),
        ];
    }

    /** @param array{text: string, level: string, url?: ?string, action?: ?string, key?: ?string, audience?: string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['text'],
            NoticeLevel::from($data['level']),
            $data['url'] ?? null,
            $data['action'] ?? null,
            $data['key'] ?? null,
            NoticeAudience::from($data['audience'] ?? NoticeAudience::Household->value),
        );
    }
}

<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Dashboard;

/**
 * One field of a widget's settings form.
 *
 * The tile editor draws the form; the server checks what comes back against
 * the fields before it stores anything. A secret (an API key) is stored
 * encrypted and never sent back to a device — only whether it is set.
 */
final readonly class SettingField
{
    /** @param  array<string, string>  $options  value => label, for `select` */
    private function __construct(
        public string $kind,
        public string $key,
        public string $label,
        public bool $required,
        public array $options = [],
    ) {}

    public static function text(string $key, string $label, bool $required = false): self
    {
        return new self('text', $key, $label, $required);
    }

    public static function number(string $key, string $label, bool $required = false): self
    {
        return new self('number', $key, $label, $required);
    }

    /** @param  array<string, string>  $options  value => label */
    public static function select(string $key, string $label, array $options, bool $required = false): self
    {
        return new self('select', $key, $label, $required, $options);
    }

    public static function toggle(string $key, string $label): self
    {
        return new self('toggle', $key, $label, false);
    }

    public static function secret(string $key, string $label, bool $required = false): self
    {
        return new self('secret', $key, $label, $required);
    }

    /** @return array{kind: string, key: string, label: string, required: bool, options: list<array{value: string, label: string}>} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'key' => $this->key,
            'label' => $this->label,
            'required' => $this->required,
            'options' => array_map(
                static fn (string|int $value, string $label): array => ['value' => (string) $value, 'label' => $label],
                array_keys($this->options),
                $this->options,
            ),
        ];
    }
}

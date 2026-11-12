<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Dashboard;

use InvalidArgumentException;

/**
 * What a data widget shows: a metric, a list, an image, a table, a
 * progress bar, label/value pairs or buttons.
 *
 * Shapes, never HTML: the wall renders text as text, so a plugin cannot
 * inject anything, and the tile keeps the wall's look.
 */
final readonly class WidgetContent
{
    private const MAX_ROWS = 20;

    private const MAX_ACTIONS = 6;

    private const ACTION_NAME = '/^[a-z0-9_-]{1,40}$/';

    /** @param  array<string, mixed>  $data */
    private function __construct(
        public string $kind,
        public array $data,
    ) {}

    public static function metric(string $value, ?string $caption = null): self
    {
        return new self('metric', ['value' => $value, 'caption' => $caption]);
    }

    /**
     * @param  array<int, array{text: string, detail?: string|null}>  $rows
     */
    public static function list(array $rows): self
    {
        $normalized = array_map(static fn (array $row): array => [
            'text' => (string) $row['text'],
            'detail' => isset($row['detail']) ? (string) $row['detail'] : null,
        ], array_slice(array_values($rows), 0, self::MAX_ROWS));

        return new self('list', ['rows' => $normalized]);
    }

    /**
     * Only URLs the wall's browser would load: a path on this server or an
     * `https://` host a plugin declared (ContributesToCsp). Anything else —
     * `javascript:`, `file:`, an undeclared host — fails here, not as a
     * broken image on the wall. Without a policy bound (a plugin's own unit
     * test) only paths pass.
     */
    public static function image(string $url, ?string $caption = null): self
    {
        $allowed = app()->bound(ImagePolicy::class)
            ? app(ImagePolicy::class)->allowsImage($url)
            : str_starts_with($url, '/') && ! str_starts_with($url, '//');

        if (! $allowed) {
            throw new InvalidArgumentException("Image URL {$url} is neither a local path nor a declared https host.");
        }

        return new self('image', ['url' => $url, 'caption' => $caption]);
    }

    /**
     * Rows cut or padded to the header's width.
     *
     * @param  list<string>  $header
     * @param  array<int, array<int, string|int|float|null>>  $rows
     */
    public static function table(array $header, array $rows): self
    {
        $header = array_map('strval', array_values($header));

        if ($header === []) {
            throw new InvalidArgumentException('A table needs at least one column.');
        }

        $width = count($header);
        $normalized = array_map(
            static fn (array $row): array => array_pad(array_map(
                static fn (mixed $cell): string => (string) $cell,
                array_slice(array_values($row), 0, $width),
            ), $width, ''),
            array_slice(array_values($rows), 0, self::MAX_ROWS),
        );

        return new self('table', ['header' => $header, 'rows' => $normalized]);
    }

    /** A bar from 0 to `max`; the value is held inside it. */
    public static function progress(float $value, float $max, ?string $caption = null): self
    {
        if ($max <= 0) {
            throw new InvalidArgumentException('The maximum of a progress bar must be above zero.');
        }

        return new self('progress', ['value' => max(0.0, min($value, $max)), 'max' => $max, 'caption' => $caption]);
    }

    /** @param  array<int, array{label: string, value: string|int|float}>  $rows */
    public static function pairs(array $rows): self
    {
        $normalized = array_map(static fn (array $row): array => [
            'label' => (string) $row['label'],
            'value' => (string) $row['value'],
        ], array_slice(array_values($rows), 0, self::MAX_ROWS));

        return new self('pairs', ['rows' => $normalized]);
    }

    /**
     * Buttons. A tap sends the button's name to the widget's
     * `HandlesActions::act()`, whose answer replaces the tile's content.
     *
     * @param  array<int, array{name: string, label: string}>  $buttons
     */
    public static function actions(array $buttons, ?string $caption = null): self
    {
        $normalized = [];

        foreach (array_slice(array_values($buttons), 0, self::MAX_ACTIONS) as $button) {
            $name = (string) $button['name'];

            if (preg_match(self::ACTION_NAME, $name) !== 1) {
                throw new InvalidArgumentException("Action name {$name} may hold only a-z, 0-9, - and _.");
            }

            $normalized[] = ['name' => $name, 'label' => (string) $button['label']];
        }

        return new self('actions', ['buttons' => $normalized, 'caption' => $caption]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['kind' => $this->kind, ...$this->data];
    }
}

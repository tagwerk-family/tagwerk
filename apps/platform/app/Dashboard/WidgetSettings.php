<?php

declare(strict_types=1);

namespace App\Dashboard;

use Closure;
use Fahoba\PluginApi\Dashboard\SettingField;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A plugin tile's settings, held to the widget's fields.
 *
 * The device sends a form; what is stored is only what the fields know, in
 * their type. Secrets are encrypted at rest, go back to a device only as
 * “set or not”, and reach the widget decrypted.
 */
final class WidgetSettings
{
    private const MAX_TEXT = 500;

    /**
     * @param  list<SettingField>  $fields
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validated(array $fields, array $input, array $stored): array
    {
        $rules = [];

        foreach ($fields as $field) {
            // A secret already stored stays when the form leaves it empty.
            $kept = $field->kind === 'secret' && isset($stored[$field->key]);
            $presence = $field->required && ! $kept ? 'required' : 'nullable';

            $rules["settings.{$field->key}"] = match ($field->kind) {
                // 1e400 is numeric but becomes INF, which JSON cannot carry.
                'number' => [$presence, 'numeric', static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_numeric($value) && ! is_finite((float) $value)) {
                        $fail(__('The number is too large.'));
                    }
                }],
                'select' => [$presence, 'string', Rule::in(array_map('strval', array_keys($field->options)))],
                'toggle' => ['nullable', 'boolean'],
                default => [$presence, 'string', 'max:'.self::MAX_TEXT],
            };
        }

        Validator::make(['settings' => $input], $rules)->validate();

        $settings = [];

        foreach ($fields as $field) {
            $value = $input[$field->key] ?? null;

            $settings[$field->key] = match ($field->kind) {
                'number' => $value === null || $value === '' ? null : $value + 0,
                'toggle' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'secret' => $value === null || $value === '' ? ($stored[$field->key] ?? null) : Crypt::encryptString((string) $value),
                default => $value === '' ? null : $value,
            };
        }

        return $settings;
    }

    /**
     * What a device sees: secrets as true or false.
     *
     * @param  list<SettingField>  $fields
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public function shown(array $fields, array $stored): array
    {
        foreach ($fields as $field) {
            if ($field->kind === 'secret') {
                $stored[$field->key] = isset($stored[$field->key]);
            }
        }

        return $stored;
    }

    /**
     * What the widget receives: secrets decrypted.
     *
     * @param  list<SettingField>  $fields
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public function plain(array $fields, array $stored): array
    {
        foreach ($fields as $field) {
            if ($field->kind === 'secret' && is_string($stored[$field->key] ?? null)) {
                try {
                    $stored[$field->key] = Crypt::decryptString($stored[$field->key]);
                } catch (DecryptException) {
                    // A new APP_KEY: the secret is gone, the form asks again.
                    $stored[$field->key] = null;
                }
            }
        }

        return $stored;
    }
}

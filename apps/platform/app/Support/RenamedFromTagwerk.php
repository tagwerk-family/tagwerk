<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Stored values from before the rename to Fahoba: the bundled plugins' keys
 * were `tagwerk/…` (a connected Bring list, a Koch-Wiki favourite, a design,
 * an extension's settings), and the built-in calendar's id was `tagwerk`.
 * `BuiltinCalendar` finds that calendar by its id; under the old one it would
 * add a second family calendar.
 *
 * The migration of that release renames what a database holds; this renames
 * the rows of an export written before, which both imports still accept.
 */
final class RenamedFromTagwerk
{
    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function row(string $table, array $row): array
    {
        foreach ($row as $column => $value) {
            if (! is_string($value)) {
                continue;
            }

            if (str_starts_with($value, 'tagwerk/')) {
                $row[$column] = 'fahoba/'.substr($value, 8);
            } elseif (str_starts_with($value, '[') || str_starts_with($value, '{')) {
                // json_encode() escapes the slash unless told not to.
                $row[$column] = str_replace(['"tagwerk/', '"tagwerk\\/'], ['"fahoba/', '"fahoba\\/'], $value);
            }
        }

        if ($table === 'calendars' && ($row['external_id'] ?? null) === 'tagwerk') {
            $row['external_id'] = 'fahoba';
        }

        // The built-in account's label is the name shown to the family: Tagwerk
        // until 0.3.0, Fahoba in 0.3.0.
        if ($table === 'provider_accounts' && in_array($row['account_label'] ?? null, ['Tagwerk', 'Fahoba'], true)) {
            $row['account_label'] = 'Family Home Base';
        }

        return $row;
    }
}

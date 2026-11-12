<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Recipes;

/**
 * One line of a recipe's ingredient list — amount, unit, name, note.
 *
 * Amount and unit are fields of their own so that portions can be scaled and
 * a week's shopping summed ("200 g Mehl" and "300 g Mehl" are "500 g Mehl").
 * What does not parse stays text: "a handful" is a note, not a number.
 */
final readonly class Ingredient
{
    /**
     * Spellings of a unit, lower case, to the one used for summing. Units
     * not listed stay as written — they are still summed with themselves.
     */
    private const UNITS = [
        'g' => 'g', 'gr' => 'g', 'gr.' => 'g', 'gramm' => 'g',
        'kg' => 'kg', 'kilogramm' => 'kg', 'kilo' => 'kg',
        'mg' => 'mg',
        'ml' => 'ml', 'milliliter' => 'ml',
        'cl' => 'cl', 'dl' => 'dl',
        'l' => 'l', 'liter' => 'l', 'ltr' => 'l', 'ltr.' => 'l',
        'el' => 'EL', 'el.' => 'EL', 'essl.' => 'EL', 'esslöffel' => 'EL',
        'tl' => 'TL', 'tl.' => 'TL', 'teel.' => 'TL', 'teelöffel' => 'TL',
        'msp' => 'Msp.', 'msp.' => 'Msp.', 'messerspitze' => 'Msp.', 'messerspitzen' => 'Msp.',
        'prise' => 'Prise', 'prisen' => 'Prise', 'pr.' => 'Prise',
        'stück' => 'Stück', 'stk' => 'Stück', 'stk.' => 'Stück', 'st.' => 'Stück',
        'pkg' => 'Pkg.', 'pkg.' => 'Pkg.', 'pck' => 'Pkg.', 'pck.' => 'Pkg.', 'pk.' => 'Pkg.',
        'packung' => 'Pkg.', 'packungen' => 'Pkg.', 'päckchen' => 'Pkg.', 'pkt.' => 'Pkg.',
        'bund' => 'Bund', 'bd.' => 'Bund',
        'dose' => 'Dose', 'dosen' => 'Dose',
        'becher' => 'Becher',
        'tasse' => 'Tasse', 'tassen' => 'Tasse',
        'glas' => 'Glas', 'gläser' => 'Glas',
        'scheibe' => 'Scheibe', 'scheiben' => 'Scheibe',
        'zehe' => 'Zehe', 'zehen' => 'Zehe',
        'blatt' => 'Blatt', 'blätter' => 'Blatt',
        'zweig' => 'Zweig', 'zweige' => 'Zweig',
        'stange' => 'Stange', 'stangen' => 'Stange',
        'knolle' => 'Knolle', 'knollen' => 'Knolle',
        'kopf' => 'Kopf', 'köpfe' => 'Kopf',
        'würfel' => 'Würfel',
        'tafel' => 'Tafel', 'tafeln' => 'Tafel',
        'beutel' => 'Beutel',
        'handvoll' => 'Handvoll',
        'spritzer' => 'Spritzer',
        'schuss' => 'Schuss',
        'cm' => 'cm',
        'cup' => 'cup', 'cups' => 'cup',
        'tbsp' => 'tbsp', 'tsp' => 'tsp', 'oz' => 'oz', 'lb' => 'lb',
        'pinch' => 'pinch', 'clove' => 'clove', 'cloves' => 'clove',
    ];

    private const FRACTIONS = ['½' => 0.5, '¼' => 0.25, '¾' => 0.75, '⅓' => 1 / 3, '⅔' => 2 / 3, '⅛' => 0.125];

    private const NUMBER = '(?:\d+\s+\d+\s*/\s*\d+|\d+\s*/\s*\d+|\d+\s*[½¼¾⅓⅔⅛]|[½¼¾⅓⅔⅛]|\d+(?:[.,]\d+)?)';

    public function __construct(
        public string $name,
        public ?float $amount = null,
        public ?float $amountMax = null,
        public ?string $unit = null,
        public ?string $note = null,
        public ?string $group = null,
    ) {}

    /** "200 g Mehl (glatt)", "2-3 Eier", "Salz, nach Geschmack". */
    public static function parse(string $line, ?string $group = null): ?self
    {
        $line = trim((string) preg_replace('/^[*#\-•]+\s*/u', '', trim($line)));

        if ($line === '') {
            return null;
        }

        [$amount, $max, $unit, $rest] = self::quantity($line);
        $note = null;

        if (preg_match('/^(.*?)\s*\((.+)\)\s*(.*)$/u', $rest, $match) === 1 && $match[1] !== '') {
            $rest = trim($match[1].' '.$match[3]);
            $note = trim($match[2]);
        }

        if (str_contains($rest, ',')) {
            [$rest, $more] = array_map(trim(...), explode(',', $rest, 2));
            $note = trim(implode(', ', array_filter([$note, $more])));
        }

        if ($rest === '') {
            return $note === null ? null : new self($note, $amount, $max, $unit, null, $group);
        }

        return new self($rest, $amount, $max, $unit, $note === '' ? null : $note, $group);
    }

    /**
     * A typed list, one ingredient per line. A line ending in a colon heads
     * the lines below it ("Für den Teig:").
     *
     * @return list<self>
     */
    public static function parseList(string $text): array
    {
        $list = [];
        $group = null;

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);

            if (str_ends_with($line, ':') && mb_strlen($line) > 1) {
                $group = trim(mb_substr($line, 0, -1));

                continue;
            }

            $ingredient = self::parse($line, $group);

            if ($ingredient !== null) {
                $list[] = $ingredient;
            }
        }

        return $list;
    }

    /**
     * The other way round, for the edit form.
     *
     * @param  list<self>  $list
     */
    public static function listText(array $list): string
    {
        $lines = [];
        $group = null;

        foreach ($list as $ingredient) {
            if ($ingredient->group !== null && $ingredient->group !== $group) {
                $lines[] = $ingredient->group.':';
            }

            $group = $ingredient->group;
            $lines[] = trim(($ingredient->spec(round: false) ?? '').' '.$ingredient->name.($ingredient->note === null ? '' : ', '.$ingredient->note));
        }

        return implode("\n", $lines);
    }

    /**
     * From storage. Also reads the old `{name, spec}` shape: a recipe saved
     * by the previous release must not lose its amounts.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $name = trim((string) ($data['name'] ?? ''));
        $group = self::text($data['group'] ?? null);

        if (array_key_exists('spec', $data) && ! array_key_exists('amount', $data)) {
            [$amount, $max, $unit, $rest] = self::quantity(trim((string) $data['spec']));

            return new self($name, $amount, $max, $unit, $rest === '' ? null : $rest, $group);
        }

        return new self(
            $name,
            is_numeric($data['amount'] ?? null) ? (float) $data['amount'] : null,
            is_numeric($data['amount_max'] ?? null) ? (float) $data['amount_max'] : null,
            self::text($data['unit'] ?? null),
            self::text($data['note'] ?? null),
            $group,
        );
    }

    /** @return array{amount: float|null, amount_max: float|null, unit: string|null, name: string, note: string|null, group: string|null} */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'amount_max' => $this->amountMax,
            'unit' => $this->unit,
            'name' => $this->name,
            'note' => $this->note,
            'group' => $this->group,
        ];
    }

    public function scaled(float $factor): self
    {
        return new self(
            $this->name,
            $this->amount === null ? null : $this->amount * $factor,
            $this->amountMax === null ? null : $this->amountMax * $factor,
            $this->unit,
            $this->note,
            $this->group,
        );
    }

    /** Amount and unit as one text — what lands next to the name on a list. */
    public function spec(bool $round = true): ?string
    {
        $amount = $this->amount === null ? null : self::format($this->amount, $round)
            .($this->amountMax === null ? '' : '–'.self::format($this->amountMax, $round));

        $spec = trim(implode(' ', array_filter([$amount, $this->unit])));

        return $spec === '' ? null : $spec;
    }

    /**
     * "1 ½", "250", "0,3" — quarters and thirds as fractions, large amounts
     * rounded unless `$round` is off (the edit form keeps what was typed).
     */
    public static function format(float $value, bool $round = true): string
    {
        if (! $round && $value >= 10) {
            $text = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

            return app()->getLocale() === 'de' ? str_replace('.', ',', $text) : $text;
        }

        if ($value >= 100) {
            return (string) (int) (round($value / 5) * 5);
        }

        if ($value >= 10) {
            return (string) (int) round($value);
        }

        $whole = (int) floor($value);
        $rest = $value - $whole;

        if ($rest < 0.05) {
            return (string) $whole;
        }

        if ($rest > 0.95) {
            return (string) ($whole + 1);
        }

        foreach (self::FRACTIONS as $glyph => $fraction) {
            // An eighth is read, never written: 0.1 l would become "⅛ l".
            if ($glyph !== '⅛' && abs($rest - $fraction) < 0.04) {
                return $whole === 0 ? $glyph : $whole.' '.$glyph;
            }
        }

        $text = number_format($value, 1, '.', '');

        return app()->getLocale() === 'de' ? str_replace('.', ',', $text) : $text;
    }

    /**
     * Leading amount, range and unit of a line.
     *
     * @return array{0: float|null, 1: float|null, 2: string|null, 3: string}
     */
    private static function quantity(string $line): array
    {
        $pattern = '~^(?:ca\.?\s+|etwa\s+)?('.self::NUMBER.')(?:\s*(?:-|–|bis)\s*('.self::NUMBER.'))?\s*~iu';

        if (preg_match($pattern, $line, $match) !== 1) {
            return [null, null, null, $line];
        }

        $amount = self::number($match[1]);
        $max = isset($match[2]) && $match[2] !== '' ? self::number($match[2]) : null;
        $rest = mb_substr($line, mb_strlen($match[0]));
        $unit = null;

        if (preg_match('/^(\S+)(?:\s+|$)/u', $rest, $word) === 1) {
            $key = mb_strtolower($word[1]);

            if (isset(self::UNITS[$key])) {
                $unit = self::UNITS[$key];
                $rest = mb_substr($rest, mb_strlen($word[0]));
            }
        }

        return [$amount, $max, $unit, trim($rest)];
    }

    private static function number(string $text): float
    {
        $text = trim($text);

        if (isset(self::FRACTIONS[$text])) {
            return self::FRACTIONS[$text];
        }

        if (preg_match('/^(\d+)\s*([½¼¾⅓⅔⅛])$/u', $text, $match) === 1) {
            return (float) $match[1] + self::FRACTIONS[$match[2]];
        }

        if (preg_match('~^(?:(\d+)\s+)?(\d+)\s*/\s*(\d+)$~', $text, $match) === 1) {
            return (float) ($match[1] !== '' ? $match[1] : 0) + ((int) $match[3] === 0 ? 0 : (int) $match[2] / (int) $match[3]);
        }

        return (float) str_replace(',', '.', $text);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

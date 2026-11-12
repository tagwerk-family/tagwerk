<?php

declare(strict_types=1);

namespace Tests\Unit;

use Fahoba\PluginApi\Recipes\Ingredient;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class IngredientTest extends TestCase
{
    /** @return array<string, array{string, float|null, float|null, string|null, string, string|null}> */
    public static function lines(): array
    {
        return [
            'grams' => ['200 g Mehl', 200.0, null, 'g', 'Mehl', null],
            'glued unit' => ['200g Mehl', 200.0, null, 'g', 'Mehl', null],
            'fraction' => ['1/2 TL Salz', 0.5, null, 'TL', 'Salz', null],
            'mixed fraction' => ['1 ½ l Milch', 1.5, null, 'l', 'Milch', null],
            'decimal comma' => ['1,5 kg Erdäpfel', 1.5, null, 'kg', 'Erdäpfel', null],
            'range' => ['2-3 Eier', 2.0, 3.0, null, 'Eier', null],
            'spelled unit' => ['3 Esslöffel Öl', 3.0, null, 'EL', 'Öl', null],
            'note after comma' => ['80 g Pecorino, gerieben', 80.0, null, 'g', 'Pecorino', 'gerieben'],
            'note in brackets' => ['500 g Mehl (glatt)', 500.0, null, 'g', 'Mehl', 'glatt'],
            'no amount' => ['Salz und Pfeffer', null, null, null, 'Salz und Pfeffer', null],
            'bullet' => ['* 1 Prise Salz', 1.0, null, 'Prise', 'Salz', null],
            'piece without unit' => ['1 Zwiebel', 1.0, null, null, 'Zwiebel', null],
        ];
    }

    #[DataProvider('lines')]
    public function test_a_line_is_parsed(string $line, ?float $amount, ?float $max, ?string $unit, string $name, ?string $note): void
    {
        $ingredient = Ingredient::parse($line);

        $this->assertNotNull($ingredient);
        $this->assertSame($amount, $ingredient->amount);
        $this->assertSame($max, $ingredient->amountMax);
        $this->assertSame($unit, $ingredient->unit);
        $this->assertSame($name, $ingredient->name);
        $this->assertSame($note, $ingredient->note);
    }

    public function test_an_empty_line_is_nothing(): void
    {
        $this->assertNull(Ingredient::parse('  - '));
    }

    /** A recipe saved by the previous release keeps its amounts. */
    public function test_the_old_shape_is_read(): void
    {
        $ingredient = Ingredient::fromArray(['name' => 'Mehl', 'spec' => '500 g']);

        $this->assertSame(500.0, $ingredient->amount);
        $this->assertSame('g', $ingredient->unit);
        $this->assertSame('500 g', $ingredient->spec());

        $handful = Ingredient::fromArray(['name' => 'Petersilie', 'spec' => 'eine Handvoll']);

        $this->assertNull($handful->amount);
        $this->assertSame('eine Handvoll', $handful->note);
    }

    public function test_scaling_formats_sensibly(): void
    {
        app()->setLocale('de');

        $this->assertSame('1 ½ TL', Ingredient::parse('1 TL Salz')?->scaled(1.5)->spec());
        $this->assertSame('⅓ l', Ingredient::parse('1 l Milch')?->scaled(1 / 3)->spec());
        $this->assertSame('375 g', Ingredient::parse('250 g Mehl')?->scaled(1.5)->spec());
        $this->assertSame('3–4 ½', Ingredient::parse('2-3 Eier')?->scaled(1.5)->spec());
        $this->assertSame('0,1 l', Ingredient::parse('1 l Milch')?->scaled(0.1)->spec());
        $this->assertNull(Ingredient::parse('Pfeffer')?->spec());
    }
}

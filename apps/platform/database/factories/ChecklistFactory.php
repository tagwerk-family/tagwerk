<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ListKind;
use App\Models\Checklist;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Checklist> */
final class ChecklistFactory extends Factory
{
    protected $model = Checklist::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => fake()->randomElement(['Einkauf', 'Baumarkt', 'Urlaub', 'Projekte']),
            'kind' => ListKind::Generic,
            'position' => 0,
        ];
    }

    public function shopping(): self
    {
        return $this->state(fn (): array => [
            'kind' => ListKind::Shopping,
            'list_provider' => 'fahoba/bring',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Household;
use App\Services\Recipes\RecipeImport;
use App\Support\HouseholdContext;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * A collection into a household's recipe book — for larger ones than an
 * upload in the admin carries comfortably.
 */
final class ImportRecipesCommand extends Command
{
    protected $signature = 'fahoba:import-recipes {household : Household id} {file : A ZIP from fahoba:export-recipes or the admin}';

    protected $description = 'Imports a recipe collection into a household; known titles are skipped';

    public function handle(RecipeImport $import, HouseholdContext $context): int
    {
        $household = Household::query()->find((int) $this->argument('household'));
        $file = (string) $this->argument('file');

        if ($household === null || ! is_file($file)) {
            $this->error($household === null ? 'No such household.' : 'No such file.');

            return self::FAILURE;
        }

        try {
            $result = $context->forId((int) $household->id, fn (): array => $import->import($file));
        } catch (ValidationException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->info("Imported {$result['imported']} recipes, skipped {$result['skipped']}.");

        return self::SUCCESS;
    }
}

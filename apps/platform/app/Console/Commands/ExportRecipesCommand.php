<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Household;
use App\Services\Recipes\RecipeImport;
use App\Support\HouseholdContext;
use Illuminate\Console\Command;

/**
 * A household's own recipes as a collection — the same ZIP the admin offers.
 */
final class ExportRecipesCommand extends Command
{
    protected $signature = 'fahoba:export-recipes {household : Household id} {file : Path of the ZIP to write}';

    protected $description = 'Writes a household\'s own recipes with photos into a ZIP';

    public function handle(RecipeImport $import, HouseholdContext $context): int
    {
        $household = Household::query()->find((int) $this->argument('household'));

        if ($household === null) {
            $this->error('No such household.');

            return self::FAILURE;
        }

        $path = $context->forId((int) $household->id, $import->export(...));
        rename($path, (string) $this->argument('file'));
        $this->info('Written to '.$this->argument('file').'.');

        return self::SUCCESS;
    }
}

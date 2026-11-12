<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\DesignReference;
use Fahoba\PluginApi\Themes\Tokens;
use Illuminate\Console\Command;

/**
 * Writes the design tokens themes may set into `fahoba/plugin-api`. Run
 * it after adding or changing a token in `fahoba.css`.
 */
final class DesignReferenceCommand extends Command
{
    protected $signature = 'fahoba:design-reference';

    protected $description = 'Writes the design tokens themes may set into the plugin API package';

    public function handle(): int
    {
        file_put_contents(Tokens::path(), DesignReference::build());

        $this->info('Design reference written to '.Tokens::path().'.');

        return self::SUCCESS;
    }
}

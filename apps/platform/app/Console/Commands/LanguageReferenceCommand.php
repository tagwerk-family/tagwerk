<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\LanguageReference;
use Fahoba\PluginApi\Languages\Reference;
use Illuminate\Console\Command;

/**
 * Writes the English reference that language packs are measured against
 * into `fahoba/plugin-api`. Run it after adding or changing texts.
 */
final class LanguageReferenceCommand extends Command
{
    protected $signature = 'fahoba:language-reference';

    protected $description = 'Writes the reference texts language packs translate into the plugin API package';

    public function handle(): int
    {
        $path = Reference::path();

        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        foreach (LanguageReference::build() as $file => $content) {
            file_put_contents("{$path}/{$file}", $content);
        }

        $this->info("Reference written to {$path}.");

        return self::SUCCESS;
    }
}

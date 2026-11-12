<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Security\ContentSecurityPolicy;
use Illuminate\Console\Command;

/**
 * Prints the app's policy as an nginx line. The container's entrypoint
 * writes it to /etc/nginx/csp.conf before nginx starts, so the static app
 * gets the same policy, plugins included, as the Laravel side.
 */
final class CspCommand extends Command
{
    protected $signature = 'fahoba:csp';

    protected $description = 'Prints the Content Security Policy of the app as an nginx add_header line';

    public function handle(ContentSecurityPolicy $policy): int
    {
        $this->line($policy->mode() === 'off'
            ? '# Content Security Policy switched off (FAHOBA_CSP=off).'
            : sprintf('add_header %s "%s" always;', $policy->headerName(), $policy->forApp()));

        return self::SUCCESS;
    }
}

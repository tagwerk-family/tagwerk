<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Household;
use App\Services\HouseholdExport;
use Illuminate\Console\Command;

/**
 * One household as JSON, to look at it in a local copy
 * (`deploy/pull-household.sh`). Without --output it goes to stdout, so it can
 * travel through ssh without a file left on the server.
 */
final class ExportHouseholdCommand extends Command
{
    protected $signature = 'fahoba:export-household
        {household : Id of the household}
        {--output= : File to write; stdout without}
        {--with-secrets : Passwords (connections, webhooks, tiles) in plain text, for a move to another server}';

    protected $description = 'Exports one household as JSON for a local copy';

    public function handle(HouseholdExport $export): int
    {
        $household = Household::query()->find((int) $this->argument('household'));

        if ($household === null) {
            $this->error('No such household.');

            return self::FAILURE;
        }

        $secrets = (bool) $this->option('with-secrets');

        if ($secrets) {
            // stderr: stdout may be the export itself, streaming through ssh.
            fwrite(STDERR, "WARNING: the export contains passwords and keys of connections, webhooks and tiles in plain text. Do not forward it; delete it after the move.\n");
        }

        $json = json_encode($export->export($household, $secrets), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $output = $this->option('output');

        if (is_string($output) && $output !== '') {
            file_put_contents($output, $json);
            $this->info("Household {$household->id} written to {$output}.");

            return self::SUCCESS;
        }

        // Raw, not through the console's formatter: tags in the data would be read as styles.
        fwrite(STDOUT, $json);

        return self::SUCCESS;
    }
}

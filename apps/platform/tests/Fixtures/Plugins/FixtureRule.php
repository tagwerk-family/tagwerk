<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins;

use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Photos\PhotoRule;

final class FixtureRule implements PhotoRule
{
    public function key(): string
    {
        return 'acme/fixture';
    }

    public function label(): string
    {
        return 'Fixture';
    }

    public function description(): string
    {
        return '';
    }

    public function pick(array $photos, int $count, CarbonImmutable $today): array
    {
        return array_slice($photos, 0, $count);
    }
}

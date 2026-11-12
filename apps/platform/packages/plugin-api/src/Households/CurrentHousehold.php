<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Households;

/**
 * The household the current request or job works for. Null everywhere
 * while there is none — an operator without a household, a job that has not
 * set one yet.
 */
interface CurrentHousehold
{
    public function id(): ?int;

    public function name(): ?string;

    /** e.g. `Europe/Vienna`; the zone in which the household's days begin and end. */
    public function timezone(): ?string;

    /** The household's default language, e.g. `de`. */
    public function locale(): ?string;
}

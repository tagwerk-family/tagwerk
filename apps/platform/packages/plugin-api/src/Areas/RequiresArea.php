<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Areas;

/** An area that works only while another one is on, like `Feature::requires()`. */
interface RequiresArea
{
    public function requires(): string;
}

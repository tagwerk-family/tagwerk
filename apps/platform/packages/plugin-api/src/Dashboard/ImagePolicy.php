<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Dashboard;

/** Which image URLs a widget may hand to the wall — the host's content security policy. */
interface ImagePolicy
{
    public function allowsImage(string $url): bool;
}

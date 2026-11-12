<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Calendar;

/** One page of changes. */
final readonly class ChangePage
{
    /**
     * @param  array<int, RemoteEvent>  $events
     * @param  string|null  $nextPage  further page of the same run
     * @param  string|null  $nextCursor  state for the next sync; usually only comes on
     *                                   the last page
     */
    public function __construct(
        public array $events = [],
        public ?string $nextPage = null,
        public ?string $nextCursor = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Calendar;

use RuntimeException;

/**
 * The event changed at the provider since our last known state.
 *
 * On concurrent edits the provider wins. A merge dialog would be overkill for
 * a family calendar; instead we fetch the remote state and show it rather
 * than overwriting it.
 */
final class EventConflict extends RuntimeException {}

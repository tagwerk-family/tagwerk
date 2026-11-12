<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Calendar;

use RuntimeException;

/**
 * The provider discarded the state of the last sync (Google: 410 on the
 * syncToken).
 *
 * Not an incident but a regular path: the calendar's events are cleared and
 * fetched again in full. That is why the person assignment is also stored on
 * the event itself.
 */
final class SyncCursorExpired extends RuntimeException {}

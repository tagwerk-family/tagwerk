<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Calendar;

use RuntimeException;

/**
 * An incoming notification was not genuine.
 *
 * The endpoint is necessarily public; without verification anyone could
 * trigger our syncs.
 */
final class InvalidNotification extends RuntimeException {}

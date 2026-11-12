<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Households;

/**
 * An admin page that stays open while the household is read-only — a
 * billing page, say. Every other page of a read-only household leads to
 * the core's read-only notice.
 */
interface AvailableWhileReadOnly {}

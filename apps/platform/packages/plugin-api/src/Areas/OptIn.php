<?php

declare(strict_types=1);

namespace Fahoba\PluginApi\Areas;

/**
 * An area that opens the household to the outside — other programs, feeds,
 * addresses that answer without a sign-in.
 *
 * Off until the household switches it on, unlike every other area: an
 * installed package must not hand out a door nobody asked for. Stored as
 * the exception the other way round (`households.opted_in_features`).
 */
interface OptIn {}

<?php

declare(strict_types=1);

namespace App\Integrations\Calendar\Google;

/**
 * The keys under which Fahoba stores its own data on the Google event.
 *
 * One place for the write and the read path: if the two sides drifted apart,
 * it would go unnoticed because nothing crashes — the echo protection would
 * simply stop working, and every write of ours would trigger another one via
 * the watch channel.
 *
 * Limits according to Google: at most 300 properties, 32 kB in total, keys
 * up to 44 characters, values up to 1024 characters. `private` is bound to
 * the calendarId and therefore the right variant; `shared` would appear for
 * all attendees.
 */
final class EventProperties
{
    /** Recognises our own write when it comes back through the channel. */
    public const REVISION = 'fahoba_revision';

    /**
     * The explicit person mapping, as a list of IDs.
     *
     * Also stored on the event itself so it survives a full resync after
     * `410 Gone`.
     */
    public const PEOPLE = 'fahoba_people';

    /**
     * The keys from before the rename to Fahoba, still read: events written
     * then carry them at Google, and without them every assignment and the
     * echo protection of those events would be lost.
     */
    public const LEGACY = [
        self::REVISION => 'tagwerk_revision',
        self::PEOPLE => 'tagwerk_people',
    ];
}

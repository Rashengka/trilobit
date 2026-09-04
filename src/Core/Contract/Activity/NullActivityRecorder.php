<?php

declare(strict_types=1);

namespace Trilobit\Core\Contract\Activity;

/**
 * What answers ActivityRecorder when no enabled module keeps a timeline:
 * every record is accepted and none of them go anywhere.
 *
 * Trilobit\Core\DI\CoreExtension registers this in place of the port when
 * nothing else does; see NullPartyDirectory for the same mechanism on the
 * other port.
 */
final class NullActivityRecorder implements ActivityRecorder
{
    public function record(ActivityRecord $record): void
    {
        // Nobody is listening. Accepting the record and doing nothing with it
        // is the point: a caller records an activity unconditionally, and
        // whether it is kept is a question for the modules currently enabled,
        // never for the caller.
    }
}

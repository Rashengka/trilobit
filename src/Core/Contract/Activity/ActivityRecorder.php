<?php

declare(strict_types=1);

namespace Trilobit\Core\Contract\Activity;

/**
 * The port a module implements when it keeps a timeline of what happened to a
 * person.
 *
 * Like PartyDirectory, this is a plain constructor dependency rather than an
 * optional one: when no enabled module implements it, Core registers
 * Trilobit\Core\Contract\Activity\NullActivityRecorder in its place, so
 * recording an activity is never conditional on which modules happen to be
 * switched on.
 */
interface ActivityRecorder
{
    public function record(ActivityRecord $record): void;
}

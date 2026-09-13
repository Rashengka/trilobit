<?php

declare(strict_types=1);

namespace Trilobit\Core\Setup;

/**
 * Where the setup of this installation stands, as its database says.
 *
 * The wizard has no memory of its own - no session, no "step 2 of 3" - and
 * that is deliberate: a step read off the data cannot disagree with the data,
 * and a visitor who comes back tomorrow, or somebody who ran the migrations
 * from the command line first, lands on the step that is actually next
 * (decision O4 in .ai/plans/23-instalace-na-zelene-louce.md).
 */
enum Step
{
    /** Nothing answers where the configuration says the database is. */
    case DatabaseUnreachable;

    /** The database answers and not every migration this build has has run in it. */
    case Install;

    /** The tables are there and hold no account and no business (decision O4). */
    case FirstAdministrator;

    /**
     * The installation holds an account or a business, and the wizard is over
     * - not refused, but not there (decision O3).
     */
    case Done;
}

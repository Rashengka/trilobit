<?php

declare(strict_types=1);

namespace Trilobit\Tests;

use RuntimeException;

/**
 * Raised when a test that needs the database could not have one.
 *
 * It exists to be a name rather than a message. A run without a server used to
 * end in skips, and a skipped suite prints a green summary - the shape this
 * project spends most of its guards on, where the failure and the success are
 * told apart by nobody. The database is now a part of the application rather
 * than an option it can be run without, so the honest end is red, and the class
 * name is what says which red it is: this is not a broken test, it is a run
 * that never happened.
 *
 * Exit condition: none. Every reason a database could be missing is a reason
 * this run proved nothing, so there is no case in which one of them should go
 * back to being quiet.
 */
final class NoDatabaseToTestAgainst extends RuntimeException {}

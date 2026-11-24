<?php

declare(strict_types=1);

use Trilobit\Tests\Boot;

require __DIR__ . '/../vendor/autoload.php';

// Before any test has loaded the application's code, so that what the suite's
// builds are keyed by is the code this process runs; see the method.
Boot::takeTheSourceAsItIsNow();

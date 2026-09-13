<?php

declare(strict_types=1);

use Nette\Application\Application;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Config\ModeNotNamed;

require __DIR__ . '/../vendor/autoload.php';

try {
    $container = Bootstrap::boot();
} catch (ModeNotNamed $refusal) {
    // The one failure of the boot answered here, and on purpose the only one.
    // It happens before Tracy is switched on, so without this whoever opens a
    // page after pulling the change meets an empty 500 and no word of why. Its
    // message is fixed text that describes no machine, which is what makes it
    // fit to show; any other exception thrown while booting could carry
    // anything, and is left to PHP, which in production shows nothing. See
    // tests/Integration/Config/TheRefusalReachesTheBrowserTest.
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $refusal->getMessage(), "\n";

    return;
}

$container->getByType(Application::class)->run();

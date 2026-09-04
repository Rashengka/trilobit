<?php

declare(strict_types=1);

use Nette\Application\Application;
use Trilobit\Core\Bootstrap;

require __DIR__ . '/../vendor/autoload.php';

Bootstrap::boot()
    ->getByType(Application::class)
    ->run();

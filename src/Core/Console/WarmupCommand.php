<?php

declare(strict_types=1);

namespace Trilobit\Core\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Trilobit\Core\Build\BuildManifest;

/**
 * Brings the generated files under var/ up to date with the configuration.
 *
 * It is the step between changing config/modules.neon and building the assets,
 * and it is a command rather than something that happens on the first request
 * because the asset build runs without ever starting PHP. Running it twice
 * changes nothing, so a deployment script may simply always call it.
 */
#[AsCommand(name: 'app:warmup', description: 'Writes what this build is made of to var/build.')]
final class WarmupCommand extends Command
{
    public function __construct(
        private readonly BuildManifest $manifest,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->manifest->write() as $file) {
            $output->writeln(sprintf('Wrote %s', $file));
        }

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Trilobit\Core\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Trilobit\Core\Media\MediaNotStored;
use Trilobit\Core\Media\MediaStorage;

/**
 * Makes every variant of every stored picture again, from its original.
 *
 * For when the sizes change, and for when www/media has been lost: the
 * originals are the record and the variants can always be made from them.
 *
 * A picture it cannot make is named on the error output and does not stop the
 * rest, and any such picture fails the run - a run that went on quietly would
 * end with the same exit code as one where nothing went wrong.
 */
#[AsCommand(name: 'app:media-variants', description: 'Makes every variant of every stored picture again from its original.')]
final class MediaVariantsCommand extends Command
{
    public function __construct(
        private readonly MediaStorage $storage,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $paths = $this->storage->originals();
        if ($paths === []) {
            $output->writeln('There are no pictures to make variants of.');

            return self::SUCCESS;
        }

        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $made = 0;
        foreach ($paths as $path) {
            try {
                $this->storage->regenerate($path);
                $made++;
            } catch (MediaNotStored $failure) {
                $errors->writeln(sprintf('<error>%s</error> %s', $path, $failure->getMessage()));
            }
        }

        $output->writeln(sprintf('Made the variants of %d of %d pictures.', $made, count($paths)));

        return $made === count($paths) ? self::SUCCESS : self::FAILURE;
    }
}

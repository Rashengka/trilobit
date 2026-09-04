<?php

declare(strict_types=1);

namespace Trilobit\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Nette\DI\Container;
use PHPUnit\Framework\Assert;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Running the migrations of a build, the way a person running them would.
 *
 * The schema in a test is built by executing the migrations rather than from
 * the mapping, because the mapping is not what a customer's database is made
 * of. A schema created from metadata would be right every time and would never
 * once have shown that the migrations themselves are complete.
 */
final class Migrations
{
    /** Every migration the build has, up to the latest. */
    public static function run(Container $container): string
    {
        $command = new MigrateCommand($container->getByType(DependencyFactory::class));
        new Application()->addCommand($command);

        $tester = new CommandTester($command);
        $status = $tester->execute(
            ['--allow-no-migration' => true],
            ['interactive' => false, 'capture_stderr_separately' => true],
        );

        $output = $tester->getDisplay() . $tester->getErrorOutput();
        Assert::assertSame(Command::SUCCESS, $status, 'the migrations did not run: ' . $output);

        // The migrator leaves the connection where a fresh process would
        // simply end: this server commits a transaction implicitly on every
        // schema statement, so what the client believes about how deep it is
        // no longer matches. Whoever uses the build next opens a new one.
        $container->getByType(Connection::class)->close();

        return $output;
    }
}

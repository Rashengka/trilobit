<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Doctrine;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Tests\Boot;

/**
 * Generating a migration from a build that is missing a module is refused.
 *
 * The filter over the tables the schema tools may see is what protects a
 * customer's data; this is what protects the commit. Even with the filter in
 * place, a diff taken from a build without a module is an incomplete picture -
 * the mapping of the module that is switched off is not loaded, so a change to
 * its entities is simply not in the comparison, and the migration that comes
 * out looks finished. Nobody reviewing it can tell what is missing, because
 * what is missing left no trace.
 *
 * The command therefore refuses to run rather than producing something that
 * has to be checked by hand. It says which modules to switch on, because the
 * answer to the refusal is a two-line edit and there is no reason to make
 * somebody look it up.
 */
#[CoversNothing]
final class MigrationsDiffGuardTest extends TestCase
{
    private const string SERVICE = 'core.migrationsDiffCommand';

    public function testItRefusesToRunWhenAModuleIsSwitchedOff(): void
    {
        $tester = new CommandTester($this->command(['cms' => true, 'crm' => false, 'shop' => true]));

        $status = $tester->execute(['--namespace' => 'Trilobit\Shop\Migrations'], ['capture_stderr_separately' => true]);
        $output = $tester->getDisplay() . $tester->getErrorOutput();

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('crm', $output);
        self::assertStringContainsString('config/modules.neon', $output);
    }

    /**
     * The refusal names every module that is off, not the first one, so that
     * switching one on does not lead to the same refusal a second time.
     */
    public function testTheRefusalNamesEveryModuleThatIsOff(): void
    {
        $tester = new CommandTester($this->command(['cms' => false, 'crm' => false, 'shop' => true]));
        $tester->execute(['--namespace' => 'Trilobit\Core\Migrations'], ['capture_stderr_separately' => true]);

        $output = $tester->getDisplay() . $tester->getErrorOutput();

        self::assertStringContainsString('cms', $output);
        self::assertStringContainsString('crm', $output);
    }

    /**
     * With every module on, the namespace is what decides which tables the
     * diff compares - so a namespace the rule cannot place is refused too,
     * rather than falling back to comparing everything.
     */
    public function testItRefusesANamespaceThatNamesNoModule(): void
    {
        $tester = new CommandTester($this->command(['cms' => true, 'crm' => true, 'shop' => true]));

        $status = $tester->execute(['--namespace' => 'App\Migrations'], ['capture_stderr_separately' => true]);
        $output = $tester->getDisplay() . $tester->getErrorOutput();

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('App\Migrations', $output);
    }

    public function testItRefusesWithoutANamespace(): void
    {
        $tester = new CommandTester($this->command(['cms' => true, 'crm' => true, 'shop' => true]));

        $status = $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('--namespace', $tester->getDisplay() . $tester->getErrorOutput());
    }

    /**
     * @param array<string, bool> $modules
     */
    private function command(array $modules): Command
    {
        $container = Boot::container(ModuleList::of($modules, Bootstrap::rootDirectory()));
        $command = $container->getService(self::SERVICE);

        self::assertInstanceOf(Command::class, $command);

        // Put into an application rather than run bare, because an application
        // merges its own options into the command's definition and replaces
        // it - which is the step a command wrapping another one has to survive,
        // and the one a bare CommandTester would never take.
        new Application()->addCommand($command);

        return $command;
    }
}

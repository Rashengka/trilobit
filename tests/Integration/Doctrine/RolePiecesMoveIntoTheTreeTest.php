<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Trilobit\Core\Migrations\Version20260907064607;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;

/**
 * The roles an earlier build wrote, carried over to the resources named as
 * paths under `app` - and back again, exactly.
 *
 * An earlier build's piece names a resource this build does not have, so it
 * is dropped when it is read: a role left unmigrated reaches nothing, and
 * nothing says why. That is what the migration is for, and it is asserted
 * against rows written the way an earlier build wrote them, on a schema that
 * got there by running the migrations.
 *
 * **Back means byte for byte.** A row that names nothing that moved is not
 * written at all - down to how it happens to be spaced - and a row that does
 * comes back as the string it was.
 *
 * **Every step is a build of its own**, the way every run of
 * `bin/trilobit migrations:migrate` is a process of its own. Doctrine freezes
 * a migration once it has run and keeps the instance for as long as the
 * build lives, so taking one back in the build that had just run it is a
 * refusal no person running the command would ever meet.
 */
#[CoversNothing]
final class RolePiecesMoveIntoTheTreeTest extends TestCase
{
    /** The last migration before the pieces moved. */
    private const string BEFORE = Version20260907064607::class;

    private const string LATEST = 'latest';

    /**
     * By the role's code: the column as an earlier build wrote it, and as it
     * reads once the pieces have moved.
     *
     * @var array<string, array{string, string}>
     */
    private const array ROLES = [
        'administrator' => [
            '["administration:*","account:view","account:purge","content:change_priority","redirection:force_redirect"]',
            '["app.administration:*","app.administration.account:view","app.administration.account:purge",'
                . '"app.administration.content:change_priority","app.redirection:force_redirect"]',
        ],
        'editor' => [
            '["content:*","content:view"]',
            '["app.administration.content:*","app.administration.content:view"]',
        ],
        'keeper' => ['["redirection:view"]', '["app.redirection:view"]'],
        // Nothing in it names a resource that moved: a piece nobody has, a
        // string that was never a piece, and a name that merely begins like
        // one that moved. Spaced the way no build writes it, so that being
        // rewritten anyway would show.
        'outdated' => [
            '["invoicing:view", "administration", "contentment:view"]',
            '["invoicing:view", "administration", "contentment:view"]',
        ],
        'nobody' => ['[]', '[]'],
    ];

    private string $schema = '';

    protected function tearDown(): void
    {
        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testThePiecesMoveOntoThePaths(): void
    {
        $this->rolesWrittenByAnEarlierBuild();

        $this->migrate(self::LATEST);

        self::assertSame($this->column(1), $this->rolesStored());
    }

    /** Up, down and up again: taking it back is exact, and it does not stop it being run again. */
    public function testTakingItBackRestoresEveryRowAsItWas(): void
    {
        $this->rolesWrittenByAnEarlierBuild();

        $this->migrate(self::LATEST);
        $this->migrate(self::BEFORE);

        self::assertSame($this->column(0), $this->rolesStored());

        $this->migrate(self::LATEST);

        self::assertSame($this->column(1), $this->rolesStored());
    }

    private function rolesWrittenByAnEarlierBuild(): void
    {
        $this->schema = Database::schemaFor(self::class);
        $this->migrate(self::BEFORE);

        $connection = Boot::coreAlone()->getByType(Connection::class);
        foreach (self::ROLES as $code => [$written]) {
            $connection->insert('core_role', ['code' => $code, 'name' => ucfirst($code), 'permissions' => $written]);
        }

        $connection->close();
    }

    /** @return array<string, string> the column as it is stored, by the role's code */
    private function rolesStored(): array
    {
        $connection = Boot::coreAlone()->getByType(Connection::class);

        /** @var array<string, string> $roles */
        $roles = $connection->fetchAllKeyValue('SELECT code, permissions FROM core_role ORDER BY id');
        $connection->close();

        return $roles;
    }

    /**
     * @param 0|1 $which before the pieces moved, or after
     *
     * @return array<string, string>
     */
    private function column(int $which): array
    {
        return array_map(static fn(array $role): string => $role[$which], self::ROLES);
    }

    private function migrate(string $version): void
    {
        $build = Boot::coreAlone();
        $command = new MigrateCommand($build->getByType(DependencyFactory::class));
        new Application()->addCommand($command);

        $tester = new CommandTester($command);
        $status = $tester->execute(
            ['version' => $version, '--allow-no-migration' => true],
            ['interactive' => false, 'capture_stderr_separately' => true],
        );

        self::assertSame(
            Command::SUCCESS,
            $status,
            'the migrations did not run: ' . $tester->getDisplay() . $tester->getErrorOutput(),
        );

        // As in Trilobit\Tests\Migrations: the migrator leaves the connection
        // where a fresh process would simply end.
        $build->getByType(Connection::class)->close();
    }
}

<?php

declare(strict_types=1);

namespace Trilobit\Core\Setup;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Doctrine\ORM\EntityManagerInterface;
use Nette\Security\Passwords;
use SensitiveParameter;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Trilobit\Core\Domain\Setup\Completion;
use Trilobit\Core\Domain\Tenancy\Domain;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Security\Accounts;
use Trilobit\Core\Security\PermissionStructure;

/**
 * What the setup wizard writes: the tables, and then the installation's first
 * administrator together with its first business.
 *
 * It is the wizard's counterpart of `migrations:migrate`, `app:tenant` and
 * `app:account` run one after another, and it writes the same rows those do,
 * so that an installation set up either way is the same installation.
 */
final readonly class Installer
{
    /**
     * What an installation has to hold none of for the wizard to finish it:
     * an account, and a business (decision O3, tightened on 2026-09-13).
     *
     * @var list<class-string>
     */
    private const array EMPTY_OF = [User::class, Tenant::class];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private DependencyFactory $migrations,
        private Accounts $accounts,
        private Passwords $passwords,
        private PermissionStructure $structure,
    ) {}

    /**
     * Runs every migration this build has that has not run yet - through
     * Doctrine's own command, so that it is the same run `bin/trilobit
     * migrations:migrate` makes rather than a second way of making one.
     *
     * What the command prints is kept rather than shown: it goes into the
     * exception when the run fails, which is the log in production and the
     * error page in debug mode, and nowhere when it succeeds.
     */
    public function install(): void
    {
        $command = new MigrateCommand($this->migrations);
        new Application()->addCommand($command);

        $input = new ArrayInput(['--allow-no-migration' => true]);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $status = $command->run($input, $output);

        // The server commits implicitly on every schema statement, so what the
        // client believes about its transactions no longer holds; the next
        // request opens a connection of its own. See Trilobit\Tests\Migrations.
        $this->entityManager->getConnection()->close();

        if ($status !== Command::SUCCESS) {
            throw new \RuntimeException(sprintf('The migrations did not run: %s', $output->fetch()));
        }
    }

    /**
     * Makes the installation's first administrator and its first business on
     * an installation that holds nothing yet, or refuses - and says which:
     * true when this call made them, false when it made nothing at all.
     *
     * **Two things are settled inside the transaction, in this order, and
     * neither by asking first.** Asking "is the installation still empty?"
     * before writing would let through both of two visitors finishing at the
     * same moment, and a visitor finishing while `app:account` or `app:tenant`
     * makes a first row; the wizard is a public page, and either would hand
     * whoever was quicker the installation.
     *
     * - **The claim.** The row of Trilobit\Core\Domain\Setup\Completion goes
     *   in first, under a key there is only one of. A second wizard waits on
     *   it and is refused when the first commits. The insert goes past the
     *   entity manager for the reason
     *   Trilobit\Core\Security\Accounts::applicationRoleMadeIfMissing() gives:
     *   a refused flush closes the entity manager for good, a refused
     *   statement on the connection does not.
     * - **The emptiness, with the claim held.** Accounts and businesses are
     *   read with a locking read (FOR UPDATE), which reads what is committed
     *   rather than a snapshot and waits for a row another transaction has
     *   written and not committed yet - so a command making an account at this
     *   moment is waited for and then seen. On an empty table the same read
     *   locks the gap a row would go into, so a command starting a moment
     *   later waits for this transaction instead. The isolation level is said
     *   outright for this transaction, because it is what makes that gap lock
     *   exist, and a server configured to READ COMMITTED would otherwise take
     *   none without saying so.
     *
     * Refused either way, everything is rolled back, the claim included, so
     * that the row never says the wizard finished an installation it did not.
     *
     * **Being both is said by $alsoTheBusiness and by nothing else.** With it
     * the administrator owns the business as well, through
     * Membership::forTheInstallationsAdministrator() - the simple installation,
     * one person running one shop. Without it the business is made and nobody
     * holds a role in it yet - the separated installation, where
     * `app:account --tenant` makes whoever will run it.
     *
     * The business answers at $host and nowhere else; more hosts are
     * `app:tenant`'s. The password is hashed before the transaction opens, so
     * that the slow part of this - on purpose, see
     * Trilobit\Core\DI\CoreExtension::hashPasswordsWithoutThrowingAnyOfThemAway()
     * - holds no lock, and it is not kept anywhere but as that hash.
     *
     * The owner's role is made inside the transaction, which
     * Accounts::applicationRoleMadeIfMissing() says it is not meant for: if
     * `app:account` made the same role in the very same moment, the read back
     * would not see it and this call would fail - loudly, with everything it
     * wrote rolled back. On an installation with no account the only command
     * it could be racing is one making the first account, and the locking
     * read above has already waited for that one.
     */
    public function complete(
        string $email,
        string $name,
        #[SensitiveParameter]
        string $password,
        string $business,
        string $host,
        bool $alsoTheBusiness,
    ): bool {
        $hash = $this->passwords->hash($password);
        $now = new DateTimeImmutable();

        $connection = $this->entityManager->getConnection();
        $claim = $this->entityManager->getClassMetadata(Completion::class);

        // For the next transaction only, which is the one opened below.
        $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $connection->beginTransaction();

        try {
            try {
                $connection->insert(
                    $claim->getTableName(),
                    [$claim->getColumnName('id') => Completion::ONLY, $claim->getColumnName('completedAt') => $now],
                    [$claim->getColumnName('completedAt') => Types::DATETIME_IMMUTABLE],
                );
            } catch (UniqueConstraintViolationException) {
                $connection->rollBack();

                return false;
            }

            if ($this->holdsAnything($connection)) {
                $connection->rollBack();

                return false;
            }

            $account = new User($email, $hash, $name, $now, landlord: true);
            $tenant = new Tenant($business, $now);
            $this->entityManager->persist($account);
            $this->entityManager->persist($tenant);
            $this->entityManager->persist(new Domain(strtolower($host), $tenant));

            if ($alsoTheBusiness) {
                $this->entityManager->persist(Membership::forTheInstallationsAdministrator(
                    $tenant,
                    $account,
                    $this->accounts->ownersRole($this->structure),
                ));
            }

            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $failure) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $failure;
        }

        return true;
    }

    /**
     * Whether the installation holds an account or a business, read with a
     * lock - see complete(). The tables and columns are the mapping's, read
     * off it rather than written out a second time.
     */
    private function holdsAnything(Connection $connection): bool
    {
        foreach (self::EMPTY_OF as $entity) {
            $mapping = $this->entityManager->getClassMetadata($entity);
            $found = $connection->fetchOne(sprintf(
                'SELECT %s FROM %s LIMIT 1 FOR UPDATE',
                $mapping->getSingleIdentifierColumnName(),
                $mapping->getTableName(),
            ));

            if ($found !== false) {
                return true;
            }
        }

        return false;
    }
}

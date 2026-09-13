<?php

declare(strict_types=1);

namespace Trilobit\Core\Setup;

use DateTimeImmutable;
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
     * Makes the installation's first administrator and its first business, or
     * refuses because somebody else already did - and says which: true when
     * this call made them, false when it made nothing at all.
     *
     * **The claim comes first, and it is what decides.** The row of
     * Trilobit\Core\Domain\Setup\Completion is inserted before anything else,
     * inside the same transaction, under a key there is only one of. A second
     * call at the same moment waits on that row and is refused when the first
     * commits, with nothing of its own written yet; a second call afterwards
     * is refused at once. Asking "is there an administrator yet?" first would
     * let both of two simultaneous visitors through, which is the one outcome
     * a public page on a fresh installation must not have. The insert goes
     * past the entity manager for the reason
     * Trilobit\Core\Security\Accounts::applicationRoleMadeIfMissing() gives: a
     * refused flush closes the entity manager for good, a refused statement on
     * the connection does not.
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
     * wrote rolled back. Only one wizard can be past the claim at a time, so
     * that is the whole of the exposure, and failing is the right answer to it.
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
}

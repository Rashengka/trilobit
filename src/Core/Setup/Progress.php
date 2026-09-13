<?php

declare(strict_types=1);

namespace Trilobit\Core\Setup;

use Doctrine\DBAL\Exception as DatabaseFailure;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManagerInterface;
use Trilobit\Core\Domain\User\User;

/**
 * How far the setup of this installation has got, read off its database every
 * time it is asked (see Trilobit\Core\Setup\Step for why there is no other
 * memory of it).
 *
 * The questions go in the order in which each one can be asked at all: whether
 * anything answers, then whether somebody already administers the
 * installation - which ends the wizard whatever else is true, a pending
 * migration included - and only then whether the migrations have all run.
 *
 * **"Somebody administers the installation" is the flag on an account and
 * nothing else** (decision O3). An installation holding only the administrator
 * of a business is one nobody looks after as a whole, and the wizard stays how
 * it gets somebody who does. A database with no accounts table yet is one
 * nobody administers either, which is the fresh installation's case.
 *
 * **Nothing here decides between two visitors.** This is a reading, made
 * before anybody writes, and two readings made at the same moment agree. What
 * decides is Trilobit\Core\Domain\Setup\Completion, inside the installer's
 * transaction; this only says which page to draw.
 */
final class Progress
{
    private ?Attempt $attempt = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DependencyFactory $migrations,
        /**
         * Whether a database that cannot be reached may be described - where
         * it was looked for, as whom, and what the driver said. True in debug
         * mode and nowhere else: that is the mode in which the framework's own
         * error page already shows the same things, so the line is drawn where
         * one already is rather than at a second place (a working copy, and a
         * staging request carrying the debug secret). A production page is
         * public, and a host and a user name are a map for whoever is probing
         * it.
         */
        private readonly bool $explains,
    ) {}

    public function step(): Step
    {
        $this->attempt = null;
        $connection = $this->entityManager->getConnection();

        try {
            $connection->executeQuery('SELECT 1');
        } catch (DatabaseFailure $failure) {
            if ($this->explains) {
                $this->attempt = Attempt::of($connection->getParams(), $failure);
            }

            return Step::DatabaseUnreachable;
        }

        if ($this->theInstallationHasAnAdministrator()) {
            return Step::Done;
        }

        return count($this->migrations->getMigrationStatusCalculator()->getNewMigrations()) > 0
            ? Step::Install
            : Step::FirstAdministrator;
    }

    /**
     * What was tried, when the last step() found nothing answering and this
     * build may say so; null otherwise, and always null outside debug mode.
     */
    public function attempt(): ?Attempt
    {
        return $this->attempt;
    }

    private function theInstallationHasAnAdministrator(): bool
    {
        try {
            $count = $this->entityManager
                ->createQuery(sprintf('SELECT COUNT(u.id) FROM %s u WHERE u.landlord = true', User::class))
                ->getSingleScalarResult();
        } catch (TableNotFoundException) {
            return false;
        }

        return is_numeric($count) && (int) $count > 0;
    }
}

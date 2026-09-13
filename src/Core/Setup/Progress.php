<?php

declare(strict_types=1);

namespace Trilobit\Core\Setup;

use Doctrine\DBAL\Exception as DatabaseFailure;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManagerInterface;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Domain\User\User;

/**
 * How far the setup of this installation has got, read off its database every
 * time it is asked (see Trilobit\Core\Setup\Step for why there is no other
 * memory of it).
 *
 * The questions go in the order in which each one can be asked at all: whether
 * anything answers, then whether the installation already holds anything -
 * which ends the wizard whatever else is true, a pending migration included -
 * and only then whether the migrations have all run.
 *
 * **The wizard is for an empty installation and for nothing else** (decision
 * O3 in .ai/plans/23-instalace-na-zelene-louce.md, tightened on 2026-09-13).
 * One account of any kind, or one business, and it is over: an installation
 * that already has something was set up some other way - `app:tenant`,
 * `app:account` - and is finished that way, never by a public page able to
 * make its administrator. A database with no tables yet holds nothing, which
 * is the fresh installation's case.
 *
 * **Nothing here decides between two writers.** This is a reading, made
 * before anybody writes, and two readings made at the same moment agree. What
 * decides is Trilobit\Core\Setup\Installer, which asks the same question again
 * inside its transaction, after taking the claim; this only says which page
 * to draw.
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

        if ($this->holdsAny(User::class) || $this->holdsAny(Tenant::class)) {
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

    /**
     * Whether there is a row of $entity. Both entities asked about are shared
     * rather than a business's, so the question needs no business entered; a
     * table that is not there yet holds nothing.
     *
     * @param class-string $entity
     */
    private function holdsAny(string $entity): bool
    {
        try {
            $count = $this->entityManager
                ->createQuery(sprintf('SELECT COUNT(e.id) FROM %s e', $entity))
                ->getSingleScalarResult();
        } catch (TableNotFoundException) {
            return false;
        }

        return is_numeric($count) && (int) $count > 0;
    }
}

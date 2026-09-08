<?php

declare(strict_types=1);

namespace Trilobit\Core\Console;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nette\Security\Passwords;
use Nette\Utils\Random;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Security\Accounts;
use Trilobit\Core\Security\Grant;
use Trilobit\Core\Security\PermissionStructure;
use Trilobit\Core\Tenancy\HostTenants;
use Trilobit\Core\Tenancy\Tenancy;

/**
 * Makes somebody who can sign in, which a freshly installed application
 * otherwise has nobody to - and says which of the two kinds of administrator it
 * is making.
 *
 * **There are two, and they are different scopes rather than different levels.**
 * Without --tenant the account administers the installation: it belongs to no
 * business, holds no role, and is answered about by
 * Trilobit\Core\Security\Landlords. With --tenant it administers one business:
 * the account, the role and the Trilobit\Core\Domain\Tenancy\Membership joining
 * them in that business, which is the only place a right is ever written down.
 * The command has to say which, because an account that was given neither is an
 * account that can sign in and do nothing, and that is what the first run of a
 * fresh installation used to produce.
 *
 * **The business is named by a host and not by an identifier.** A host is what
 * a person knows, it is what `app:tenant` was given, and it is the same
 * sentence a request says by arriving. A host nobody has claimed is refused
 * rather than quietly given a business of its own: two ways to create one is
 * one too many, and the one that happens by accident is the wrong one.
 *
 * **The two shapes cannot be combined on one account.** Asking for a role
 * inside a business for the installation's administrator - or the reverse - is
 * refused and says why. What an account is, is settled when it is made; see the
 * constructor of Trilobit\Core\Domain\User\User.
 *
 * The password is generated here and shown once. It is not an argument and not
 * an option: an argument is in the shell history of the machine it was typed
 * on and in that machine's process list while the command runs, and neither is
 * a place for it. It is not written to a file either - what is stored is the
 * hash.
 *
 * Run again for an address that already exists it replaces the password rather
 * than refusing. That is what somebody who has lost theirs needs, and what a
 * deployment script that calls this every time needs; making a second account
 * for one address is what nobody needs.
 */
#[AsCommand(
    name: 'app:account',
    description: 'Creates the administrator of the installation, or of one business, or gives one a new password.',
)]
final class AccountCommand extends Command
{
    /**
     * How the generated password appears in the output: on a line of its own,
     * indented, with nothing else on it.
     *
     * It is a constant because it is a contract. tests/e2e signs in with a
     * real browser and reads the password back out of this output, and
     * Trilobit\Tests\Integration\Console\AccountCommandTest holds the format
     * still so that rewording the prose around it cannot quietly break that.
     * Nothing else printed here may be two spaces and a single word.
     */
    public const string PASSWORD_LINE = '/^ {2}(\S+)$/m'; // check-leaks:allow rule=credential reason=a regular expression that finds the line, not a value that was on it

    /** The option that decides which of the two administrators is meant, by naming a host the business answers at. */
    private const string TENANT = 'tenant';

    /** Long enough that it is not worth attacking, short enough to be typed once. */
    private const int GENERATED_LENGTH = 24;

    /** The role an account administering one business holds; see permissionsNow() for what it is made of. */
    private const string ROLE_CODE = 'administrator';

    private const string ROLE_NAME = 'Administrator';

    public function __construct(
        private readonly Accounts $accounts,
        private readonly Passwords $passwords,
        private readonly EntityManagerInterface $entityManager,
        /**
         * Which business answers at a host, asked the one way that is not
         * scoped by a business. A command line is inside none until it says so,
         * so this is also the only way the host given here can be resolved at
         * all; see Trilobit\Core\Tenancy\HostTenants.
         */
        private readonly HostTenants $hosts,
        private readonly Tenancy $tenancy,
        private readonly PermissionStructure $structure,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'The address the account signs in with.');
        $this->addOption(
            self::TENANT,
            null,
            InputOption::VALUE_REQUIRED,
            'A host the business answers at. Left out, the account administers the installation instead.',
        );
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'What to call this person.', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');
        if (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $style->error('The first argument has to be an address an account can sign in with.');

            return self::FAILURE;
        }

        $host = $input->getOption(self::TENANT);
        if ($host !== null && (!is_string($host) || trim($host) === '')) {
            $style->error('--tenant has to be a host the business answers at.');

            return self::FAILURE;
        }

        $host = is_string($host) ? strtolower(trim($host)) : null;
        $tenant = null;

        if ($host !== null) {
            $tenant = $this->businessAt($host);
            if (!$tenant instanceof Tenant) {
                $style->error(sprintf('No business answers at \'%s\'.', $host));
                $style->writeln(
                    'A host that names no business is refused rather than given one of its own, because a business '
                    . 'created by a typo is one somebody would go on administering. Make it with app:tenant first, '
                    . 'then name one of its hosts here.',
                );

                return self::FAILURE;
            }
        }

        $account = $this->accounts->withEmail($email);
        if ($account instanceof User && $account->isLandlord() !== ($host === null)) {
            $style->error(sprintf(
                $account->isLandlord()
                    ? '%s administers the installation, so it cannot be given a role inside a business as well.'
                    : '%s administers a business, so it cannot be made the administrator of the installation as well.',
                $email,
            ));
            $style->writeln(
                'They are two scopes rather than two levels, and an account in both would make "which scope is '
                . 'this question about" something every part of the application had to get right. Which of the two '
                . 'an account is was settled when it was made; make a second account for the other one.',
            );

            return self::FAILURE;
        }

        $name = $input->getOption('name');
        $name = is_string($name) && $name !== '' ? $name : $this->nameFrom($email);

        $password = Random::generate(self::GENERATED_LENGTH, 'a-zA-Z0-9');

        if (!$account instanceof User) {
            $account = new User(
                $email,
                $this->passwords->hash($password),
                $name,
                new DateTimeImmutable(),
                landlord: $host === null,
            );
            $existing = false;
        } else {
            $account->changePassword($this->passwords->hash($password));
            $account->rename($name);
            $existing = true;
        }

        $this->accounts->save($account);

        if ($tenant instanceof Tenant) {
            $this->administer($tenant, $account);
        }

        $style->success(sprintf(
            '%s can sign in as %s.',
            $existing ? 'The account that was already there' : 'A new account',
            $email,
        ));
        $style->writeln($tenant instanceof Tenant
            ? sprintf('It administers %s, the business answering at %s.', $tenant->name(), $host)
            : 'It administers the installation: it belongs to no business and holds no role in one.');
        $style->writeln('');
        $style->writeln('The password for that account is');
        $style->writeln('');
        $style->writeln('  ' . $password);
        $style->writeln('');
        $style->writeln('This is the only time it is shown. What is stored is a hash of it.');

        return self::SUCCESS;
    }

    /**
     * The business answering at $host, with the process now working inside it.
     *
     * Entering it is what puts the business into every query below - the
     * membership this command reads and writes is scoped by it like every other
     * tenanted row - so it happens here, next to the reading that settled which
     * business it is, rather than being remembered further down.
     */
    private function businessAt(string $host): ?Tenant
    {
        $id = $this->hosts->tenantAt($host);
        if ($id === null) {
            return null;
        }

        $this->tenancy->enter($id);

        return $this->entityManager->getRepository(Tenant::class)->find($id);
    }

    /**
     * Gives $account the administrator's role in $tenant, and brings the role
     * itself up to what this build offers.
     *
     * The role is redefined rather than only created, because an installation
     * upgraded from an earlier build already holds a row under this code
     * carrying whatever that build wrote in it - and an account holding an
     * outdated one can sign in and reach nothing, which looks like a
     * permission problem rather than like an old row.
     */
    private function administer(Tenant $tenant, User $account): void
    {
        $role = $this->accounts->roleWithCode(self::ROLE_CODE) ?? new Role(self::ROLE_CODE, self::ROLE_NAME);
        $role->redefine($this->permissionsNow());

        $this->entityManager->persist($role);
        $this->entityManager->flush();

        $held = $this->entityManager->getRepository(Membership::class)
            ->findOneBy(['user' => $account, 'role' => $role]);

        if (!$held instanceof Membership) {
            $this->entityManager->persist(new Membership($tenant, $account, $role));
            $this->entityManager->flush();
        }
    }

    /**
     * Every piece this build offers, which is what administering a business
     * means here.
     *
     * Derived from the structure rather than listed beside it. A list written
     * down would go on saying what the application used to offer, and the
     * account holding it would silently stop being able to reach whatever was
     * added afterwards - which is exactly the kind of missing right nobody
     * reports, because the page it guards simply is not there.
     *
     * @return list<string>
     */
    private function permissionsNow(): array
    {
        return array_map(
            static fn(Grant $piece): string => $piece->code(),
            $this->structure->everyPair(),
        );
    }

    /** Something to put on the page before anybody has said what to call them. */
    private function nameFrom(string $email): string
    {
        return ucfirst(explode('@', $email)[0]);
    }
}

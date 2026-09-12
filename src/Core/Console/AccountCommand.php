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
 * **Both at once is said, never implied.** --tenant together with
 * --also-installation makes the account that administers the installation the
 * owner of that business as well - the one person of a simple installation.
 * Without the switch, a role inside a business for the installation's
 * administrator is refused, and the refusal names the switch: being both is
 * allowed, but an account that became both because the command was run with
 * one option too few is the account nobody meant to have. The membership is
 * then given through
 * Trilobit\Core\Domain\Tenancy\Membership::forTheInstallationsAdministrator(),
 * so the decision is written down in the one place that makes the row.
 *
 * **What the switch does not do is make an existing member the installation's
 * administrator.** Whether an account administers the installation is settled
 * when it is made - see the constructor of Trilobit\Core\Domain\User\User - so
 * an account that already administers a business is refused either way, and
 * the installation gets an account of its own or one made both from the start.
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

    /** The switch that says the account administers the installation as well as the business --tenant names. */
    private const string ALSO_INSTALLATION = 'also-installation';

    /** Long enough that it is not worth attacking, short enough to be typed once. */
    private const int GENERATED_LENGTH = 24;

    /** What a person reads for the owner's role; the code is Trilobit\Core\Domain\User\Role::OWNER. */
    private const string ROLE_NAME = 'Owner';

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
        $this->addOption(
            self::ALSO_INSTALLATION,
            null,
            InputOption::VALUE_NONE,
            'Together with --tenant: the account administers the installation as well. Being both is a decision, '
            . 'so it is said here rather than implied.',
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
        $both = $input->getOption(self::ALSO_INSTALLATION) === true;

        if ($both && $host === null) {
            $style->error('--also-installation means "a business as well", so it needs --tenant to name the business.');
            $style->writeln(
                'Without --tenant the account administers the installation and nothing else, which is what leaving '
                . 'both out already says.',
            );

            return self::FAILURE;
        }

        // What the account is being asked to be. Administering the installation
        // is either the whole of it - no business named - or said outright
        // beside the business that is.
        $landlord = $host === null || $both;
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
        if ($account instanceof User && $account->isLandlord() !== $landlord) {
            if ($account->isLandlord()) {
                $style->error(sprintf(
                    '%s administers the installation, so it is not given a role inside a business without that being said.',
                    $email,
                ));
                $style->writeln(sprintf(
                    'One account may be both, and it is a decision rather than a side effect of naming a business. '
                    . 'Run the command again with --%s to make it the owner of the business answering at %s as well; '
                    . 'make a second account if the business is meant to be administered by somebody else.',
                    self::ALSO_INSTALLATION,
                    $host,
                ));

                return self::FAILURE;
            }

            $style->error(sprintf(
                '%s administers a business, so it cannot be made the administrator of the installation as well.',
                $email,
            ));
            $style->writeln(
                'Whether an account administers the installation is settled when the account is made, and there is '
                . 'no way to say it afterwards. Make a second account for the installation, or make one that is both '
                . 'from the start with --tenant and --' . self::ALSO_INSTALLATION . '.',
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
                landlord: $landlord,
            );
            $existing = false;
        } else {
            $account->changePassword($this->passwords->hash($password));
            $account->rename($name);
            $existing = true;
        }

        $this->accounts->save($account);

        if ($tenant instanceof Tenant) {
            $this->administer($tenant, $account, $both);
        }

        $style->success(sprintf(
            '%s can sign in as %s.',
            $existing ? 'The account that was already there' : 'A new account',
            $email,
        ));
        $style->writeln(match (true) {
            $tenant instanceof Tenant && $both => sprintf(
                'It administers the installation, and %s as well, the business answering at %s.',
                $tenant->name(),
                $host,
            ),
            $tenant instanceof Tenant => sprintf('It administers %s, the business answering at %s.', $tenant->name(), $host),
            default => 'It administers the installation, which is not a role in any business.',
        });
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
     * Makes $account the owner of $tenant: the owner's role, held there.
     *
     * The role is the whole of the application - `app:*` - and not a list of
     * what this build offers. A list would go on saying what the application
     * used to offer, and the account holding it would silently stop being able
     * to reach whatever was added afterwards; the whole of it takes in every
     * section by itself, including the ones nobody has written yet, which is
     * what owning a business means. It is honoured on this role and on no
     * other - see Trilobit\Core\Security\AccessComposition.
     *
     * The role is redefined rather than only created, so that a row under this
     * code saying anything else - edited by hand, or left by an earlier build -
     * says the whole of the application again after the command has run. The
     * row an earlier build called `administrator` is carried over by
     * Trilobit\Core\Migrations\Version20260912114800, not here.
     *
     * $both is what --also-installation said, and it is what chooses the way
     * the membership is made - not whether the account happens to administer
     * the installation. Reading the flag off the account here would turn the
     * decision back into a side effect; passing what was said means an
     * account that is the installation's administrator and was not said to be
     * both still meets the refusal in the constructor.
     */
    private function administer(Tenant $tenant, User $account, bool $both): void
    {
        $role = $this->accounts->roleWithCode(Role::OWNER) ?? new Role(Role::OWNER, self::ROLE_NAME);
        $role->redefine([new Grant($this->structure->root(), null)->code()]);

        $this->entityManager->persist($role);
        $this->entityManager->flush();

        $held = $this->entityManager->getRepository(Membership::class)
            ->findOneBy(['user' => $account, 'role' => $role]);

        if (!$held instanceof Membership) {
            $this->entityManager->persist($both
                ? Membership::forTheInstallationsAdministrator($tenant, $account, $role)
                : new Membership($tenant, $account, $role));
            $this->entityManager->flush();
        }
    }

    /** Something to put on the page before anybody has said what to call them. */
    private function nameFrom(string $email): string
    {
        return ucfirst(explode('@', $email)[0]);
    }
}

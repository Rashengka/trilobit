<?php

declare(strict_types=1);

namespace Trilobit\Core\Console;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nette\Security\Passwords;
use Nette\Utils\Random;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Trilobit\Core\Config\Mode;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Security\Accounts;
use Trilobit\Core\Security\Grant;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;
use Trilobit\Core\Seed\SeedProvider;
use Trilobit\Core\Seed\SeedsMembers;
use Trilobit\Core\Tenancy\HostTenants;
use Trilobit\Core\Tenancy\Tenancy;

/**
 * Fills an empty database on a working copy with the cases clicking through
 * the application stands on: two businesses, somebody for each way of
 * belonging to one, and whatever the modules of this build show.
 *
 * **A command and not a migration.** A migration runs wherever the schema is
 * brought up to date, production included, and runs once; what is wanted here
 * runs only where somebody asks for it and grows with what the application can
 * do. Made a migration, it would be a second migration system switched on by
 * the mode, promising by its shape something it does not do.
 *
 * **Only on a working copy**, which is Trilobit\Core\Config\Mode::mayAlterData()
 * and nothing worked out here. Staging is refused like production, because its
 * data is real and invented businesses beside it are a mess somebody has to
 * clean out of real data. The refusal comes before anything is read.
 *
 * **Only into an empty database, and it never deletes.** Empty means no
 * business and no account: those are the two things every other row hangs
 * from - a page, a menu or a role of a business belongs to one, and an account
 * is how anybody got in to make anything - so a database with neither holds
 * nothing anybody made. Counting every table instead would have Core naming
 * the tables of every module, and a stray row belonging to no business is not
 * somebody's work. Seeding again means starting from an empty database - a
 * worktree of its own, or one dropped by whoever owns it - and never this
 * command clearing one, because the database it would clear is usually
 * somebody's working copy.
 *
 * **It goes through the application, not around it.** The businesses, their
 * hosts, the installation's administrator and the owners are made by running
 * `app:tenant` and `app:account`, the commands a person would type, so the
 * seed cannot make a business or an owner those commands would not - and
 * follows them when they change. A business's own roles and the people holding
 * them have no command yet, so they are made here out of the same entities,
 * whose constructors refuse what the domain refuses - and so are the ones a
 * module names through Trilobit\Core\Seed\SeedsMembers, whose roles are made
 * of the module's own resources and therefore cannot be written here by name.
 * **Exit condition:** the
 * first command or administration screen that composes a business's role or
 * gives somebody one - the seed then goes through that instead.
 *
 * **Every password is generated and printed once**, like `app:account`'s, and
 * what is stored is a hash. None is written in the code, so a seeded database
 * copied somewhere else is not a set of accounts anybody reading this
 * repository could sign in to.
 *
 * **The hosts open on the machine the seed runs on.** `localhost` is the first
 * business, and `*.localhost` names resolve to the machine itself in the
 * browsers people develop in, so `ammonite.localhost` and `belemnite.localhost`
 * need no entry anywhere - the second is how the other business is reached.
 * `127.0.0.1` is left alone on purpose: the browser suite makes a business of
 * its own there, in the same database, and would be refused if the seed had
 * taken it.
 *
 * It does not run in a transaction. Owners are made through
 * Trilobit\Core\Security\Accounts::applicationRoleMadeIfMissing(), which is
 * not meant to run inside one, so a run that fails part of the way leaves what
 * it had made - and says so, since the next run will refuse that database.
 */
#[AsCommand(
    name: 'app:seed',
    description: 'Fills an empty database on a working copy with businesses, accounts and content to click through.',
)]
final class SeedCommand extends Command
{
    /**
     * How an account appears in the output: two spaces, the address, the
     * password, and what the account is for.
     *
     * A constant because it is a contract - Trilobit\Tests\Integration\Console\
     * SeedCommandTest signs in with what it reads out of the output, so
     * rewording the rest cannot quietly break reading the passwords back.
     * Nothing else printed here holds an address.
     */
    public const string ACCOUNT_LINE = '/^ {2}(\S+@\S+) +([A-Za-z0-9]+) {2}(\S.*)$/m';

    private const string AMMONITE = 'Ammonite Bikes';

    /** The first is where a working copy is opened anyway; the second is an alias, which is what a second domain is. */
    private const array AMMONITE_HOSTS = ['localhost', 'ammonite.localhost'];

    private const string BELEMNITE = 'Belemnite Books';

    private const array BELEMNITE_HOSTS = ['belemnite.localhost'];

    /**
     * @param list<SeedProvider> $providers what the modules of this build add,
     *     collected by tag - see Trilobit\Core\Seed\SeedProvider
     */
    public function __construct(
        private readonly Mode $mode,
        private readonly EntityManagerInterface $entityManager,
        private readonly Accounts $accounts,
        private readonly Passwords $passwords,
        private readonly HostTenants $hosts,
        private readonly Tenancy $tenancy,
        private readonly array $providers = [],
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        if (!$this->mode->mayAlterData()) {
            $style->error(sprintf('app:seed runs on a working copy only, and this deployment is %s.', $this->mode->value));
            $style->writeln(sprintf(
                '%s says which deployment this is, and only %s may be seeded. Staging is refused like production '
                . 'because its data is real, and invented businesses beside real ones are something somebody has '
                . 'to clean out of them. Nothing was read or written.',
                Mode::VARIABLE,
                Mode::Dev->value,
            ));

            return self::FAILURE;
        }

        $standing = $this->whatIsThere();
        if ($standing !== null) {
            $style->error(sprintf('The database is not empty: it holds %s.', $standing));
            $style->writeln(
                'The seed only ever fills an empty database, and it deletes nothing - the database it would clear '
                . 'is usually somebody\'s working copy. To seed again, start from an empty one: a worktree of its '
                . 'own, or a database its owner has dropped and migrated. Nothing was written.',
            );

            return self::FAILURE;
        }

        $this->runCommand('app:tenant', ['name' => self::AMMONITE, 'hosts' => self::AMMONITE_HOSTS]);
        $this->runCommand('app:tenant', ['name' => self::BELEMNITE, 'hosts' => self::BELEMNITE_HOSTS]);

        $accounts = [
            $this->throughAccountCommand('landlord@example.com', 'Tessa Trilobite', null, 'administers the installation'),
            $this->throughAccountCommand('ammonite-owner@example.com', 'Alice Ammonite', self::AMMONITE_HOSTS[0], 'owns ' . self::AMMONITE),
            $this->throughAccountCommand('belemnite-owner@example.com', 'Bruno Belemnite', self::BELEMNITE_HOSTS[0], 'owns ' . self::BELEMNITE),
        ];
        $content = [];

        $ammonite = $this->enter(self::AMMONITE_HOSTS[0]);

        // The role in between: what an editor does with the content, and
        // neither what cannot be undone nor what takes it out of the
        // application. Written as pairs rather than as the whole of the
        // section, because every resource named in the code is named as a
        // question a reader can check - see
        // tests/Architecture/EveryPermissionQuestionIsPredefinedTest - and the
        // one whole piece the code holds is the owner's, found in the tree.
        $accounts[] = $this->member(
            $ammonite,
            'ammonite-editor@example.com',
            'Eddie Echinoid',
            $this->roleOf($ammonite, 'editor', 'Content editor', [
                new Grant(Resource::Content, Privilege::View),
                new Grant(Resource::Content, Privilege::Add),
                new Grant(Resource::Content, Privilege::Edit),
                new Grant(Resource::Content, Privilege::Delete),
                new Grant(Resource::Content, Privilege::ChangePriority),
            ]),
            'keeps the content of ' . self::AMMONITE . ' and nothing else',
        );
        $accounts[] = $this->member(
            $ammonite,
            'ammonite-onlooker@example.com',
            'Nora Nautilus',
            $this->roleOf($ammonite, 'onlooker', 'Onlooker', []),
            'belongs to ' . self::AMMONITE . ' and may do nothing there',
        );
        $accounts = [...$accounts, ...$this->membersFromModules($ammonite)];
        $content[self::AMMONITE] = $this->contentOf($ammonite);

        $belemnite = $this->enter(self::BELEMNITE_HOSTS[0]);
        $accounts[] = $this->member(
            $belemnite,
            'belemnite-editor@example.com',
            'Cyril Crinoid',
            $this->roleOf($belemnite, 'editor', 'Proofreader', [
                new Grant(Resource::Content, Privilege::View),
                new Grant(Resource::Content, Privilege::Edit),
            ]),
            'reads and corrects the content of ' . self::BELEMNITE . ' - its own editor, under the same code',
        );
        $accounts = [...$accounts, ...$this->membersFromModules($belemnite)];
        $content[self::BELEMNITE] = $this->contentOf($belemnite);

        $this->report($style, $accounts, $content);

        return self::SUCCESS;
    }

    /** What makes the database somebody's, said in words, or null when it holds nothing anybody made. */
    private function whatIsThere(): ?string
    {
        // Neither is scoped by a business, so both are counted across the
        // whole installation, which is the question.
        $businesses = $this->entityManager->getRepository(Tenant::class)->count([]);
        $accounts = $this->entityManager->getRepository(User::class)->count([]);

        if ($businesses === 0 && $accounts === 0) {
            return null;
        }

        return sprintf(
            '%d %s and %d %s',
            $businesses,
            $businesses === 1 ? 'business' : 'businesses',
            $accounts,
            $accounts === 1 ? 'account' : 'accounts',
        );
    }

    /**
     * Runs another command of this console, the way a person would type it,
     * and hands back what it printed.
     *
     * @param array<string, mixed> $arguments
     */
    private function runCommand(string $name, array $arguments): string
    {
        $application = $this->getApplication() ?? throw new LogicException(
            'app:seed is made of app:tenant and app:account, so it runs inside the console that has them.',
        );

        $input = new ArrayInput(['command' => $name] + $arguments);
        $input->setInteractive(false);
        $printed = new BufferedOutput();

        if ($application->find($name)->run($input, $printed) !== self::SUCCESS) {
            throw new RuntimeException(sprintf(
                '%s refused, so the seed stopped part of the way and the database holds what was made before it. '
                    . "Start again from an empty one. What %s said:\n\n%s",
                $name,
                $name,
                $printed->fetch(),
            ));
        }

        return $printed->fetch();
    }

    /**
     * An account made by `app:account` - the installation's administrator
     * without a host, the owner of the business answering at $host with one -
     * and the password it printed.
     *
     * @return array{string, string, string} the address, the password and what the account is for
     */
    private function throughAccountCommand(string $email, string $name, ?string $host, string $role): array
    {
        $arguments = ['email' => $email, '--name' => $name];
        if ($host !== null) {
            $arguments['--tenant'] = $host;
        }

        $printed = $this->runCommand('app:account', $arguments);
        if (preg_match(AccountCommand::PASSWORD_LINE, $printed, $password) !== 1) {
            throw new LogicException(sprintf(
                "app:account made %s and printed no password in the shape it promises:\n\n%s",
                $email,
                $printed,
            ));
        }

        return [$email, $password[1], $role];
    }

    /** The business answering at $host, with the process now working inside it - so that what follows is its. */
    private function enter(string $host): Tenant
    {
        $id = $this->hosts->tenantAt($host) ?? throw new LogicException(sprintf(
            'app:tenant was run for %s and no business answers there.',
            $host,
        ));
        $this->tenancy->enter($id);

        return $this->entityManager->find(Tenant::class, $id) ?? throw new LogicException(sprintf(
            'A business answers at %s and cannot be read back.',
            $host,
        ));
    }

    /**
     * A role $business composes for itself out of $grants.
     *
     * @param list<Grant> $grants
     */
    private function roleOf(Tenant $business, string $code, string $name, array $grants): Role
    {
        $role = Role::ofBusiness($business, $code, $name, array_map(
            static fn(Grant $grant): string => $grant->code(),
            $grants,
        ));
        $this->entityManager->persist($role);
        $this->entityManager->flush();

        return $role;
    }

    /**
     * Somebody who belongs to $business by holding $role there, with a
     * password generated the way `app:account` generates one.
     *
     * @return array{string, string, string} the address, the password and what the account is for
     */
    private function member(Tenant $business, string $email, string $name, Role $role, string $description): array
    {
        $password = Random::generate(AccountCommand::GENERATED_LENGTH, 'a-zA-Z0-9');

        $account = new User($email, $this->passwords->hash($password), $name, new DateTimeImmutable());
        $this->accounts->save($account);

        $this->entityManager->persist(new Membership($business, $account, $role));
        $this->entityManager->flush();

        return [$email, $password, $description];
    }

    /**
     * The people the modules of this build want in $business, made the way
     * member() makes the seed's own, at an address beginning with the
     * business's first word - see Trilobit\Core\Seed\SeedsMembers.
     *
     * @return list<array{string, string, string}> the address, the password and what the account is for
     */
    private function membersFromModules(Tenant $business): array
    {
        $prefix = strtolower(explode(' ', $business->name())[0]);

        $made = [];
        foreach ($this->providers as $provider) {
            if (!$provider instanceof SeedsMembers) {
                continue;
            }

            foreach ($provider->members($business) as $member) {
                $made[] = $this->member(
                    $business,
                    sprintf('%s-%s@example.com', $prefix, $member->mailbox),
                    $member->name,
                    $this->roleOf($business, $member->roleCode, $member->roleName, $member->grants),
                    $member->description,
                );
            }
        }

        return $made;
    }

    /** @return list<string> what the modules of this build made in $business, which the process is inside */
    private function contentOf(Tenant $business): array
    {
        $made = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->seed($business) as $line) {
                $made[] = $line;
            }
        }

        return $made;
    }

    /**
     * @param list<array{string, string, string}> $accounts
     * @param array<string, list<string>> $content by the business's name
     */
    private function report(SymfonyStyle $style, array $accounts, array $content): void
    {
        $style->success(sprintf('Seeded two businesses and %d accounts.', count($accounts)));

        $style->section('Businesses');
        $style->writeln(sprintf('  %s answers at %s', self::AMMONITE, implode(', ', self::AMMONITE_HOSTS)));
        $style->writeln(sprintf('  %s answers at %s', self::BELEMNITE, implode(', ', self::BELEMNITE_HOSTS)));
        $style->writeln('');
        $style->writeln('Open them at the port this checkout is served on; see "Seeding a working copy" in README.md.');

        $style->section('Accounts');
        $style->writeln('Each password is shown this once. What is stored is a hash of it.');
        $style->writeln('');
        foreach ($accounts as [$email, $password, $description]) {
            $style->writeln(sprintf('  %-32s  %s  %s', $email, $password, $description));
        }

        $style->section('Content');
        if ($this->providers === []) {
            $style->writeln('No module of this build adds content to the seed.');

            return;
        }

        foreach ($content as $business => $lines) {
            $style->writeln($business . ':');
            foreach ($lines as $line) {
                $style->writeln('  - ' . $line);
            }
        }
    }
}

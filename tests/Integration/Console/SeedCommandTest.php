<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Console;

use Contributte\Console\Application;
use Doctrine\ORM\EntityManagerInterface;
use Nette\DI\Container;
use Nette\Security\Authenticator;
use Nette\Security\Passwords;
use Nette\Security\User as SignedIn;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Config\Mode;
use Trilobit\Core\Console\SeedCommand;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Security\Accounts;
use Trilobit\Core\Security\Grant;
use Trilobit\Core\Security\Identity;
use Trilobit\Core\Security\Landlords;
use Trilobit\Core\Security\Permissions;
use Trilobit\Core\Security\PermissionStructure;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;
use Trilobit\Core\Tenancy\HostTenants;
use Trilobit\Core\Tenancy\Tenancy;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * `bin/trilobit app:seed`, which fills an empty database on a working copy with
 * the cases clicking through the application stands on.
 *
 * What is asserted is what the seed is for, asked the way the application asks
 * it rather than by counting rows: that every account it prints signs in with
 * the password printed beside it, that each of them may do exactly what it is
 * there to show, and that nothing one business holds reaches into the other.
 * A seed whose rows were all present and whose roles quietly granted something
 * else would pass a count and fail the person clicking through it.
 *
 * The two refusals are asserted as refusals that leave nothing behind: a
 * database that is not empty, and a deployment that is not a working copy.
 * The second is built the way a deployment is - a container compiled in that
 * mode - rather than by handing the command a mode, so that what is tested is
 * the question the build answers and not a parameter somebody could pass.
 *
 * This suite runs without any optional module, so it says nothing about
 * content; what a module adds to the seed is asserted beside that module.
 */
#[CoversNothing]
final class SeedCommandTest extends TestCase
{
    private const string LANDLORD = 'landlord@example.com';

    private const string AMMONITE_OWNER = 'ammonite-owner@example.com';

    private const string AMMONITE_EDITOR = 'ammonite-editor@example.com';

    private const string AMMONITE_ONLOOKER = 'ammonite-onlooker@example.com';

    private const string BELEMNITE_OWNER = 'belemnite-owner@example.com';

    private const string BELEMNITE_EDITOR = 'belemnite-editor@example.com';

    /** @var list<string> */
    private array $schemas = [];

    /** @var list<Container> */
    private array $containers = [];

    /** What the mode variable held before this test set it - false when it was not set - or null while untouched. */
    private string|false|null $modeBefore = null;

    protected function tearDown(): void
    {
        if ($this->modeBefore !== null) {
            putenv($this->modeBefore === false ? Mode::VARIABLE : Mode::VARIABLE . '=' . $this->modeBefore);
            $this->modeBefore = null;
        }

        foreach ($this->containers as $container) {
            $container->getByType(SignedIn::class)->logout(true);
        }

        $this->containers = [];

        foreach ($this->schemas as $schema) {
            Database::drop($schema);
        }

        $this->schemas = [];
    }

    /**
     * Two businesses, the first at two hosts - the second an alias of the
     * first, which is what a business with two domains is - and the second at
     * a host of its own, so that the other one can be opened beside it.
     */
    public function testItMakesTwoBusinessesAndTheHostsTheyAnswerAt(): void
    {
        $container = $this->seeded();
        $hosts = $container->getByType(HostTenants::class);

        $ammonite = $this->businessCalled($container, 'Ammonite Bikes');
        $belemnite = $this->businessCalled($container, 'Belemnite Books');

        self::assertSame($ammonite->id(), $hosts->tenantAt('localhost'));
        self::assertSame($ammonite->id(), $hosts->tenantAt('ammonite.localhost'));
        self::assertSame($belemnite->id(), $hosts->tenantAt('belemnite.localhost'));
        self::assertCount(2, $container->getByType(EntityManagerInterface::class)->getRepository(Tenant::class)->findAll());
    }

    /**
     * The host the browser suite makes a business of its own at, in the same
     * database - see playwright.config.ts. Claimed here, that suite's
     * `app:tenant` would be refused and the suite could not run against a
     * seeded working copy at all.
     */
    public function testItLeavesTheBrowserSuitesHostAlone(): void
    {
        $container = $this->seeded();

        self::assertNull($container->getByType(HostTenants::class)->tenantAt('127.0.0.1'));
    }

    /**
     * Every account the output names signs in with the password printed
     * beside it, and no two of them share one. The output is the only place a
     * password exists: what is stored is a hash.
     */
    public function testEveryAccountItPrintsSignsInWithThePasswordBesideIt(): void
    {
        $container = $this->seeded($output);
        $passwords = $this->passwordsIn($output);

        self::assertSame(
            [
                self::AMMONITE_EDITOR,
                self::AMMONITE_ONLOOKER,
                self::AMMONITE_OWNER,
                self::BELEMNITE_EDITOR,
                self::BELEMNITE_OWNER,
                self::LANDLORD,
            ],
            $this->sorted(array_keys($passwords)),
        );
        self::assertCount(count($passwords), array_unique($passwords), 'two accounts were given one password');

        $authenticator = $container->getByType(Authenticator::class);
        foreach ($passwords as $email => $password) {
            self::assertInstanceOf(Identity::class, $authenticator->authenticate($email, $password), $email);
        }
    }

    /**
     * The passwords are generated on every run, never written down: two
     * installations seeded one after the other share none of them, and none of
     * them is anywhere in the sources.
     */
    public function testThePasswordsAreGeneratedAndNotWrittenInTheCode(): void
    {
        $this->seeded($first);
        $this->seeded($second, 'again');

        $firstPasswords = $this->passwordsIn($first);
        $secondPasswords = $this->passwordsIn($second);
        self::assertSame([], array_values(array_intersect($firstPasswords, $secondPasswords)));

        $sources = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            Bootstrap::rootDirectory() . '/src',
            \FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($sources as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $contents = (string) file_get_contents($file->getPathname());
            foreach ($firstPasswords as $email => $password) {
                self::assertStringNotContainsString($password, $contents, sprintf(
                    'the password of %s is written in %s',
                    $email,
                    $file->getPathname(),
                ));
            }
        }
    }

    /** The installation's administrator is the one account above the businesses, and holds nothing in either. */
    public function testTheInstallationsAdministratorHoldsNoRoleInAnyBusiness(): void
    {
        $container = $this->seeded($output);

        $landlord = $container->getByType(Accounts::class)->withEmail(self::LANDLORD);
        self::assertInstanceOf(User::class, $landlord);
        self::assertTrue($landlord->isLandlord());

        $this->signIn($container, self::LANDLORD, $this->passwordsIn($output));
        self::assertTrue($container->getByType(Landlords::class)->isLandlord());

        foreach (['Ammonite Bikes', 'Belemnite Books'] as $name) {
            Tenants::switchTo($container, $this->businessCalled($container, $name));
            self::assertSame([], $this->membershipsOf($container, self::LANDLORD), $name);
            self::assertSame([], $this->allowedPairs($container), $name);
        }
    }

    /** Each owner may do everything in their own business and nothing in the other one. */
    public function testEachOwnerOwnsTheirBusinessAndNothingElse(): void
    {
        $container = $this->seeded($output);
        $passwords = $this->passwordsIn($output);
        $everyPair = $this->pairs(PermissionStructure::of(Bootstrap::rootDirectory())->everyPair());

        foreach ([self::AMMONITE_OWNER => ['Ammonite Bikes', 'Belemnite Books'], self::BELEMNITE_OWNER => ['Belemnite Books', 'Ammonite Bikes']] as $email => [$own, $other]) {
            $this->signIn($container, $email, $passwords);

            Tenants::switchTo($container, $this->businessCalled($container, $own));
            self::assertSame(['owner'], $this->membershipsOf($container, $email));
            self::assertSame($everyPair, $this->allowedPairs($container), $email . ' in ' . $own);

            Tenants::switchTo($container, $this->businessCalled($container, $other));
            self::assertSame([], $this->allowedPairs($container), $email . ' in ' . $other);
        }
    }

    /**
     * The role in between: one section of the administration and nothing
     * beside it. It is the business's own role rather than the application's,
     * and it may do what an editor does with the content - which opens the
     * administration it is in - but not purge it or export it, and nothing to
     * the accounts or to where a visitor is sent.
     */
    public function testTheEditorMayKeepTheContentAndNothingElse(): void
    {
        $container = $this->seeded($output);
        $ammonite = $this->businessCalled($container, 'Ammonite Bikes');

        $this->signIn($container, self::AMMONITE_EDITOR, $this->passwordsIn($output));
        Tenants::switchTo($container, $ammonite);

        $role = $this->roleHeldBy($container, self::AMMONITE_EDITOR);
        self::assertSame('editor', $role->code());
        self::assertSame($ammonite->id(), $role->business()?->id(), 'the editor\'s role is the business\'s own');
        $editing = [
            new Grant(Resource::Content, Privilege::View),
            new Grant(Resource::Content, Privilege::Add),
            new Grant(Resource::Content, Privilege::Edit),
            new Grant(Resource::Content, Privilege::Delete),
            new Grant(Resource::Content, Privilege::ChangePriority),
        ];
        self::assertSame($this->pairs($editing), $this->sorted($role->permissions()));

        // Any right opens what it falls under, so the content opens the
        // administration and the application it is in - and nothing else is
        // added: not the accounts, and not purging or exporting the content.
        self::assertSame(
            $this->pairs([
                new Grant(Resource::App, Privilege::View),
                new Grant(Resource::Administration, Privilege::View),
                ...$editing,
            ]),
            $this->allowedPairs($container),
        );
    }

    /** Nothing an account holds in one business answers for it in the other. */
    public function testTheEditorOfOneBusinessIsRefusedEverythingInTheOther(): void
    {
        $container = $this->seeded($output);

        $this->signIn($container, self::AMMONITE_EDITOR, $this->passwordsIn($output));
        Tenants::switchTo($container, $this->businessCalled($container, 'Belemnite Books'));

        self::assertSame([], $this->membershipsOf($container, self::AMMONITE_EDITOR));
        self::assertSame([], $this->allowedPairs($container));
    }

    /** Somebody who belongs to the business and may do nothing in it: signed in, and refused every page. */
    public function testTheOnlookerBelongsToTheBusinessAndMayDoNothing(): void
    {
        $container = $this->seeded($output);
        $ammonite = $this->businessCalled($container, 'Ammonite Bikes');

        $this->signIn($container, self::AMMONITE_ONLOOKER, $this->passwordsIn($output));
        Tenants::switchTo($container, $ammonite);

        $role = $this->roleHeldBy($container, self::AMMONITE_ONLOOKER);
        self::assertSame($ammonite->id(), $role->business()?->id());
        self::assertSame([], $role->permissions());
        self::assertSame([], $this->allowedPairs($container));
    }

    /**
     * The second business composes a role under the same code as the first
     * and means something narrower by it - which two businesses may, now that
     * a code is unique within one. Its holder reads and corrects the content
     * there and nowhere else.
     */
    public function testTheSecondBusinessMeansSomethingElseByTheSameCode(): void
    {
        $container = $this->seeded($output);
        $belemnite = $this->businessCalled($container, 'Belemnite Books');

        $this->signIn($container, self::BELEMNITE_EDITOR, $this->passwordsIn($output));
        Tenants::switchTo($container, $belemnite);

        $role = $this->roleHeldBy($container, self::BELEMNITE_EDITOR);
        self::assertSame('editor', $role->code());
        self::assertSame($belemnite->id(), $role->business()?->id());
        self::assertSame(
            $this->pairs([
                new Grant(Resource::App, Privilege::View),
                new Grant(Resource::Administration, Privilege::View),
                new Grant(Resource::Content, Privilege::View),
                new Grant(Resource::Content, Privilege::Edit),
            ]),
            $this->allowedPairs($container),
        );

        Tenants::switchTo($container, $this->businessCalled($container, 'Ammonite Bikes'));
        self::assertSame([], $this->allowedPairs($container));
    }

    /** Run again, it refuses by name and adds nothing: seeding twice would double every business it made. */
    public function testASecondRunIsRefusedAndChangesNothing(): void
    {
        $container = $this->seeded();
        $before = $this->counts($container);

        [$status, $output] = $this->outcomeOf($container);

        self::assertSame(Command::FAILURE, $status, $output);
        self::assertStringContainsString('not empty', $output);
        self::assertSame($before, $this->counts($container));
    }

    /**
     * An account on its own is enough to make a database somebody's: an
     * installation whose administrator signed up and made no business yet is
     * not one to fill with somebody else's idea of one.
     */
    public function testADatabaseWithAnAccountAndNoBusinessIsNotEmpty(): void
    {
        $container = $this->installation('dev');
        $container->getByType(Accounts::class)->save(new User(
            'somebody@example.com',
            $container->getByType(Passwords::class)->hash('not-a-password-anybody-uses'),
            'Somebody',
            new \DateTimeImmutable('2026-09-13T08:00:00+00:00'),
            landlord: true,
        ));

        [$status, $output] = $this->outcomeOf($container);

        self::assertSame(Command::FAILURE, $status, $output);
        self::assertStringContainsString('not empty', $output);
        self::assertSame(['businesses' => 0, 'accounts' => 1], $this->counts($container));
    }

    /**
     * Anywhere but a working copy the command refuses before it reads or
     * writes anything, and says which deployment it is in. Staging is refused
     * exactly like production, because its data is real.
     */
    #[DataProvider('deploymentsThatAreNotAWorkingCopy')]
    public function testOutsideAWorkingCopyItIsRefusedByName(string $mode): void
    {
        $container = $this->installation($mode);

        [$status, $output] = $this->outcomeOf($container);

        self::assertSame(Command::FAILURE, $status, $output);
        self::assertStringContainsString($mode, $output);
        self::assertStringContainsString('TRILOBIT_ENV', $output);
        self::assertSame(['businesses' => 0, 'accounts' => 0], $this->counts($container));
    }

    /** @return iterable<string, array{string}> */
    public static function deploymentsThatAreNotAWorkingCopy(): iterable
    {
        yield 'staging' => ['staging'];
        yield 'prod' => ['prod'];
    }

    /**
     * A seeded installation, with what the command printed in $output.
     *
     * @param-out string $output
     */
    private function seeded(?string &$output = null, string $variant = ''): Container
    {
        $container = $this->installation('dev', $variant);

        [$status, $output] = $this->outcomeOf($container);
        self::assertSame(Command::SUCCESS, $status, $output);

        return $container;
    }

    /**
     * An empty, migrated installation built in $mode.
     *
     * The mode is stated rather than taken from the machine: a clone has no
     * .env and would be production, a developer's machine says dev, and a
     * suite taking either would assert one thing here and another in CI.
     *
     * It is put into the process environment, which wins over .env - the way
     * Trilobit\Tests\Database::schemaFor() names the schema - so that the
     * build reads everything else from the machine as a deployment would, and
     * this test reads no value of it at all.
     */
    private function installation(string $mode, string $variant = ''): Container
    {
        $this->schemas[] = Database::schemaFor(self::class, $variant);

        $this->modeBefore ??= getenv(Mode::VARIABLE);
        putenv(Mode::VARIABLE . '=' . $mode);

        $container = Boot::container(ModuleList::of([], Bootstrap::rootDirectory()));
        Migrations::run($container);

        // Asked for so that the security services are built the way a request
        // builds them; the command itself signs nobody in.
        self::assertInstanceOf(SignedIn::class, $container->getByType(SignedIn::class));

        return $this->containers[] = $container;
    }

    /**
     * Runs the command the way the console runs it: found by its name in the
     * application the build registers it in, which is also the application it
     * runs the commands it is made of through.
     *
     * @return array{int, string}
     */
    private function outcomeOf(Container $container): array
    {
        $tester = new CommandTester($container->getByType(Application::class)->find('app:seed'));
        $status = $tester->execute([], ['interactive' => false, 'capture_stderr_separately' => true]);

        return [$status, $tester->getDisplay() . $tester->getErrorOutput()];
    }

    /** @return array<string, string> the password printed for each account, by its address */
    private function passwordsIn(string $output): array
    {
        preg_match_all(SeedCommand::ACCOUNT_LINE, $output, $lines, PREG_SET_ORDER);
        self::assertNotSame([], $lines, "no account line in the output:\n" . $output);

        $passwords = [];
        foreach ($lines as $line) {
            $passwords[$line[1]] = $line[2];
        }

        return $passwords;
    }

    /** @param array<string, string> $passwords */
    private function signIn(Container $container, string $email, array $passwords): void
    {
        $signedIn = $container->getByType(SignedIn::class);
        $signedIn->logout(true);
        $signedIn->login($email, $passwords[$email] ?? self::fail('no password was printed for ' . $email));
    }

    private function businessCalled(Container $container, string $name): Tenant
    {
        $tenant = $container->getByType(EntityManagerInterface::class)
            ->getRepository(Tenant::class)
            ->findOneBy(['name' => $name]);
        self::assertInstanceOf(Tenant::class, $tenant, 'there is no business called ' . $name);

        return $tenant;
    }

    /** @return list<string> the codes of the roles $email holds in the business the process is in */
    private function membershipsOf(Container $container, string $email): array
    {
        $account = $container->getByType(Accounts::class)->withEmail($email);
        self::assertInstanceOf(User::class, $account);

        $held = $container->getByType(EntityManagerInterface::class)
            ->getRepository(Membership::class)
            ->findBy(['user' => $account, 'tenant' => $container->getByType(Tenancy::class)->current()]);

        return $this->sorted(array_map(static fn(Membership $membership): string => $membership->role()->code(), $held));
    }

    /** The one role $email holds in the business the process is in. */
    private function roleHeldBy(Container $container, string $email): Role
    {
        $account = $container->getByType(Accounts::class)->withEmail($email);
        self::assertInstanceOf(User::class, $account);

        $held = $container->getByType(EntityManagerInterface::class)
            ->getRepository(Membership::class)
            ->findBy(['user' => $account]);
        self::assertCount(1, $held, $email . ' holds one role here');

        return $held[0]->role();
    }

    /**
     * Every pair this build offers that the signed-in account is allowed in
     * the business the process is in, asked the way the application asks.
     *
     * @return list<string>
     */
    private function allowedPairs(Container $container): array
    {
        $permissions = $container->getByType(Permissions::class);

        $allowed = [];
        foreach (PermissionStructure::of(Bootstrap::rootDirectory())->everyPair() as $pair) {
            self::assertInstanceOf(Privilege::class, $pair->privilege);
            if ($permissions->isAllowed($pair->resource, $pair->privilege)) {
                $allowed[] = $pair;
            }
        }

        return $this->pairs($allowed);
    }

    /**
     * @param list<Grant> $grants
     * @return list<string>
     */
    private function pairs(array $grants): array
    {
        return $this->sorted(array_map(static fn(Grant $grant): string => $grant->code(), $grants));
    }

    /** @return array{businesses: int, accounts: int} */
    private function counts(Container $container): array
    {
        $entityManager = $container->getByType(EntityManagerInterface::class);

        return [
            'businesses' => count($entityManager->getRepository(Tenant::class)->findAll()),
            'accounts' => count($entityManager->getRepository(User::class)->findAll()),
        ];
    }

    /**
     * @param array<array-key, string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        $values = array_values($values);
        sort($values);

        return $values;
    }
}

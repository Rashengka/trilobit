<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Setup;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Dom\HTMLDocument;
use Nette\Application\BadRequestException;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\RedirectResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\DI\Container;
use Nette\Security\Authenticator;
use Nette\Security\Passwords;
use Nette\Security\User as SignedIn;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Security\Accounts;
use Trilobit\Core\Security\Identity;
use Trilobit\Core\Setup\Installer;
use Trilobit\Core\Tenancy\HostTenants;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * The setup wizard at /_setup, which takes a freshly uploaded checkout to an
 * installation somebody can sign in to (.ai/plans/23-instalace-na-zelene-louce.md).
 *
 * What it does is read off the database and nothing else, so every case here
 * starts from a database in a particular state and asks the page what it
 * offers: nothing migrated, migrated with nobody administering the
 * installation, and migrated with somebody who does. The last is the one that
 * matters most - decision O3 closes the wizard for good the moment the
 * installation has an administrator, and a wizard that stayed open would be a
 * public page able to make one.
 *
 * Requests are run through the presenter the way AdministrationTest runs them,
 * with the Sec-Fetch-Site header a browser posting the form sends: nette/forms
 * checks that in place of a token, and without it every submission here would
 * be refused as coming from another site.
 */
#[CoversNothing]
final class SetupWizardTest extends TestCase
{
    private const string WIZARD = 'Core:Setup:Wizard';

    /** What the wizard makes the first business answer at: the host the request arrived at. */
    private const string HOST = 'localhost';

    private const string EMAIL = 'founder@example.com';

    /** How long the second visitor's process gets to reach the claim; see AccountCommandTest for the number. */
    private const int SECONDS_TO_REACH_THE_CLAIM = 30;

    private string $schema = '';

    private ?Container $container = null;

    /** @var array<string, mixed> what $_SERVER held for the two keys this suite sets */
    private array $server = [];

    protected function setUp(): void
    {
        foreach (['HTTP_SEC_FETCH_SITE', 'HTTP_HOST'] as $key) {
            $this->server[$key] = $_SERVER[$key] ?? null;
        }

        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
        $_SERVER['HTTP_HOST'] = self::HOST;
    }

    protected function tearDown(): void
    {
        foreach ($this->server as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }

        $this->container?->getByType(SignedIn::class)->logout(true);
        $this->container = null;

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    /** An empty database has nothing to sign in to, so the first thing offered is to make its tables. */
    public function testAnEmptyDatabaseIsOfferedTheInstallation(): void
    {
        $this->emptyDatabase();

        $page = $this->pageOf($this->get());

        self::assertNotNull($page->querySelector('[data-testid="setup-install"]'), 'nothing offered to install');
        self::assertNull($page->querySelector('[data-testid="setup-administrator"]'), 'an account offered before there is a table for it');
    }

    public function testInstallingRunsTheMigrationsAndGoesOnToTheFirstAdministrator(): void
    {
        $this->emptyDatabase();

        $response = $this->post('install', ['install' => 'Install']);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertContains('core_user', Database::tablesIn($this->schema));
        self::assertContains('core_migration', Database::tablesIn($this->schema));

        $page = $this->pageOf($this->get());
        self::assertNotNull($page->querySelector('[data-testid="setup-administrator"]'));
        self::assertNull($page->querySelector('[data-testid="setup-install"]'));
    }

    /**
     * Decision O4: a database that is already migrated - by `bin/trilobit
     * migrations:migrate`, or by an earlier visit that stopped half-way - goes
     * on from the account rather than being refused or migrated again.
     */
    public function testAMigratedDatabaseWithNobodyAdministeringItGoesOnFromTheAccount(): void
    {
        $this->migratedDatabase();

        $page = $this->pageOf($this->get());

        self::assertNotNull($page->querySelector('[data-testid="setup-administrator"]'));
        self::assertNull($page->querySelector('[data-testid="setup-install"]'));
        $host = $page->querySelector('[data-testid="setup-host"]');
        self::assertNotNull($host, 'the page does not say where the business will answer');
        self::assertStringContainsString(self::HOST, $host->textContent ?? '');
    }

    /**
     * Decision O1: the wizard makes the account and sends its owner to sign in
     * with it. Nobody is signed in on the way - an identity is made in one
     * place, Trilobit\Core\Security\Authenticator, and signing in is the first
     * moment the chosen password is shown to work.
     */
    public function testFinishingSendsToTheSignInPageWithNobodySignedIn(): void
    {
        $container = $this->migratedDatabase();
        $password = $this->password();

        $response = $this->finish($password, 'both');

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('admin/sign-in', $response->getUrl());
        self::assertFalse($container->getByType(SignedIn::class)->isLoggedIn(), 'the wizard signed somebody in');

        $identity = $container->getByType(Authenticator::class)->authenticate(self::EMAIL, $password);
        self::assertInstanceOf(Identity::class, $identity);
    }

    public function testFinishingMakesTheInstallationsAdministratorAndTheFirstBusiness(): void
    {
        $container = $this->migratedDatabase();

        $this->finish($this->password(), 'installation');

        $account = $container->getByType(Accounts::class)->withEmail(self::EMAIL);
        self::assertInstanceOf(User::class, $account);
        self::assertTrue($account->isLandlord());
        self::assertSame('Ada Ammonite', $account->name());
        self::assertStringStartsWith('$argon2id$', $account->passwordHash(), 'hashed some other way than app:password hashes');

        $business = $container->getByType(EntityManagerInterface::class)
            ->getRepository(Tenant::class)
            ->findOneBy(['name' => 'Ammonite Bikes']);
        self::assertInstanceOf(Tenant::class, $business);
        self::assertSame($business->id(), $container->getByType(HostTenants::class)->tenantAt(self::HOST));
    }

    /** The simple installation: one person who is both, said outright by the choice on the form. */
    public function testTheSimpleInstallationMakesTheAdministratorTheBusinessesOwnerAsWell(): void
    {
        $container = $this->migratedDatabase();

        $this->finish($this->password(), 'both');

        $memberships = $this->membershipsIn($container);
        self::assertCount(1, $memberships);
        self::assertSame(self::EMAIL, $memberships[0]->user()->email());
        self::assertSame(Role::OWNER, $memberships[0]->role()->code());
        self::assertSame(['app:*'], $memberships[0]->role()->permissions());
    }

    /** The separated installation: the business is made, and somebody else is going to run it. */
    public function testTheSeparatedInstallationGivesTheAdministratorNothingInTheBusiness(): void
    {
        $container = $this->migratedDatabase();

        $this->finish($this->password(), 'installation');

        self::assertSame([], $this->membershipsIn($container));
    }

    /**
     * Being both is a decision (.ai/plans/23-instalace-na-zelene-louce.md, on
     * what joining the two scopes costs), so the form has no answer chosen in
     * advance and refuses to guess one.
     */
    public function testWhichOfTheTwoInstallationsItIsHasToBeSaid(): void
    {
        $container = $this->migratedDatabase();

        $response = $this->finish($this->password(), null);

        self::assertInstanceOf(TextResponse::class, $response);
        self::assertNull($container->getByType(Accounts::class)->withEmail(self::EMAIL));
    }

    /**
     * Decision O3: once the installation has an administrator, the wizard is
     * not there. It answers the way an address nobody claims answers - 404,
     * like the style guide switched off - rather than 403, which would tell
     * whoever is probing that there is something here worth asking about.
     */
    public function testOnceTheInstallationHasAnAdministratorTheWizardIsNotFound(): void
    {
        $container = $this->migratedDatabase();
        $this->anAdministratorOfTheInstallation($container);

        $this->assertNotFound(fn(): Response => $this->get());
    }

    /**
     * Posting to it is not a way round that: the form is refused as not found
     * before anything is read off it, and nothing is written.
     */
    public function testNorDoesItTakeAnythingPostedToIt(): void
    {
        $container = $this->migratedDatabase();
        $this->anAdministratorOfTheInstallation($container);

        $this->assertNotFound(fn(): Response => $this->finish($this->password(), 'both'));
        $this->assertNotFound(fn(): Response => $this->post('install', ['install' => 'Install']));

        self::assertNull($container->getByType(Accounts::class)->withEmail(self::EMAIL));
        self::assertCount(0, $container->getByType(EntityManagerInterface::class)->getRepository(Tenant::class)->findAll());
    }

    /**
     * What closes it is an administrator of the installation, and only that
     * (decision O3 names the kind of account). An installation holding only
     * the administrator of a business is still one nobody looks after as a
     * whole, and the wizard is still how it gets somebody who does.
     */
    public function testAnAdministratorOfABusinessAloneDoesNotCloseIt(): void
    {
        $container = $this->migratedDatabase();
        $container->getByType(Accounts::class)->save(new User(
            'owner@example.com',
            $container->getByType(Passwords::class)->hash($this->password()),
            'Olga Owner',
            new \DateTimeImmutable('2026-09-13T08:00:00+00:00'),
        ));

        self::assertNotNull($this->pageOf($this->get())->querySelector('[data-testid="setup-administrator"]'));
    }

    /** The same rule as `app:password`: at least twelve characters, and nothing else about its shape. */
    public function testAPasswordShorterThanTwelveCharactersIsRefused(): void
    {
        $this->assertRefused('eleven-char', 'eleven-char');
    }

    public function testThePasswordCannotBeTheAddress(): void
    {
        $this->assertRefused(strtoupper(self::EMAIL), strtoupper(self::EMAIL));
    }

    /** Asked twice, because a password typed hidden is the one kind of typo nobody can see. */
    public function testThePasswordHasToBeTypedTheSameTwice(): void
    {
        $this->assertRefused($this->password(), $this->password());
    }

    /**
     * The claim is held by the database and not by the wizard asking first:
     * the installer refuses a second completion by itself, whoever calls it.
     */
    public function testASecondCompletionIsRefusedByTheDatabaseEvenPastTheWizard(): void
    {
        $container = $this->migratedDatabase();
        $installer = $container->getByType(Installer::class);

        self::assertTrue($installer->complete(self::EMAIL, 'Ada Ammonite', $this->password(), 'Ammonite Bikes', self::HOST, true));
        self::assertFalse($installer->complete('second@example.com', 'Bea Belemnite', $this->password(), 'Belemnite Books', 'belemnite.localhost', true));

        $container->getByType(EntityManagerInterface::class)->clear();
        self::assertNull($container->getByType(Accounts::class)->withEmail('second@example.com'));
        self::assertCount(1, $container->getByType(EntityManagerInterface::class)->getRepository(Tenant::class)->findAll());
    }

    /**
     * Two visitors of a fresh installation finishing at the same moment: only
     * one of them becomes its administrator, and the other one makes nothing.
     *
     * The moment is made rather than hoped for, the way
     * AccountCommandTest::testARoleAnotherRunMadeMeanwhileIsTakenRatherThanMadeTwice
     * makes it. This test is the first visitor: it has taken the claim and
     * holds its transaction open. The second visitor is a process of its own,
     * which asked the wizard first and was told it was open - nothing has been
     * committed yet - and goes to take the claim. It is let go only once the
     * server shows it waiting on that row, and then the first commits. A check
     * "is there an administrator yet?" before writing would have let it
     * through; what refuses it is the key the claim is written under.
     */
    public function testOfTwoVisitorsFinishingAtOnceOnlyOneBecomesTheAdministrator(): void
    {
        $container = $this->migratedDatabase();
        $connection = $container->getByType(Connection::class);

        $connection->beginTransaction();
        $connection->insert('core_setup_completion', ['id' => 1, 'completed_at' => '2026-09-13 10:00:00']);

        [$met, $status, $output] = $this->finishedElsewhereOnceItWaits($connection, 'second@example.com');

        self::assertTrue($met, 'the other visitor never got as far as the claim, so it never met this one: ' . $output);
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('outcome:refused', $output);

        $entityManager = $container->getByType(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertNull($container->getByType(Accounts::class)->withEmail('second@example.com'));
        self::assertCount(0, $entityManager->getRepository(Tenant::class)->findAll(), 'the refused visitor made a business');
    }

    private function assertRefused(string $password, string $again): void
    {
        $container = $this->migratedDatabase();

        $response = $this->finish($password, 'both', $again);

        self::assertInstanceOf(TextResponse::class, $response, 'the form was taken');
        self::assertNull($container->getByType(Accounts::class)->withEmail(self::EMAIL));

        $source = $response->getSource();
        self::assertInstanceOf(\Stringable::class, $source);
        self::assertStringNotContainsString($password, (string) $source, 'the password was written back into the page');
        self::assertNotNull(
            $this->pageOf($response)->querySelector('[data-testid="setup-administrator"] .c-field__error, [data-testid="setup-administrator"] .c-notice'),
            'the form was refused without saying why',
        );
    }

    /** @param callable(): Response $request */
    private function assertNotFound(callable $request): void
    {
        try {
            $request();
        } catch (BadRequestException $notFound) {
            self::assertSame(404, $notFound->getHttpCode());

            return;
        }

        self::fail('the wizard answered after the installation had an administrator');
    }

    private function emptyDatabase(): Container
    {
        $this->schema = Database::schemaFor(self::class);

        return $this->container = Boot::coreAlone();
    }

    private function migratedDatabase(): Container
    {
        $container = $this->emptyDatabase();
        Migrations::run($container);

        return $container;
    }

    private function anAdministratorOfTheInstallation(Container $container): void
    {
        $container->getByType(Accounts::class)->save(new User(
            'landlord@example.com',
            $container->getByType(Passwords::class)->hash($this->password()),
            'Lars Landlord',
            new \DateTimeImmutable('2026-09-13T08:00:00+00:00'),
            landlord: true,
        ));
    }

    /** @return list<Membership> */
    private function membershipsIn(Container $container): array
    {
        $business = $container->getByType(EntityManagerInterface::class)
            ->getRepository(Tenant::class)
            ->findOneBy(['name' => 'Ammonite Bikes']);
        self::assertInstanceOf(Tenant::class, $business);
        Tenants::switchTo($container, $business);

        return $container->getByType(EntityManagerInterface::class)->getRepository(Membership::class)->findAll();
    }

    private function password(): string
    {
        return Random::generate(24);
    }

    private function finish(string $password, ?string $scope, ?string $again = null): Response
    {
        return $this->post('administrator', [
            'email' => self::EMAIL,
            'name' => 'Ada Ammonite',
            'password' => $password,
            'passwordAgain' => $again ?? $password,
            'business' => 'Ammonite Bikes',
            ...($scope === null ? [] : ['scope' => $scope]),
            'finish' => 'Finish',
        ]);
    }

    private function get(): Response
    {
        return $this->serve(new Request(self::WIZARD, 'GET', ['action' => 'default']));
    }

    /** @param array<string, string> $post */
    private function post(string $form, array $post): Response
    {
        return $this->serve(new Request(self::WIZARD, 'POST', ['action' => 'default', 'do' => $form . '-submit'], $post));
    }

    private function serve(Request $request): Response
    {
        self::assertInstanceOf(Container::class, $this->container, 'a database has to be set up first');

        $presenter = $this->container->getByType(IPresenterFactory::class)->createPresenter($request->getPresenterName());
        self::assertInstanceOf(Presenter::class, $presenter);
        $presenter->autoCanonicalize = false;

        return $presenter->run($request);
    }

    private function pageOf(Response $response): HTMLDocument
    {
        self::assertInstanceOf(TextResponse::class, $response);
        $source = $response->getSource();
        self::assertInstanceOf(\Stringable::class, $source);

        return HTMLDocument::createFromString((string) $source, LIBXML_NOERROR);
    }

    /**
     * The installer run the way a second visitor's request runs it - in a
     * process of its own, with a connection of its own - while $connection
     * holds the claim uncommitted; committed the moment that process is seen
     * inserting the claim too, and so waiting on it. See
     * AccountCommandTest::committedOnceItWaits(), which this follows.
     *
     * @return array{bool, int, string} whether it was seen waiting, its exit code, and what it printed
     */
    private function finishedElsewhereOnceItWaits(Connection $connection, string $email): array
    {
        $code = sprintf(
            'require %s; echo "outcome:", %s::boot()->getByType(%s::class)->complete(%s, %s, %s, %s, %s, true) ? "claimed" : "refused", "\n";',
            var_export(Bootstrap::rootDirectory() . '/vendor/autoload.php', true),
            Bootstrap::class,
            Installer::class,
            var_export($email, true),
            var_export('Bea Belemnite', true),
            var_export($this->password(), true),
            var_export('Belemnite Books', true),
            var_export('belemnite.localhost', true),
        );

        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            Bootstrap::rootDirectory(),
        );

        try {
            self::assertIsResource($process);

            $met = false;
            $deadline = microtime(true) + self::SECONDS_TO_REACH_THE_CLAIM;
            while (!$met && microtime(true) < $deadline && proc_get_status($process)['running']) {
                $waiting = $connection->fetchOne(
                    'SELECT COUNT(*) FROM information_schema.PROCESSLIST'
                    . ' WHERE ID <> CONNECTION_ID() AND DB = DATABASE() AND INFO LIKE ?',
                    ['INSERT INTO core_setup_completion%'],
                );
                $met = is_numeric($waiting) && (int) $waiting > 0;
                if (!$met) {
                    usleep(20_000);
                }
            }
        } finally {
            $connection->commit();
        }

        $output = '';
        foreach ($pipes as $pipe) {
            $output .= (string) stream_get_contents($pipe);
            fclose($pipe);
        }

        $status = proc_get_status($process);
        while ($status['running']) {
            usleep(10_000);
            $status = proc_get_status($process);
        }

        proc_close($process);

        return [$met, $status['exitcode'], $output];
    }
}

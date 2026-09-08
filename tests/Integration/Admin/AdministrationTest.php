<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Admin;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Dom\Element;
use Dom\HTMLDocument;
use Nette\Application\BadRequestException;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\RedirectResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\DI\Container;
use Nette\Http\IResponse;
use Nette\Security\Passwords;
use Nette\Security\User as SignedIn;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Security\Accounts;
use Trilobit\Core\Security\Authenticator;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * The administration, from outside it: who gets in, who is turned away, and
 * what being turned away looks like.
 *
 * Signing in happens through the presenter and its form rather than by calling
 * the authenticator, because the claim is about the pages a person meets. The
 * account is made by this suite and its password generated here - nothing that
 * could be signed in with is in the repository.
 *
 * Two mechanics of the framework are set up rather than worked around. The
 * request carries the signal a submitted form carries, which is what makes the
 * form read the posted values; and the environment carries the Sec-Fetch-Site
 * header a browser sends, which is what nette/forms 3.3 checks in place of a
 * token in the page - its own CSRF control is deprecated as redundant beside
 * it. Neither weakens anything: both are what a real browser posting this form
 * produces, and tests/e2e signs in through a real one.
 */
#[CoversNothing]
final class AdministrationTest extends TestCase
{
    private const string SIGN = 'Core:Admin:Sign';

    private const string DASHBOARD = 'Core:Admin:Dashboard';

    /**
     * A section of a module, named as a string because this suite may not
     * reach into one - and does not need to. What is asked of it is the gate,
     * which is Core's.
     */
    private const string PAGES = 'Cms:Admin:Page';

    /** The other scope: the section the administrator of the installation has, and the one page in it. */
    private const string INSTALLATION = 'Core:Installation:Signpost';

    private const string BUSINESSES = 'Core:Installation:Businesses';

    private string $schema = '';

    private ?Container $container = null;

    private string $generatedPassword = '';

    private ?string $fetchSite = null;

    protected function setUp(): void
    {
        $this->fetchSite = isset($_SERVER['HTTP_SEC_FETCH_SITE']) && is_string($_SERVER['HTTP_SEC_FETCH_SITE'])
            ? $_SERVER['HTTP_SEC_FETCH_SITE']
            : null;
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
    }

    protected function tearDown(): void
    {
        if ($this->fetchSite === null) {
            unset($_SERVER['HTTP_SEC_FETCH_SITE']);
        } else {
            $_SERVER['HTTP_SEC_FETCH_SITE'] = $this->fetchSite;
        }

        $this->container?->getByType(SignedIn::class)->logout(true);
        $this->container = null;

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    /**
     * The claim T07 is measured by: a visitor who is not signed in is sent
     * somewhere rather than shown a stack trace. The status code is asserted
     * as well as the destination, because a 500 carrying a Location header
     * would satisfy "goes to the sign-in page" and nothing else about it.
     *
     * It is also where the order of the gate is measured, and the order is the
     * trap. A visitor who has not signed in holds no roles, so a gate that
     * asked what they may do before asking who they are would answer 403 - on
     * the page it was meant to be sending them to sign in on. A redirect here
     * is that ordering holding.
     */
    public function testAnAnonymousVisitorIsSentToTheSignInPage(): void
    {
        $response = $this->request(self::DASHBOARD, 'default');

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(302, $response->getCode());
        self::assertStringContainsString('admin/sign-in', $response->getUrl());
    }

    public function testTheSignInPageIsOpenToEverybody(): void
    {
        $page = $this->pageOf($this->request(self::SIGN, 'in'));

        self::assertNotNull($page->querySelector('[data-testid="sign-in-form"]'));
        self::assertNotNull($page->querySelector('[data-testid="sign-in-email"]'));
        self::assertNotNull($page->querySelector('[data-testid="sign-in-submit"]'));
    }

    /**
     * There is nothing to navigate to before there is somebody navigating, and
     * the build under test has three modules contributing entries - so an
     * empty menu here is the page leaving it out rather than there being none.
     */
    public function testTheSignInPageCarriesNoAdministrationMenu(): void
    {
        $page = $this->pageOf($this->request(self::SIGN, 'in'));

        self::assertNull($page->querySelector('[data-testid="admin-menu"]'));
    }

    public function testTheRightPasswordOpensTheAdministration(): void
    {
        $response = $this->submitSignIn('alice@example.com', $this->password());

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringNotContainsString('sign-in', $response->getUrl());
        self::assertTrue($this->container()->getByType(SignedIn::class)->isLoggedIn());
    }

    /**
     * The two ways to fail are told apart nowhere a visitor can see. A page
     * that said "no such address" would be an address checker anybody could
     * run against it.
     */
    public function testAWrongPasswordAndAnUnknownAddressAreRefusedInTheSameWords(): void
    {
        $wrongPassword = $this->refusalIn($this->submitSignIn('alice@example.com', 'not the one that was set'));
        $unknownAddress = $this->refusalIn($this->submitSignIn('nobody@example.com', $this->password()));

        self::assertSame(Authenticator::REFUSAL, $wrongPassword);
        self::assertSame($wrongPassword, $unknownAddress);
        self::assertFalse($this->container()->getByType(SignedIn::class)->isLoggedIn());
    }

    public function testOnceSignedInTheOverviewSaysWhoIsSignedIn(): void
    {
        $this->submitSignIn('alice@example.com', $this->password());

        $page = $this->pageOf($this->request(self::DASHBOARD, 'default'));

        self::assertNotNull($page->querySelector('[data-testid="admin-layout"]'));
        self::assertSame('Overview', $page->querySelector('[data-testid="admin-headline"]')?->textContent);
        self::assertSame('Alice Ammonite', $page->querySelector('[data-testid="admin-identity"]')?->textContent);
        self::assertSame(
            'alice@example.com',
            $page->querySelector('[data-testid="admin-identity-email"]')?->textContent,
        );
        self::assertNotNull(
            $page->querySelector('[data-testid="admin-role-administrator"]'),
            'the role held in this business is drawn, and it was read for this request rather than at sign-in',
        );
    }

    /**
     * The overview's other cluster is drawn from the permission snapshot on the
     * identity, which is made out of the roles granted on the account row -
     * and a person administering a business holds none of those, so the cluster
     * is empty for every account this application now makes.
     *
     * It is asserted rather than left alone so that the gap is written down
     * where somebody meets it. Nothing decides anything on that snapshot; it is
     * drawn and no more.
     * **Exit condition:** the overview is given what somebody may do here
     * instead - which is the roles held in this business expanded into pairs,
     * and therefore belongs with the model of decision D4 rather than beside it.
     */
    public function testTheOverviewDrawsNoPermissionsForSomebodyAdministeringABusiness(): void
    {
        $this->submitSignIn('alice@example.com', $this->password());

        $page = $this->pageOf($this->request(self::DASHBOARD, 'default'));

        self::assertNull($page->querySelector('[data-testid="admin-permission-administration"]'));
    }

    public function testSigningOutSendsYouBackToTheSignInPage(): void
    {
        $this->submitSignIn('alice@example.com', $this->password());
        self::assertTrue($this->container()->getByType(SignedIn::class)->isLoggedIn());

        $response = $this->request(self::SIGN, 'out');

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('admin/sign-in', $response->getUrl());
        self::assertFalse($this->container()->getByType(SignedIn::class)->isLoggedIn());
    }

    /** Somebody already signed in has no business on the sign-in page. */
    public function testSomebodySignedInIsTakenStraightToTheOverview(): void
    {
        $this->submitSignIn('alice@example.com', $this->password());

        $response = $this->request(self::SIGN, 'in');

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringNotContainsString('sign-in', $response->getUrl());
    }

    /**
     * The other half of the same order: somebody who really is signed in and
     * really holds nothing here is refused rather than sent to sign in again.
     *
     * It is the account this installation's own administrator has - see
     * Trilobit\Core\Security\Landlords - and being refused everywhere is
     * correct for them until they have a section of their own, because they
     * belong to no business and a role is something held in one. Sending them
     * to the sign-in page instead would be the loop that page cannot break:
     * they would sign in, arrive here, and be sent back.
     */
    public function testSomebodySignedInWhoHoldsNothingHereIsRefused(): void
    {
        $this->submitSignIn('bob@example.com', $this->password());
        self::assertTrue($this->container()->getByType(SignedIn::class)->isLoggedIn());

        $this->expectException(BadRequestException::class);
        $this->expectExceptionCode(IResponse::S403_Forbidden);

        $this->request(self::DASHBOARD, 'default');
    }

    /**
     * A declaration above an action narrows the one above the presenter, and
     * this is the pair that says so: the same person opens the list and is
     * refused the form beside it.
     *
     * The person holds `administration:view` and nothing else, so the list is
     * reached through the resource inheritance in
     * src/Core/Security/permissions.neon - a rule written on the parent
     * answers for the child - while writing a new page asks for a piece
     * nobody gave them. A gate read from the class alone would open both.
     */
    public function testAnActionAsksForMoreThanThePresenterItIsOn(): void
    {
        $this->submitSignIn('alice@example.com', $this->password());

        $list = $this->request(self::PAGES, 'default');
        self::assertInstanceOf(TextResponse::class, $list);

        $this->expectException(BadRequestException::class);
        $this->expectExceptionCode(IResponse::S403_Forbidden);

        $this->request(self::PAGES, 'add');
    }

    /**
     * The two scopes meet nowhere, and this is the pair that says so from the
     * inside: the same request, made by the two kinds of account this
     * application makes, answered in opposite directions.
     *
     * It is asserted as a pair rather than as two tests, because either half
     * alone would pass in an application where the section admitted everybody
     * or nobody.
     */
    public function testTheInstallationSectionAdmitsItsAdministratorAndNobodyElse(): void
    {
        $this->submitSignIn('cora@example.com', $this->password());
        self::assertInstanceOf(TextResponse::class, $this->request(self::INSTALLATION, 'default'));

        $this->container()->getByType(SignedIn::class)->logout(true);
        $this->submitSignIn('alice@example.com', $this->password());

        $this->expectException(BadRequestException::class);
        $this->expectExceptionCode(IResponse::S403_Forbidden);

        $this->request(self::INSTALLATION, 'default');
    }

    /**
     * The other direction of the same sentence. Somebody who administers the
     * installation belongs to no business, so a page of a business's
     * administration is not theirs to open - not because they were given too
     * little, but because a role is held in a business and they are in none.
     */
    public function testTheInstallationsAdministratorIsRefusedTheAdministrationOfABusiness(): void
    {
        $this->submitSignIn('cora@example.com', $this->password());

        $this->expectException(BadRequestException::class);
        $this->expectExceptionCode(IResponse::S403_Forbidden);

        $this->request(self::DASHBOARD, 'default');
    }

    /**
     * Signing in has to land somebody where they may be, and the two kinds of
     * account land in different places for that reason alone.
     *
     * The overview of a business is where an administrator of one belongs and
     * is exactly what the other is refused, so sending both there would make
     * the application look broken to the account a fresh installation is set
     * up with.
     */
    public function testSigningInLandsEachKindOfAccountWhereItMayBe(): void
    {
        $business = $this->submitSignIn('alice@example.com', $this->password());
        self::assertInstanceOf(RedirectResponse::class, $business);
        self::assertStringEndsWith('/admin', $business->getUrl());

        $this->container()->getByType(SignedIn::class)->logout(true);

        $installation = $this->submitSignIn('cora@example.com', $this->password());
        self::assertInstanceOf(RedirectResponse::class, $installation);
        self::assertStringContainsString('/admin/installation', $installation->getUrl());
    }

    /** The businesses of this installation are what the section holds in this first version. */
    public function testTheSectionListsTheBusinessesThisInstallationHas(): void
    {
        $this->submitSignIn('cora@example.com', $this->password());

        $page = $this->pageOf($this->request(self::BUSINESSES, 'default'));

        $list = $page->querySelector('[data-testid="business-list"]');
        self::assertNotNull($list, 'the section drew no list of businesses');
        self::assertStringContainsString('Ammonite Bikes', $list->textContent ?? '');
    }

    /**
     * The menu draws nothing this person would be refused, and that is one
     * filter rather than a habit kept in two places.
     *
     * Both directions are asserted here because the entry that has to go is a
     * different entry for each of them: the administrator of a business must
     * not be shown the way into the installation, and the administrator of the
     * installation must not be shown the way into a business.
     */
    public function testTheMenuHoldsNothingThePersonReadingItWouldBeRefused(): void
    {
        $this->submitSignIn('alice@example.com', $this->password());
        $business = $this->menuAddressesOn($this->request(self::DASHBOARD, 'default'));

        self::assertNotSame([], $business, 'somebody administering a business was drawn no menu at all');
        self::assertSame(
            [],
            array_values(array_filter($business, static fn(string $href): bool => str_contains($href, '/admin/installation'))),
            'the bar offered the installation section to somebody who administers a business',
        );

        $this->container()->getByType(SignedIn::class)->logout(true);
        $this->submitSignIn('cora@example.com', $this->password());
        $installation = $this->menuAddressesOn($this->request(self::BUSINESSES, 'default'));

        self::assertNotSame([], $installation, 'the administrator of the installation was drawn no menu at all');
        self::assertSame(
            [],
            array_values(array_filter(
                $installation,
                static fn(string $href): bool => str_starts_with($href, '/admin') && !str_starts_with($href, '/admin/installation'),
            )),
            'the bar offered the administration of a business to somebody who is in none',
        );
    }

    /**
     * An entry leading somewhere no gate stands is drawn for everybody, which
     * is a decision rather than an oversight: the destination is a page of the
     * public site that a module put on the bar, and hiding a page anybody may
     * open would be the filter answering a question nobody asked it.
     *
     * It is asserted from the account that holds nothing in this business,
     * because that is where "hide what is refused" and "hide what has no gate"
     * come apart.
     */
    public function testAnEntryLeadingSomewhereNoGateStandsIsDrawnForEverybody(): void
    {
        $this->submitSignIn('cora@example.com', $this->password());

        $addresses = $this->menuAddressesOn($this->request(self::BUSINESSES, 'default'));

        self::assertNotSame(
            [],
            array_values(array_filter($addresses, static fn(string $href): bool => !str_starts_with($href, '/admin'))),
            'every entry leading outside the administration was dropped, and none of them is gated',
        );
    }

    /**
     * The section's signpost and the bar are one data structure drawn twice
     * (decision M2), so what the filter takes out of one it takes out of the
     * other - and what it leaves is the same in both.
     */
    public function testTheSectionSignpostHoldsExactlyWhatTheBarHoldsForIt(): void
    {
        $this->submitSignIn('cora@example.com', $this->password());

        $page = $this->pageOf($this->request(self::INSTALLATION, 'default'));

        $signpost = $page->querySelector('[data-testid="installation-signpost"]');
        self::assertNotNull($signpost, 'the section drew no signpost');

        $labels = [];
        foreach ($signpost->querySelectorAll('.c-card__link') as $link) {
            $labels[] = trim($link->textContent ?? '');
        }

        self::assertSame(['Businesses'], $labels);
    }

    /**
     * Every address the administration bar of a drawn page leads to.
     *
     * @return list<string>
     */
    private function menuAddressesOn(Response $response): array
    {
        $menu = $this->pageOf($response)->querySelector('[data-testid="admin-menu"]');
        if (!$menu instanceof Element) {
            return [];
        }

        $addresses = [];
        foreach ($menu->querySelectorAll('.c-nav__link') as $link) {
            $addresses[] = $link->getAttribute('href') ?? '';
        }

        return $addresses;
    }

    private function submitSignIn(string $email, string $secret): Response
    {
        return $this->request(self::SIGN, 'in', ['do' => 'signIn-submit'], [
            'email' => $email,
            'password' => $secret,
            'send' => 'Sign in',
        ]);
    }

    /**
     * @param array<string, string> $parameters
     * @param array<string, string> $post
     */
    private function request(string $presenterName, string $action, array $parameters = [], array $post = []): Response
    {
        $presenter = $this->container()->getByType(IPresenterFactory::class)->createPresenter($presenterName);
        self::assertInstanceOf(Presenter::class, $presenter);
        $presenter->autoCanonicalize = false;

        return $presenter->run(new Request(
            $presenterName,
            $post === [] ? 'GET' : 'POST',
            ['action' => $action, ...$parameters],
            $post,
        ));
    }

    private function pageOf(Response $response): HTMLDocument
    {
        self::assertInstanceOf(TextResponse::class, $response);
        $source = $response->getSource();
        self::assertInstanceOf(\Stringable::class, $source);

        return HTMLDocument::createFromString((string) $source, LIBXML_NOERROR);
    }

    private function refusalIn(Response $response): string
    {
        $error = $this->pageOf($response)->querySelector('[data-testid="sign-in-error"]');
        self::assertNotNull($error, 'the page said nothing about the sign-in having failed');

        return trim($error->textContent ?? '');
    }

    private function password(): string
    {
        $this->container();

        return $this->generatedPassword;
    }

    /**
     * A build with every module on, so that the menu the administration draws
     * is not empty, and one business with one person administering it.
     *
     * The role is held through a membership and not granted on the account,
     * because that is where a right lives: core_user_role names no business, so
     * a role read off it would be a role in every business at once. It is also
     * what `app:account --tenant` makes, which is the account tests/e2e signs in
     * as - a suite set up any other way would be exercising a shape nothing
     * produces.
     *
     * **The second account holds nothing here, and that is a shape the
     * application really makes too.** An account can hold nothing in this
     * business without administering the installation - it may hold something
     * in another one - so what the pages of the administration do about
     * somebody like that has to be measured rather than assumed.
     *
     * **The third is the administrator of the installation**, which is what
     * `app:account` makes when it is given no business: the flag on the row
     * and no membership anywhere, because the two cannot be held by one
     * account. It is a separate account from the second on purpose - holding
     * nothing here and administering the installation are answered by
     * different services and would otherwise be one fixture standing for both.
     *
     * All three share one generated password, because what differs between
     * them is what they hold and nothing else.
     */
    private function container(): Container
    {
        if ($this->container instanceof Container) {
            return $this->container;
        }

        $this->schema = Database::schemaFor(self::class);
        $container = Boot::container(ModuleList::of(
            ['cms' => true, 'crm' => true, 'shop' => true],
            Bootstrap::rootDirectory(),
        ));
        Migrations::run($container);
        $tenant = Tenants::enter($container, 'Ammonite Bikes');

        $this->generatedPassword = Random::generate(24, 'a-zA-Z0-9');
        $account = new User(
            'alice@example.com',
            $container->getByType(Passwords::class)->hash($this->generatedPassword),
            'Alice Ammonite',
            new DateTimeImmutable('2026-09-04T08:00:00+00:00'),
        );
        $container->getByType(Accounts::class)->save($account);

        $holdingNothingHere = new User(
            'bob@example.com',
            $container->getByType(Passwords::class)->hash($this->generatedPassword),
            'Bob Belemnite',
            new DateTimeImmutable('2026-09-04T08:00:00+00:00'),
        );
        $container->getByType(Accounts::class)->save($holdingNothingHere);

        $administersTheInstallation = new User(
            'cora@example.com',
            $container->getByType(Passwords::class)->hash($this->generatedPassword),
            'Cora Crinoid',
            new DateTimeImmutable('2026-09-04T08:00:00+00:00'),
            landlord: true,
        );
        $container->getByType(Accounts::class)->save($administersTheInstallation);

        $entityManager = $container->getByType(EntityManagerInterface::class);
        $role = new Role('administrator', 'Administrator', ['administration:view']);
        $entityManager->persist($role);
        $entityManager->persist(new Membership($tenant, $account, $role));
        $entityManager->flush();

        return $this->container = $container;
    }
}

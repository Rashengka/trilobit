<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Admin;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Dom\Element;
use Dom\HTMLDocument;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\ForwardResponse;
use Nette\Application\Responses\RedirectResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\DI\Container;
use Nette\Http\IResponse;
use Nette\Http\Request as HttpRequest;
use Nette\Http\UrlScript;
use Nette\Routing\Router;
use Nette\Security\Passwords;
use Nette\Security\User as SignedIn;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\DI\CoreExtension;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Security\Accounts;
use Trilobit\Core\Security\Authenticator;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Double\Admin\PublicPageMenu;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * The administration, from outside it: who gets in, who is turned away, what
 * being turned away looks like, and that being turned away still leaves a way
 * out.
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

    /** Not a page of the administration at all, which is the whole of why it can answer for one. */
    private const string REFUSAL = 'Core:Error:Refusal';

    /** Nor is this: ending a session is one act for the whole application. */
    private const string SIGN_OUT = 'Core:Session:SignOut';

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
            $page->querySelector('[data-testid="admin-role-owner"]'),
            'the role held in this business is drawn, and it was read for this request rather than at sign-in',
        );
    }

    /**
     * Who is signed in, the switch and the ways out are one menu in the
     * banner: a button carrying the name, and a panel the browser opens and
     * closes over the page.
     *
     * The testids are the ones the row beside the banner used to carry, so
     * what the rest of this suite asserts of them still holds - which is why
     * they are asserted here to be inside the panel. A name drawn beside the
     * menu rather than in it would satisfy every other test in this file.
     */
    public function testWhoIsSignedInAndTheWaysOutAreInTheirMenu(): void
    {
        $this->submitSignIn('alice@example.com', $this->password());

        $page = $this->pageOf($this->request(self::DASHBOARD, 'default'));

        $trigger = $page->querySelector('[data-testid="admin-account-menu"]');
        $panel = $page->querySelector('[data-testid="admin-account-menu-panel"]');
        self::assertNotNull($trigger, 'there is no button for the menu of whoever is signed in');
        self::assertNotNull($panel, 'there is no panel for that button to open');

        self::assertStringContainsString('Alice Ammonite', $trigger->textContent ?? '');
        self::assertTrue($panel->hasAttribute('popover'), 'the panel is not a popover, so nothing would close it');
        self::assertNotSame('', $panel->getAttribute('id') ?? '');
        self::assertSame($panel->getAttribute('id'), $trigger->getAttribute('popovertarget'));

        foreach (['admin-identity', 'admin-identity-email', 'admin-public-link', 'admin-sign-out'] as $inside) {
            self::assertNotNull(
                $panel->querySelector(sprintf('[data-testid="%s"]', $inside)),
                sprintf('%s is not inside the menu', $inside),
            );
        }

        self::assertNotNull($panel->querySelector('[data-preference="theme"]'), 'the menu offers no switch');
        self::assertNotNull(
            $panel->querySelector('[data-testid="admin-sign-out"] svg.c-icon'),
            'the way out carries no icon',
        );
    }

    /** Nobody has signed in yet, so there is no menu to open and no name to put in one. */
    public function testTheSignInPageHasNoMenuOfWhoeverIsSignedIn(): void
    {
        $page = $this->pageOf($this->request(self::SIGN, 'in'));

        self::assertNull($page->querySelector('[data-testid="admin-account-menu"]'));
        self::assertNull($page->querySelector('[data-testid="admin-account-menu-panel"]'));
        self::assertNull($page->querySelector('[data-testid="admin-identity"]'));
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

    /**
     * Signing out is not the administration's own act and does not leave
     * anybody in it.
     *
     * The address carries no `admin/` and the page it ends on is the front
     * page, because there is one identity and one session: whoever ends it -
     * an administrator today, somebody buying something later - is doing the
     * same thing, and the administration's sign-in page is not a landing every
     * one of them belongs on. See
     * Trilobit\Core\Presentation\Session\SignOutPresenter.
     */
    public function testSigningOutIsNotTheAdministrationsOwnAndLeavesNobodyInIt(): void
    {
        $this->submitSignIn('alice@example.com', $this->password());
        self::assertTrue($this->container()->getByType(SignedIn::class)->isLoggedIn());

        $response = $this->request(self::SIGN_OUT, 'default');

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringNotContainsString('admin', $response->getUrl());
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

        $page = $this->refusedPage(self::DASHBOARD, 'default');

        self::assertNotNull($page->querySelector('[data-testid="refusal-headline"]'));
        self::assertTrue($this->container()->getByType(SignedIn::class)->isLoggedIn());
    }

    /**
     * The claim the whole of this page exists for: somebody refused everywhere
     * can still get out.
     *
     * The account below holds nothing in this business, so every page of the
     * administration turns it away - and the only sign-out link the
     * application used to draw was in the administration's own banner, on
     * pages it could not reach. What is asserted is therefore not that the
     * refusal is polite but that it is a way out: a link that ends the
     * session, and one to the part of the site that is open to anybody.
     *
     * Neither address is under the administration. That is not decoration
     * either - a way out that led back through the section that just refused
     * them would refuse them again.
     */
    public function testTheRefusalOffersAWayOutThatDoesNotLeadBackIntoTheAdministration(): void
    {
        $this->submitSignIn('bob@example.com', $this->password());

        $page = $this->refusedPage(self::DASHBOARD, 'default');

        $signOut = $page->querySelector('[data-testid="refusal-sign-out"]');
        self::assertNotNull($signOut, 'somebody who is refused everywhere was offered no way of signing out');
        self::assertSame('/sign-out', $signOut->getAttribute('href'));

        $site = $page->querySelector('[data-testid="refusal-public-link"]');
        self::assertNotNull($site, 'the refusal offered no way back to the public site');
        self::assertSame('/', $site->getAttribute('href'));

        self::assertNull(
            $page->querySelector('[data-testid="admin-menu"]'),
            'the page a refusal is drawn on is a page of the administration after all',
        );

        // And the way out is a way out: following it ends the session and
        // leaves them on a page that belongs to no audience in particular.
        $left = $this->request(self::SIGN_OUT, 'default');

        self::assertInstanceOf(RedirectResponse::class, $left);
        self::assertStringEndsWith('/', $left->getUrl());
        self::assertFalse($this->container()->getByType(SignedIn::class)->isLoggedIn());
    }

    /**
     * A declaration above an action narrows the one above the presenter, and
     * this is the pair that says so: the same person opens the list and is
     * refused the form beside it.
     *
     * The person may open the administration and read its content and nothing
     * more, so the list opens while writing a new page asks for a piece nobody
     * gave them. A gate read from the class alone would open both.
     */
    public function testAnActionAsksForMoreThanThePresenterItIsOn(): void
    {
        $this->submitSignIn('alice@example.com', $this->password());

        $list = $this->request(self::PAGES, 'default');
        self::assertInstanceOf(TextResponse::class, $list);

        $this->refusedPage(self::PAGES, 'add');
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

        $this->refusedPage(self::INSTALLATION, 'default');
    }

    /**
     * The other direction of the same sentence. Somebody who administers the
     * installation belongs to no business, so a page of a business's
     * administration is not theirs to open - not because they were given too
     * little, but because a role is held in a business and they are in none.
     *
     * The page asked for is a section of a module rather than the overview,
     * and the difference is the point: the overview is what /admin leads to
     * and is therefore the address the administration begins at, which
     * resolves to where this person belongs instead of refusing them (see
     * below). Every other page, this one included, refuses as it always did -
     * otherwise nobody would ever learn they lacked a right.
     */
    public function testTheInstallationsAdministratorIsRefusedTheAdministrationOfABusiness(): void
    {
        $this->submitSignIn('cora@example.com', $this->password());

        $this->refusedPage(self::PAGES, 'default');
    }

    /**
     * The address the administration begins at takes each kind of account to
     * the administration it has, and refuses nobody for the scope they are in.
     *
     * /admin is the one address of the administration a person knows, and what
     * met somebody who administers the installation there was "This is not
     * yours to open" - a working installation behaving like a broken one, on
     * the account it was set up with. The answer is not a new rule but the one
     * the sign-in page and the mark in the banner already use, so the three
     * cannot come to disagree; see Trilobit\Core\Presentation\Admin\Landing.
     *
     * All three accounts are asked, because two of them alone would be
     * satisfied by a page that redirected everybody: the one who administers a
     * business is drawn the overview, the one who administers the installation
     * is sent to their own section, and the one who holds nothing anywhere is
     * refused exactly as before. The last is the whole of what keeps this from
     * being a gate that never says no.
     */
    public function testTheAddressTheAdministrationBeginsAtTakesEachKindOfAccountToTheirOwn(): void
    {
        $this->submitSignIn('alice@example.com', $this->password());
        $drawn = $this->pageOf($this->request(self::DASHBOARD, 'default'));
        self::assertSame('Overview', $drawn->querySelector('[data-testid="admin-headline"]')?->textContent);

        $this->container()->getByType(SignedIn::class)->logout(true);
        $this->submitSignIn('cora@example.com', $this->password());

        $sent = $this->request(self::DASHBOARD, 'default');
        self::assertInstanceOf(RedirectResponse::class, $sent);
        self::assertStringContainsString('/admin/installation', $sent->getUrl());

        $this->container()->getByType(SignedIn::class)->logout(true);
        $this->submitSignIn('bob@example.com', $this->password());

        $this->refusedPage(self::DASHBOARD, 'default');
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
        $business = $this->menuAddressesOn($this->pageOf($this->request(self::DASHBOARD, 'default')));

        self::assertNotSame([], $business, 'somebody administering a business was drawn no menu at all');
        self::assertSame(
            [],
            array_values(array_filter($business, static fn(string $href): bool => str_contains($href, '/admin/installation'))),
            'the bar offered the installation section to somebody who administers a business',
        );

        $this->container()->getByType(SignedIn::class)->logout(true);
        $this->submitSignIn('cora@example.com', $this->password());
        $installation = $this->menuAddressesOn($this->pageOf($this->request(self::BUSINESSES, 'default')));

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
     * The bar begins with the way back, and it leads where the mark in the
     * banner leads.
     *
     * The mark led there first and still does, and it was not enough: somebody
     * inside a section looked for the way to the top of the administration in
     * the bar, which is where the ways from here to there are, and found none.
     * What is asserted is both halves - that the first entry of the bar is that
     * way back, and that it is the same address as the mark - because two
     * places drawing it from one answer is the whole reason it is drawn from
     * Trilobit\Core\Presentation\Admin\Landing rather than written twice.
     *
     * Both kinds of account are asked, because the address is not the same one
     * for them and a way back that led everybody to a business's overview would
     * be a link one of them is refused on.
     */
    public function testTheBarBeginsWithTheWayBackToWhereThisPersonsAdministrationStarts(): void
    {
        $this->submitSignIn('alice@example.com', $this->password());
        $page = $this->pageOf($this->request(self::DASHBOARD, 'default'));

        self::assertSame('/admin', $this->menuAddressesOn($page)[0] ?? null);
        self::assertSame('/admin', $page->querySelector('[data-testid="admin-home-link"]')?->getAttribute('href'));

        $this->container()->getByType(SignedIn::class)->logout(true);
        $this->submitSignIn('cora@example.com', $this->password());
        $page = $this->pageOf($this->request(self::BUSINESSES, 'default'));

        self::assertSame('/admin/installation', $this->menuAddressesOn($page)[0] ?? null);
        self::assertSame(
            '/admin/installation',
            $page->querySelector('[data-testid="admin-home-link"]')?->getAttribute('href'),
        );
    }

    /**
     * An entry leading somewhere no gate stands is drawn for everybody, which
     * is a decision rather than an oversight: the destination is a page of the
     * public site, and hiding a page anybody may open would be the filter
     * answering a question nobody asked it.
     *
     * It is asserted from the account that holds nothing in this business,
     * because that is where "hide what is refused" and "hide what has no gate"
     * come apart.
     *
     * The entry is contributed by Trilobit\Tests\Double\Admin\PublicPageMenu
     * and no longer by a module. Every module used to contribute one like it,
     * and it was a way out of the administration drawn inside the
     * administration; taking them out took the last live example of this shape
     * with them, and a rule with no example left is a rule that quietly stops
     * being true. So the example is made here, where it can be pointed at.
     */
    public function testAnEntryLeadingSomewhereNoGateStandsIsDrawnForEverybody(): void
    {
        $this->submitSignIn('cora@example.com', $this->password());

        $addresses = $this->menuAddressesOn($this->pageOf($this->request(self::BUSINESSES, 'default')));

        self::assertNotSame(
            [],
            array_values(array_filter($addresses, static fn(string $href): bool => !str_starts_with($href, '/admin'))),
            'every entry leading outside the administration was dropped, and none of them is gated',
        );
    }

    /**
     * Every address the bar draws is followed, and every one of them opens.
     *
     * That is the sentence Trilobit\Core\Admin\Menu\ReachableMenu makes about
     * itself, and this is the only place it is measured as a whole rather than
     * entry by entry: what the bar holds is not compared with a list written
     * here, it is asked for.
     *
     * The account is the one holding a single section, because that is where
     * the claim can fail. Somebody who may open everything sees no wrong entry
     * because there is no page to be refused, and somebody who may open nothing
     * never reaches a page that draws a bar at all - so a suite made of those
     * two would agree with a bar that offered a refusal to everybody in
     * between. The way back was such an entry once, when a section did not
     * open the administration it is a section of; it opens it now, so the way
     * back is drawn for this person and has to open like every other entry.
     */
    public function testEveryAddressTheBarDrawsOpensForThePersonReadingIt(): void
    {
        $this->submitSignIn('dana@example.com', $this->password());

        $addresses = $this->menuAddressesOn($this->pageOf($this->request(self::PAGES, 'default')));
        self::assertNotSame([], $addresses, 'the person was drawn no bar, so nothing was measured');

        foreach ($addresses as $address) {
            [$presenter, $action] = $this->routed($address);

            self::assertInstanceOf(
                TextResponse::class,
                $this->request($presenter, $action),
                $address . ' is offered in the bar and refuses the person reading it',
            );
        }

        // The other half, and the half that keeps the first from being met by a
        // bar with nothing in it: the page they may open is in there, and so is
        // the way back to the overview, which their section opens for them.
        self::assertContains('/admin/cms/pages', $addresses);
        self::assertContains('/admin', $addresses);
    }

    /**
     * Somebody holding one section and nothing else signs in onto the
     * overview, and the mark in the banner leads there.
     *
     * Any right in a section opens the administration it is a section of, so
     * the overview - gated on opening the administration - is theirs to open
     * and not a refusal. It is asserted through the whole way in: where the
     * form sends them, that the page at the end of it draws the overview, and
     * that the two ways back lead to it. A role like this is the ordinary
     * shape of one rather than an odd one, and it met a refusal on the first
     * page it was shown.
     */
    public function testSomebodyHoldingOneSectionSignsInOntoTheOverview(): void
    {
        $sent = $this->submitSignIn('dana@example.com', $this->password());
        self::assertInstanceOf(RedirectResponse::class, $sent);
        self::assertStringEndsWith('/admin', $sent->getUrl());

        $page = $this->pageOf($this->request(self::DASHBOARD, 'default'));

        self::assertSame('Overview', $page->querySelector('[data-testid="admin-headline"]')?->textContent);
        self::assertSame('/admin', $page->querySelector('[data-testid="admin-home-link"]')?->getAttribute('href'));
        self::assertSame('/admin', $this->menuAddressesOn($page)[0] ?? null);
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
     * Every address the administration bar of a drawn page leads to, in the
     * order the bar draws them - the first of which is the way back.
     *
     * @return list<string>
     */
    private function menuAddressesOn(HTMLDocument $page): array
    {
        $menu = $page->querySelector('[data-testid="admin-menu"]');
        if (!$menu instanceof Element) {
            return [];
        }

        $addresses = [];
        foreach ($menu->querySelectorAll('.c-nav__link') as $link) {
            $addresses[] = $link->getAttribute('href') ?? '';
        }

        return $addresses;
    }

    /**
     * What answers at an address, asked of the router rather than worked out
     * from the string.
     *
     * A page reached by taking its address apart here would be a page this
     * suite chose; asked of the router, it is the page a browser following that
     * link would land on.
     *
     * @return array{string, string}
     */
    private function routed(string $address): array
    {
        $matched = $this->container()->getByType(Router::class)
            ->match(new HttpRequest(new UrlScript('http://localhost' . $address, '/')));

        self::assertIsArray($matched, $address . ' is drawn in the bar and the router does not claim it');
        self::assertIsString($matched['presenter'] ?? null);
        self::assertIsString($matched['action'] ?? null);

        return [$matched['presenter'], $matched['action']];
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
        return $this->runRequest(new Request(
            $presenterName,
            $post === [] ? 'GET' : 'POST',
            ['action' => $action, ...$parameters],
            $post,
        ));
    }

    /**
     * The page somebody is actually shown when a gate refuses them, reached
     * the way the framework reaches it.
     *
     * A refusal is a forward and not an exception, so the claim worth making
     * is about the page at the end of it - the status it carries and the way
     * out it offers - and not about the shape of a throw. The forward is
     * followed here rather than being asserted and left, because
     * Nette\Application\Application follows it on a real request and a
     * ForwardResponse nobody follows would prove nothing about what a person
     * sees.
     *
     * @param array<string, string> $parameters
     */
    private function refusedPage(string $presenterName, string $action, array $parameters = []): HTMLDocument
    {
        $refusal = $this->request($presenterName, $action, $parameters);

        self::assertInstanceOf(
            ForwardResponse::class,
            $refusal,
            'the gate did not turn the request into the page a refusal is drawn on',
        );
        self::assertSame(self::REFUSAL, $refusal->getRequest()->getPresenterName());

        $page = $this->pageOf($this->runRequest($refusal->getRequest()));

        self::assertSame(
            IResponse::S403_Forbidden,
            $this->container()->getByType(IResponse::class)->getCode(),
            'the refusal was drawn as an ordinary page rather than as a refusal',
        );

        return $page;
    }

    private function runRequest(Request $request): Response
    {
        $presenter = $this->container()->getByType(IPresenterFactory::class)
            ->createPresenter($request->getPresenterName());
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
     *
     * **The fourth holds one section and was not given the administration**,
     * which is the ordinary shape of a role rather than an odd one:
     * `app.administration.content:view` opens that section, and the section
     * opens the administration it is a section of - a door worked out from the
     * tree in the resource's name - and nothing else in it. It is the
     * account that shows what the bar may offer somebody: every other account
     * here either may open everything in this business or may open nothing in
     * it, and both hide an entry that leads to a refusal.
     *
     * The first is given the administration and its content by name, because
     * a pair means that pair and nothing under it: opening the administration
     * alone would be let in and shown no section.
     *
     * **One menu entry in this build comes from the suite and not from a
     * module**, and it is the one leading to a page no gate stands over - see
     * Trilobit\Tests\Double\Admin\PublicPageMenu. It is tagged the way a module
     * tags its own provider, so what the filter meets is a row like any other.
     */
    private function container(): Container
    {
        if ($this->container instanceof Container) {
            return $this->container;
        }

        $this->schema = Database::schemaFor(self::class);
        $container = Boot::container(
            ModuleList::of(['cms' => true, 'crm' => true, 'shop' => true], Bootstrap::rootDirectory()),
            config: ['services' => [
                'test.publicPageMenu' => [
                    'factory' => PublicPageMenu::class,
                    'autowired' => false,
                    'tags' => [CoreExtension::TAG_ADMIN_MENU_PROVIDER],
                ],
            ]],
        );
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

        $holdingOneSection = new User(
            'dana@example.com',
            $container->getByType(Passwords::class)->hash($this->generatedPassword),
            'Dana Diatom',
            new DateTimeImmutable('2026-09-04T08:00:00+00:00'),
        );
        $container->getByType(Accounts::class)->save($holdingOneSection);

        $entityManager = $container->getByType(EntityManagerInterface::class);
        $owner = new Role(
            'owner',
            'Owner',
            ['app.administration:view', 'app.administration.content:view'],
        );
        $entityManager->persist($owner);
        $entityManager->persist(new Membership($tenant, $account, $owner));

        // The narrow role, and it is narrow the way src/Core/Security/
        // permissions.neon means it rather than by leaving something out.
        // Nothing is inherited downwards, and any right on a section opens
        // what it falls under: `app.administration.content` is under
        // `app.administration` by its name, so somebody assembled out of
        // `app.administration.content:view` opens every page of that section
        // that asks for no more, and the overview as well - and nothing else
        // in the administration.
        $editor = new Role('editor', 'Editor', ['app.administration.content:view']);
        $entityManager->persist($editor);
        $entityManager->persist(new Membership($tenant, $holdingOneSection, $editor));

        $entityManager->flush();

        return $this->container = $container;
    }
}

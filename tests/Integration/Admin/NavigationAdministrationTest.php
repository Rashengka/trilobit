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
use Nette\Security\Passwords;
use Nette\Security\User as SignedIn;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Domain\Navigation\Menu;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Navigation\Menus;
use Trilobit\Core\Security\Accounts;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * Arranging the site's navigation from the administration: the order of what
 * contributes to it, and which of the contributors are drawn at all
 * (.ai/plans/10-menu-submenu-a-rozcestniky.md, M3, decided 2026-09-13).
 *
 * The build has no Cms, so that the page is shown to stand on Core alone: what
 * contributes is the way home and the sections of the enabled modules. That is
 * two contributors and three entries - enough for an entry to move past a
 * neighbour of the other contributor and to be kept from moving past one of
 * its own.
 *
 * **Reordering is its own privilege.** `app.administration.content:change_priority`
 * exists because "a menu is an ordering: somebody may be trusted to arrange what
 * is there without being trusted to change what it says"
 * (src/Core/Security/permissions.neon). This page does nothing but arrange, so
 * it is where the privilege is asked for first; seeing the page is `view`.
 */
#[CoversNothing]
final class NavigationAdministrationTest extends TestCase
{
    private const string PRESENTER = 'Core:Admin:Navigation';

    private const string FORM = 'arrangement';

    private string $schema = '';

    private ?Container $container = null;

    private ?Tenant $tenant = null;

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
        $this->tenant = null;

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testThePageListsTheNavigationAsItIsDrawnAndTheWaysEachEntryMayMove(): void
    {
        $this->signIn('app:*');
        $page = $this->pageOf($this->request());

        self::assertSame(['Home', 'Crm', 'Shop'], $this->rowsOf($page));

        self::assertNull($this->control($page, 'up-0'), 'the first entry offers to move up');
        self::assertNotNull($this->control($page, 'down-0'));
        self::assertNotNull($this->control($page, 'up-1'));
        self::assertNull($this->control($page, 'down-1'), 'an entry offers to move past one of its own contributor');
        self::assertNull($this->control($page, 'down-2'), 'the last entry offers to move down');

        self::assertNotNull($this->control($page, 'hide-0'));
        self::assertNotNull($this->control($page, 'hide-1'));
        self::assertNull($this->control($page, 'reset'), 'there is nothing saved to go back from');
    }

    public function testMovingAnEntryIsWhatTheSiteThenDraws(): void
    {
        $this->signIn('app:*');

        self::assertInstanceOf(RedirectResponse::class, $this->press('down0'));

        self::assertSame(['Crm', 'Home', 'Shop'], $this->siteNavigation());
        $page = $this->pageOf($this->request());
        self::assertSame(['Crm', 'Home', 'Shop'], $this->rowsOf($page));
        self::assertNotNull($this->control($page, 'reset'), 'a saved arrangement offers no way back to the default');
    }

    public function testHidingAContributorTakesItsEntriesOffTheSiteAndShowingPutsThemBack(): void
    {
        $this->signIn('app:*');

        $this->press('hide1');
        self::assertSame(['Home'], $this->siteNavigation());
        self::assertNotNull($this->control($this->pageOf($this->request()), 'show-1'));

        $this->press('show1');
        self::assertSame(['Home', 'Crm', 'Shop'], $this->siteNavigation());
    }

    public function testGoingBackToTheDefaultForgetsWhatWasSaved(): void
    {
        $this->signIn('app:*');

        self::assertInstanceOf(RedirectResponse::class, $this->press('down0'));
        self::assertInstanceOf(
            RedirectResponse::class,
            $this->press('useDefault'),
            'the press was not taken as the button, so nothing was done - and the page answered as if it had been',
        );

        self::assertSame(['Home', 'Crm', 'Shop'], $this->siteNavigation());
        self::assertNull($this->container()->getByType(Menus::class)->named(Menu::MAIN)?->composition());
    }

    /**
     * Seeing the page is `view`; changing anything on it is `change_priority`.
     * The buttons are left out for somebody who may not press them, and a
     * press made anyway - the form is the same form, so it can be sent - is
     * refused and changes nothing.
     */
    public function testSomebodyWhoMayOnlyLookSeesNoButtonsAndIsRefusedAPress(): void
    {
        $this->signIn('app.administration.content:view');

        $page = $this->pageOf($this->request());
        self::assertSame(['Home', 'Crm', 'Shop'], $this->rowsOf($page));
        self::assertNull($this->control($page, 'down-0'), 'a button was drawn for somebody who may not press it');
        self::assertNull($this->control($page, 'hide-0'), 'a button was drawn for somebody who may not press it');

        try {
            $this->press('down0');
            self::fail('a press by somebody who may not reorder was accepted');
        } catch (BadRequestException $refused) {
            self::assertSame(403, $refused->getHttpCode());
        }

        self::assertSame(['Home', 'Crm', 'Shop'], $this->siteNavigation());
    }

    public function testTheAdministrationBarLeadsToThePage(): void
    {
        $this->signIn('app:*');

        $page = $this->pageOf($this->request('Core:Admin:Dashboard'));

        self::assertSame(
            '/admin/navigation',
            $page->querySelector('[data-testid="admin-menu-navigation"]')?->getAttribute('href'),
        );
    }

    /** @return list<string> the labels of the rows, in the order the page lists them */
    private function rowsOf(HTMLDocument $page): array
    {
        $labels = [];
        foreach ($page->querySelectorAll('[data-testid^="admin-navigation-label-"]') as $label) {
            $labels[] = trim((string) $label->textContent);
        }

        return $labels;
    }

    private function control(HTMLDocument $page, string $name): ?Element
    {
        return $page->querySelector(sprintf('[data-testid="admin-navigation-%s"]', $name));
    }

    /** @return list<string> the labels of the site's own navigation, as the front page draws it */
    private function siteNavigation(): array
    {
        $page = $this->pageOf($this->request('Core:Front:Home'));

        $labels = [];
        foreach ($page->querySelectorAll('[data-testid="layout-nav"] .c-nav__list > .c-nav__item > .c-nav__link') as $link) {
            $labels[] = trim((string) $link->textContent);
        }

        return $labels;
    }

    private function press(string $button): Response
    {
        return $this->request(self::PRESENTER, [$button => 'pressed']);
    }

    /** @param array<string, string> $post */
    private function request(string $presenterName = self::PRESENTER, array $post = []): Response
    {
        $presenter = $this->container()->getByType(IPresenterFactory::class)->createPresenter($presenterName);
        self::assertInstanceOf(Presenter::class, $presenter);
        $presenter->autoCanonicalize = false;

        return $presenter->run(new Request(
            $presenterName,
            $post === [] ? 'GET' : 'POST',
            ['action' => 'default', ...($post === [] ? [] : ['do' => self::FORM . '-submit'])],
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

    /** Somebody holding $piece in the business, through a role of the business's own. */
    private function signIn(string $piece): void
    {
        $container = $this->container();
        $tenant = $this->tenant ?? throw new \LogicException('The business is made with the build.');

        $this->generatedPassword = Random::generate(24, 'a-zA-Z0-9');
        $account = new User(
            'alice@example.com',
            $container->getByType(Passwords::class)->hash($this->generatedPassword),
            'Alice Ammonite',
            new DateTimeImmutable('2026-09-13T08:00:00+00:00'),
        );
        $container->getByType(Accounts::class)->save($account);

        $role = $piece === 'app:*'
            ? new Role(Role::OWNER, 'Owner', [$piece])
            : Role::ofBusiness($tenant, 'onlooker', 'Onlooker', [$piece]);

        $entityManager = $container->getByType(EntityManagerInterface::class);
        $entityManager->persist($role);
        $entityManager->persist(new Membership($tenant, $account, $role));
        $entityManager->flush();

        $container->getByType(SignedIn::class)->login('alice@example.com', $this->generatedPassword);
    }

    /** A build of Core and the two modules with no menu of their own to arrange, and one business. */
    private function container(): Container
    {
        if ($this->container instanceof Container) {
            return $this->container;
        }

        $this->schema = Database::schemaFor(self::class);
        $container = Boot::container(ModuleList::of(
            ['cms' => false, 'crm' => true, 'shop' => true],
            Bootstrap::rootDirectory(),
        ));
        Migrations::run($container);
        $this->tenant = Tenants::enter($container, 'Ammonite Bikes', Tenants::HOST);

        return $this->container = $container;
    }
}

<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Cms;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nette\Application\BadRequestException;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\ForwardResponse;
use Nette\Application\UI\Presenter;
use Nette\DI\Container;
use Nette\Security\Passwords;
use Nette\Security\User as SignedIn;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Cms\Application\Page\Pages;
use Trilobit\Cms\Domain\Menu\MenuItem;
use Trilobit\Cms\Domain\Menu\MenuRepository;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Content\Categories;
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
 * A form of the content administration does what it does only for somebody
 * the page it belongs to would admit, wherever it is posted.
 *
 * **The request this exists for was measured, not imagined.** An account
 * holding `app.administration.content:view` and nothing else posted the page
 * form to the list of pages - `do=page-submit` on the `default` action - and a
 * published page came into being. The form is a signal of its component, and
 * a signal is answered on whatever action the request names: the gates above
 * `add` and `edit` stood in front of the pages the form is drawn on and not in
 * front of the form. The three presenters here were built the same way, so
 * each is asked the same questions.
 *
 * What is asserted is the data and not only the answer. A refusal that left a
 * row behind would pass a test that only looked at the status, and a row left
 * behind is the whole of what went wrong.
 *
 * **Roles in between are asked too.** Being allowed to edit is not being
 * allowed to add, and being allowed to delete is not being allowed to write -
 * each of them could post to the list as well as somebody who may only read.
 *
 * **Another business's identifiers are asked about as well**, in the address
 * and in the form: every read of these tables is scoped to the business of the
 * request, and a form is the other place an identifier comes in through.
 */
#[CoversNothing]
final class FormsPostedWhereTheyDoNotBelongTest extends TestCase
{
    private const string PAGES = 'pages';

    private const string CATEGORIES = 'categories';

    private const string MENUS = 'menus';

    private const string REFUSAL = 'Core:Error:Refusal';

    private string $schema = '';

    private ?Container $container = null;

    private ?Tenant $here = null;

    /** A second business, made the first time a test needs one. */
    private ?Tenant $there = null;

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
        $this->here = null;

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    /** @return iterable<string, array{string}> */
    public static function forms(): iterable
    {
        yield 'pages' => [self::PAGES];
        yield 'categories' => [self::CATEGORIES];
        yield 'menus' => [self::MENUS];
    }

    /**
     * Every role that may read the content and may not add to it, crossed
     * with every form - including the roles that may do something else to it,
     * because what each of them was able to do through the list was add.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function formsAndRolesThatMayNotAdd(): iterable
    {
        $roles = [
            'only reading' => ['app.administration.content:view'],
            'editing' => ['app.administration.content:view', 'app.administration.content:edit'],
            'deleting' => ['app.administration.content:view', 'app.administration.content:delete'],
            'arranging' => ['app.administration.content:view', 'app.administration.content:change_priority'],
        ];

        foreach (self::forms() as $form => [$kind]) {
            foreach ($roles as $role => $grants) {
                yield $form . ', ' . $role => [$kind, $grants];
            }
        }
    }

    /**
     * The measured request, for every form and every role that may not add.
     *
     * @param list<string> $grants
     */
    #[DataProvider('formsAndRolesThatMayNotAdd')]
    public function testTheFormPostedToTheListByAnybodyWhoMayNotAddChangesNothing(string $kind, array $grants): void
    {
        $this->standing($kind);
        $before = $this->snapshot($kind);
        $this->signInHolding($grants);

        $answer = $this->post($kind, 'default', $this->values($kind));

        $this->assertRefused($answer);
        self::assertSame($before, $this->snapshot($kind), 'the form posted to the list changed the content');
    }

    /**
     * The form of an existing item, identifier and all, posted to the list.
     * The list has no item being edited, so what the form did there was make
     * a new one out of the values meant for the old.
     */
    #[DataProvider('forms')]
    public function testTheFormOfAnExistingItemPostedToTheListChangesNothing(string $kind): void
    {
        $id = $this->standing($kind);
        $before = $this->snapshot($kind);
        $this->signInHolding(['app.administration.content:view']);

        $answer = $this->post($kind, 'default', $this->values($kind), ['id' => $id]);

        $this->assertRefused($answer);
        self::assertSame($before, $this->snapshot($kind));
    }

    /**
     * An action nothing draws. It has no method and no declaration of its own,
     * so the only thing in front of it is the floor on the class - and the form
     * would be answered there as it was on the list.
     */
    #[DataProvider('forms')]
    public function testTheFormPostedToAnActionThatDoesNotExistChangesNothing(string $kind): void
    {
        $this->standing($kind);
        $before = $this->snapshot($kind);
        $this->signInHolding(['app.administration.content:view']);

        $answer = $this->post($kind, 'nowhere', $this->values($kind));

        $this->assertRefused($answer);
        self::assertSame($before, $this->snapshot($kind));
    }

    /** Somebody who may edit and not add is refused the page a new item is written on. */
    #[DataProvider('forms')]
    public function testSomebodyWhoMayEditButNotAddIsRefusedTheFormOfANewItem(string $kind): void
    {
        $this->standing($kind);
        $before = $this->snapshot($kind);
        $this->signInHolding(['app.administration.content:view', 'app.administration.content:edit']);

        $answer = $this->post($kind, 'add', $this->values($kind));

        $this->assertRefused($answer);
        self::assertSame($before, $this->snapshot($kind));
    }

    /** Somebody who may add and not edit is refused the form of an existing item. */
    #[DataProvider('forms')]
    public function testSomebodyWhoMayAddButNotEditCannotRewriteAnExistingItem(string $kind): void
    {
        $id = $this->standing($kind);
        $before = $this->snapshot($kind);
        $this->signInHolding(['app.administration.content:view', 'app.administration.content:add']);

        $answer = $this->post($kind, 'edit', $this->values($kind), ['id' => $id]);

        $this->assertRefused($answer);
        self::assertSame($before, $this->snapshot($kind));
    }

    /**
     * Somebody who may rewrite an item and not take it away presses the
     * delete button of its form. The button is drawn for them; pressing it is
     * refused.
     */
    #[DataProvider('forms')]
    public function testSomebodyWhoMayEditButNotDeleteCannotDelete(string $kind): void
    {
        $id = $this->standing($kind);
        $before = $this->snapshot($kind);
        $this->signInHolding(['app.administration.content:view', 'app.administration.content:edit']);

        $answer = $this->post($kind, 'edit', ['delete' => 'Delete'], ['id' => $id]);

        $this->assertRefused($answer);
        self::assertSame($before, $this->snapshot($kind));
    }

    /**
     * The delete button of an existing item pressed on the form of a new one,
     * by somebody who may add and not delete: there is nothing being edited on
     * that page, so there is nothing for the press to take away.
     */
    #[DataProvider('forms')]
    public function testSomebodyWhoMayAddButNotDeleteCannotDeleteThroughTheFormOfANewItem(string $kind): void
    {
        $id = $this->standing($kind);
        $before = $this->snapshot($kind);
        $this->signInHolding(['app.administration.content:view', 'app.administration.content:add']);

        $this->post($kind, 'add', ['delete' => 'Delete'], ['id' => $id]);

        self::assertSame($before, $this->snapshot($kind));
    }

    /**
     * An item of another business, named in the address of the form it would
     * be edited in, by somebody who owns this one.
     */
    #[DataProvider('forms')]
    public function testAnItemOfAnotherBusinessIsNotFoundThroughTheAddress(string $kind): void
    {
        $this->signInHolding(['app:*']);
        $foreign = $this->inAnotherBusiness(fn(): string => $this->standing($kind));
        $theirs = $this->inAnotherBusiness(fn(): array => $this->snapshot($kind));
        $ours = $this->snapshot($kind);

        $read = $this->answer($kind, new Request($this->presenterOf($kind), 'GET', ['action' => 'edit', 'id' => $foreign]));
        $written = $this->post($kind, 'edit', $this->values($kind), ['id' => $foreign]);
        $deleted = $this->post($kind, 'edit', ['delete' => 'Delete'], ['id' => $foreign]);

        foreach ([$read, $written, $deleted] as $answer) {
            self::assertInstanceOf(BadRequestException::class, $answer, 'another business\'s item was found here');
            self::assertSame(404, $answer->getHttpCode());
        }

        self::assertSame($ours, $this->snapshot($kind));
        self::assertSame($theirs, $this->inAnotherBusiness(fn(): array => $this->snapshot($kind)));
    }

    /**
     * A category of another business chosen in the form of a page or of a
     * category: it is not offered, and sent anyway it is not what anything
     * here ends up filed under.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function formsNamingACategory(): iterable
    {
        yield 'a page filed under it' => [self::PAGES, 'category'];
        yield 'a category filed under it' => [self::CATEGORIES, 'parent'];
    }

    #[DataProvider('formsNamingACategory')]
    public function testACategoryOfAnotherBusinessInTheFormIsNotFiledUnder(string $kind, string $field): void
    {
        $this->signInHolding(['app:*']);
        $foreign = $this->inAnotherBusiness(
            fn(): string => $this->container()->getByType(Categories::class)->create('Their shelf', 'their-shelf', null)->ref->id,
        );
        $theirs = $this->inAnotherBusiness(fn(): array => $this->snapshot(self::CATEGORIES));

        $this->post($kind, 'add', $this->values($kind, [$field => $foreign]));

        foreach ($this->snapshot($kind) as $row) {
            self::assertStringNotContainsString('their-shelf', $row, 'something here was filed under another business\'s category');
        }

        self::assertSame($theirs, $this->inAnotherBusiness(fn(): array => $this->snapshot(self::CATEGORIES)));
    }

    /** A page and an entry of another business chosen in the form of a menu entry. */
    public function testAPageOrAnEntryOfAnotherBusinessInTheMenuFormIsNotTaken(): void
    {
        $this->signInHolding(['app:*']);
        $page = $this->inAnotherBusiness(fn(): string => $this->standing(self::PAGES));
        $entry = $this->inAnotherBusiness(fn(): string => $this->standing(self::MENUS));
        $theirs = $this->inAnotherBusiness(fn(): array => $this->snapshot(self::MENUS));

        $this->post(self::MENUS, 'add', $this->values(self::MENUS, ['targetType' => 'page', 'page' => $page]));
        self::assertSame([], $this->snapshot(self::MENUS), 'an entry here was made to lead to another business\'s page');

        $this->post(self::MENUS, 'add', $this->values(self::MENUS, ['parent' => $entry]));
        foreach ($this->entries()->all() as $ours) {
            self::assertNull($ours->parent(), 'an entry here was filed under another business\'s entry');
        }

        self::assertSame($theirs, $this->inAnotherBusiness(fn(): array => $this->snapshot(self::MENUS)));
    }

    private function assertRefused(Response|BadRequestException $answer): void
    {
        if ($answer instanceof BadRequestException) {
            self::assertGreaterThanOrEqual(400, $answer->getHttpCode());
            self::assertLessThan(500, $answer->getHttpCode());

            return;
        }

        self::assertInstanceOf(ForwardResponse::class, $answer, sprintf(
            'the form was answered with %s rather than refused',
            $answer::class,
        ));
        self::assertSame(self::REFUSAL, $answer->getRequest()->getPresenterName());
    }

    /**
     * @param array<string, string> $post
     * @param array<string, string> $parameters
     */
    private function post(string $kind, string $action, array $post, array $parameters = []): Response|BadRequestException
    {
        return $this->answer($kind, new Request(
            $this->presenterOf($kind),
            'POST',
            ['action' => $action, 'do' => $this->formOf($kind) . '-submit', ...$parameters],
            $post,
        ));
    }

    /**
     * What the presenter answered, or the refusal it raised - both are answers
     * a person gets, and a test comparing them has to be able to hold either.
     */
    private function answer(string $kind, Request $request): Response|BadRequestException
    {
        $presenter = $this->container()->getByType(IPresenterFactory::class)->createPresenter($this->presenterOf($kind));
        self::assertInstanceOf(Presenter::class, $presenter);
        $presenter->autoCanonicalize = false;

        try {
            return $presenter->run($request);
        } catch (BadRequestException $refused) {
            return $refused;
        } finally {
            // Whatever the request loaded stays out of the next one's way, as
            // it would between two requests of a real browser.
            $this->container()->getByType(EntityManagerInterface::class)->clear();
        }
    }

    private function presenterOf(string $kind): string
    {
        return match ($kind) {
            self::PAGES => 'Cms:Admin:Page',
            self::CATEGORIES => 'Cms:Admin:Category',
            default => 'Cms:Admin:Menu',
        };
    }

    private function formOf(string $kind): string
    {
        return match ($kind) {
            self::PAGES => 'page',
            self::CATEGORIES => 'category',
            default => 'entry',
        };
    }

    /**
     * What a person would type into each form to make something new of it.
     *
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function values(string $kind, array $overrides = []): array
    {
        $values = match ($kind) {
            self::PAGES => [
                'title' => 'Written anyway',
                'category' => '',
                'segment' => 'written-anyway',
                'perex' => 'Nobody was asked.',
                'content' => 'Nobody was asked.',
                'seoTitle' => '',
                'seoDescription' => '',
                'status' => 'published',
            ],
            self::CATEGORIES => [
                'name' => 'Filed anyway',
                'parent' => '',
                'segment' => 'filed-anyway',
            ],
            default => [
                'menu' => Menu::MAIN,
                'label' => 'Listed anyway',
                'targetType' => 'url',
                'page' => '',
                'target' => 'https://example.com/anyway',
                'parent' => '',
                'position' => '3',
                'visible' => '1',
            ],
        };

        return [...$values, 'send' => 'Save', ...$overrides];
    }

    /**
     * An item already there, made the way the application makes one and not
     * through the form, so that what the form does is the only thing the test
     * is about. Returned as the identifier the address carries.
     */
    private function standing(string $kind): string
    {
        $container = $this->container();

        if ($kind === self::PAGES) {
            $page = $container->getByType(Pages::class)->create('Standing', 'standing');
            $container->getByType(Pages::class)->publish($page);

            return (string) $page->id();
        }

        if ($kind === self::CATEGORIES) {
            return $container->getByType(Categories::class)->create('Standing', 'standing', null)->ref->id;
        }

        $entry = MenuItem::toUrl(
            $container->getByType(Menus::class)->namedOrNew(Menu::MAIN),
            'Standing',
            'https://example.com/standing',
        );
        $this->entries()->save($entry);

        return (string) $entry->id();
    }

    /**
     * Everything of one kind in the business being worked in, as lines that
     * say what a person would notice changing.
     *
     * @return list<string>
     */
    private function snapshot(string $kind): array
    {
        $container = $this->container();
        $container->getByType(EntityManagerInterface::class)->clear();
        $rows = [];

        if ($kind === self::PAGES) {
            $pages = $container->getByType(Pages::class);
            foreach ($pages->all() as $page) {
                $rows[] = sprintf(
                    '%s | %s | %s | %s',
                    $page->id(),
                    $page->title(),
                    $page->isPublished() ? 'published' : 'draft',
                    $pages->addressOf($page) ?? '-',
                );
            }
        } elseif ($kind === self::CATEGORIES) {
            foreach ($container->getByType(Categories::class)->all() as $category) {
                $rows[] = sprintf('%s | %s | %s', $category->ref->id, $category->label, $category->path);
            }
        } else {
            foreach ($this->entries()->all() as $entry) {
                $rows[] = sprintf(
                    '%s | %s | %s | %s | %s',
                    $entry->id(),
                    $entry->label(),
                    $entry->target(),
                    $entry->position(),
                    $entry->parent()?->id() ?? '-',
                );
            }
        }

        sort($rows);

        return $rows;
    }

    /**
     * Runs $work inside a second business and comes back to this one.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function inAnotherBusiness(\Closure $work): mixed
    {
        $container = $this->container();
        $here = $this->here ?? throw new \LogicException('The business worked in is made with the container.');
        $there = $this->there ??= Tenants::create($container, 'Trilobite Tours', 'tours.example.com');

        Tenants::switchTo($container, $there);
        try {
            return $work();
        } finally {
            $container->getByType(EntityManagerInterface::class)->clear();
            Tenants::switchTo($container, $here);
        }
    }

    /**
     * Signs in somebody holding exactly $grants in this business.
     *
     * The account and its password are made here and the password is
     * generated, as in PageAdministrationTest, so that nothing anybody could
     * sign in with is in the repository.
     *
     * @param list<string> $grants
     */
    private function signInHolding(array $grants): void
    {
        $container = $this->container();
        $here = $this->here ?? throw new \LogicException('The business worked in is made with the container.');

        $password = Random::generate(24, 'a-zA-Z0-9');
        $account = new User(
            'bea@example.com',
            $container->getByType(Passwords::class)->hash($password),
            'Bea Belemnite',
            new DateTimeImmutable('2026-09-14T08:00:00+00:00'),
        );
        $container->getByType(Accounts::class)->save($account);

        $entityManager = $container->getByType(EntityManagerInterface::class);
        $role = in_array('app:*', $grants, true)
            ? new Role(Role::OWNER, 'Owner', $grants)
            : new Role('limited', 'Limited', $grants);
        $entityManager->persist($role);
        $tenant = $entityManager->getReference(Tenant::class, $here->id())
            ?? throw new \LogicException('The business worked in has to be saved before anybody can hold anything in it.');
        $entityManager->persist(new Membership($tenant, $account, $role));
        $entityManager->flush();
        $entityManager->clear();

        $container->getByType(SignedIn::class)->login('bea@example.com', $password);
    }

    private function entries(): MenuRepository
    {
        return $this->container()->getByType(MenuRepository::class);
    }

    /** A build with this module and a business to work in; who is signed in is each test's to say. */
    private function container(): Container
    {
        if ($this->container instanceof Container) {
            return $this->container;
        }

        $this->schema = Database::schemaFor(self::class);
        $container = Boot::container(ModuleList::of(
            ['cms' => true, 'crm' => false, 'shop' => false],
            Bootstrap::rootDirectory(),
        ));
        Migrations::run($container);
        $this->here = Tenants::enter($container, 'Ammonite Bikes', Tenants::HOST);

        return $this->container = $container;
    }
}

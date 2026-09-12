<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Cms;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Dom\HTMLDocument;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\JsonResponse;
use Nette\Application\Responses\RedirectResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\DI\Container;
use Nette\Security\Passwords;
use Nette\Security\User as SignedIn;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Cms\Application\Page\Pages;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Content\Address;
use Trilobit\Core\Content\Categories;
use Trilobit\Core\Content\PathRegistry;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Security\Accounts;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * Arranging categories from the administration, through the form, the way a
 * person does it.
 *
 * A category is Core's - a row of the register that other addresses are filed
 * under - and this module is where somebody arranges them. What is claimed
 * here is what a person sees happen: a category saved from the form is in the
 * register, renaming it keeps every page beneath it reachable at its old
 * address, and deleting one that still holds something is refused on the form
 * rather than taking the pages with it.
 *
 * The account and its password are made here the way PageAdministrationTest
 * makes them, and for the same reasons.
 */
#[CoversNothing]
final class CategoryAdministrationTest extends TestCase
{
    private const string PRESENTER = 'Cms:Admin:Category';

    private const string SUBMIT = 'category-submit';

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

    public function testAddingACategoryFromTheFormPutsItInTheRegister(): void
    {
        $response = $this->submit('add', $this->values());

        self::assertInstanceOf(RedirectResponse::class, $response, 'the form did not accept the category');

        $category = $this->onlyCategory();
        self::assertSame('guides', $category->path);
        self::assertSame('Guides', $category->label);
    }

    public function testTheListShowsEveryCategoryAndWhereItAnswers(): void
    {
        $this->submit('add', $this->values());
        $id = $this->onlyCategory()->ref->id;

        $document = $this->pageOf($this->submit('default', []));

        self::assertSame(
            'Guides',
            trim((string) $document->querySelector(sprintf('[data-testid="cms-category-open-%s"]', $id))?->textContent),
        );
        self::assertSame(
            '/guides',
            trim((string) $document->querySelector(sprintf('[data-testid="cms-category-address-%s"]', $id))?->textContent),
        );
    }

    /**
     * The test the plan asks for by name: after a category is renamed, the old
     * address of a page in it leads to the new one.
     */
    public function testRenamingACategoryFromTheFormKeepsTheOldAddressesOfItsPagesAnswering(): void
    {
        $this->submit('add', $this->values());
        $id = $this->onlyCategory()->ref->id;
        $this->container()->getByType(Pages::class)->create('First ride', 'first-ride', $id);

        $response = $this->submit('edit', $this->values(['name' => 'Handbook', 'segment' => 'handbook']), ['id' => $id]);

        self::assertInstanceOf(RedirectResponse::class, $response, 'the form did not accept the new name');
        $registry = $this->container()->getByType(PathRegistry::class);
        self::assertSame('handbook/first-ride', $registry->find('guides/first-ride')?->movedTo);
        self::assertSame('handbook', $registry->find('guides')?->movedTo);
        self::assertSame('Handbook', $this->onlyCategory()->label);
    }

    public function testDeletingACategoryThatHoldsAPageIsRefusedOnTheForm(): void
    {
        $this->submit('add', $this->values());
        $id = $this->onlyCategory()->ref->id;
        $this->container()->getByType(Pages::class)->create('First ride', 'first-ride', $id);

        $response = $this->submit('edit', ['delete' => 'Delete this category'], ['id' => $id]);

        $error = $this->pageOf($response)->querySelector('[data-testid="cms-category-error"]');
        self::assertNotNull($error, 'the form said nothing about the category not having been deleted');
        self::assertStringContainsString('still has', (string) $error->textContent);
        self::assertNotNull($this->container()->getByType(PathRegistry::class)->find('guides/first-ride'));
        self::assertSame('guides', $this->onlyCategory()->path);
    }

    public function testDeletingAnEmptyCategoryRemovesIt(): void
    {
        $this->submit('add', $this->values());
        $id = $this->onlyCategory()->ref->id;

        $response = $this->submit('edit', ['delete' => 'Delete this category'], ['id' => $id]);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame([], $this->container()->getByType(Categories::class)->all());
    }

    public function testAnotherCategoryIsOfferedToFileItUnderButNeverItself(): void
    {
        $this->submit('add', $this->values());
        $this->submit('add', $this->values(['name' => 'Winter', 'segment' => 'winter']));
        $winter = $this->container()->getByType(PathRegistry::class)->find('winter');
        self::assertNotNull($winter);

        $document = $this->pageOf($this->submit('edit', [], ['id' => $winter->ref->id]));

        $offered = [];
        foreach ($document->querySelectorAll('select[name="parent"] option') as $option) {
            $offered[] = trim((string) $option->textContent);
        }

        self::assertContains('Guides (/guides)', $offered);
        self::assertNotContains('Winter (/winter)', $offered);
    }

    /** The button on the form asks the register, and the register answers with what saving would accept. */
    public function testTheFormSuggestsTheLastPartFromTheName(): void
    {
        $this->submit('add', $this->values());

        $payload = $this->suggestion('add', ['title' => 'Guides']);

        self::assertSame(['segment' => 'guides-2', 'message' => ''], $payload);
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function values(array $overrides = []): array
    {
        return [
            'name' => 'Guides',
            'parent' => '',
            'segment' => 'guides',
            'send' => 'Save',
            ...$overrides,
        ];
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $parameters
     *
     * @return array<mixed>
     */
    private function suggestion(string $action, array $query, array $parameters = []): array
    {
        $presenter = $this->presenter();
        $response = $presenter->run(new Request(
            self::PRESENTER,
            'GET',
            ['action' => $action, 'do' => 'suggestSegment', ...$query, ...$parameters],
        ));

        self::assertInstanceOf(JsonResponse::class, $response);
        $payload = $response->getPayload();
        self::assertIsArray($payload);

        return $payload;
    }

    /**
     * @param array<string, string> $post
     * @param array<string, string> $parameters
     */
    private function submit(string $action, array $post, array $parameters = []): Response
    {
        return $this->presenter()->run(new Request(
            self::PRESENTER,
            $post === [] ? 'GET' : 'POST',
            ['action' => $action, ...($post === [] ? [] : ['do' => self::SUBMIT]), ...$parameters],
            $post,
        ));
    }

    private function presenter(): Presenter
    {
        $presenter = $this->container()->getByType(IPresenterFactory::class)->createPresenter(self::PRESENTER);
        self::assertInstanceOf(Presenter::class, $presenter);
        $presenter->autoCanonicalize = false;

        return $presenter;
    }

    private function pageOf(Response $response): HTMLDocument
    {
        self::assertInstanceOf(TextResponse::class, $response);
        $source = $response->getSource();
        self::assertInstanceOf(\Stringable::class, $source);

        return HTMLDocument::createFromString((string) $source, LIBXML_NOERROR);
    }

    private function onlyCategory(): Address
    {
        $categories = $this->container()->getByType(Categories::class)->all();

        self::assertCount(1, $categories, 'the administration was expected to have made exactly one category');

        return $categories[0];
    }

    /** A build with this module, a tenant, and its owner signed in - as in PageAdministrationTest. */
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
        $tenant = Tenants::enter($container, 'Ammonite Bikes', Tenants::HOST);

        $this->generatedPassword = Random::generate(24, 'a-zA-Z0-9');
        $account = new User(
            'alice@example.com',
            $container->getByType(Passwords::class)->hash($this->generatedPassword),
            'Alice Ammonite',
            new DateTimeImmutable('2026-09-12T08:00:00+00:00'),
        );
        $container->getByType(Accounts::class)->save($account);

        $entityManager = $container->getByType(EntityManagerInterface::class);
        $role = new Role(Role::OWNER, 'Owner', ['app:*']);
        $entityManager->persist($role);
        $entityManager->persist(new Membership($tenant, $account, $role));
        $entityManager->flush();

        $container->getByType(SignedIn::class)->login('alice@example.com', $this->generatedPassword);

        return $this->container = $container;
    }
}

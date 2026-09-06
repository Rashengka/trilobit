<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Cms;

use Dom\HTMLDocument;
use Nette\Application\BadRequestException;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request as ApplicationRequest;
use Nette\Application\Response;
use Nette\Application\Responses\RedirectResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\DI\Container;
use Nette\Http\IRequest;
use Nette\Http\IResponse;
use Nette\Routing\Router;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Cms\Application\Page\Pages;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Content\PathRefused;
use Trilobit\Core\Content\PathRegistry;
use Trilobit\Core\Contract\Content\ContentRef;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Double\StandInHttpRequest;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * What a visitor gets at the address of a page, and what they get at the
 * address of one that is not published.
 *
 * The pair is the whole of the claim: 200 and 404 out of the same address, the
 * same register row and the same presenter, with nothing between them but
 * whether somebody said the page was ready. A suite that only asked for the
 * published one would pass on a build that shows drafts to the world.
 *
 * No route is written for any of this. The address is a row in Core's register
 * and the catch-all reads it, which is what lets a page sit at the root of the
 * site without the module's name being in the URL - see
 * Trilobit\Cms\Content\CmsContentTypes.
 */
#[CoversNothing]
final class PublishedPageTest extends TestCase
{
    private const string HOST = 'http://localhost';

    private string $schema = '';

    protected function setUp(): void
    {
        $this->schema = Database::schemaFor(self::class);
    }

    protected function tearDown(): void
    {
        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testAPublishedPageAnswersAtItsAddress(): void
    {
        $container = $this->site();
        $pages = $this->pages($container);

        $page = $pages->create('About us', 'about-us');
        $pages->revise($page, 'About us', 'Who we are.', "We make bicycles.\nWe have done for years.", '', 'A small workshop.');
        $pages->publish($page);

        $document = $this->page($container, '/about-us');

        self::assertSame('About us', $document->querySelector('[data-testid="cms-page-title"]')?->textContent);
        self::assertSame('Who we are.', $document->querySelector('[data-testid="cms-page-perex"]')?->textContent);
        self::assertStringContainsString(
            'We make bicycles.',
            (string) $document->querySelector('[data-testid="cms-page-body"]')?->textContent,
        );
    }

    /**
     * The permalink and the description are written into the head of the
     * document, because a page nobody links to is found through them or not at
     * all.
     */
    public function testAPublishedPageNamesItsPermalinkAndItsDescription(): void
    {
        $container = $this->site();
        $pages = $this->pages($container);

        $page = $pages->create('About us', 'about-us');
        $pages->revise($page, 'About us', '', '', '', 'A small workshop.');
        $pages->publish($page);

        $document = $this->page($container, '/about-us');

        self::assertSame(
            self::HOST . '/about-us',
            $document->querySelector('link[rel="canonical"]')?->getAttribute('href'),
        );
        self::assertSame(
            'A small workshop.',
            $document->querySelector('meta[name="description"]')?->getAttribute('content'),
        );
    }

    /**
     * The other half, and the one a build would otherwise fail silently: a
     * draft is routed - the address is claimed - and then refused, rather than
     * being unreachable in some way a visitor could tell apart from a page
     * that was never written.
     */
    public function testAPageThatIsNotPublishedIsNotFound(): void
    {
        $container = $this->site();
        $this->pages($container)->create('A draft', 'about-us');

        self::assertNotNull(
            $this->route($container, '/about-us'),
            'the address of a draft is claimed, so the register has to route it',
        );

        try {
            $this->respondTo($container, '/about-us');
            self::fail('a page that is not published answered');
        } catch (BadRequestException $refused) {
            self::assertSame(IResponse::S404_NotFound, $refused->getHttpCode());
        }
    }

    /**
     * Withdrawing a published page takes it away again. Publishing is not a
     * one-way door, and the address is not given back when it closes; see
     * Trilobit\Cms\Domain\Page\Page.
     */
    public function testAWithdrawnPageStopsAnswering(): void
    {
        $container = $this->site();
        $pages = $this->pages($container);

        $page = $pages->create('About us', 'about-us');
        $pages->publish($page);
        $pages->withdraw($page);

        $this->expectException(BadRequestException::class);
        $this->respondTo($container, '/about-us');
    }

    /**
     * The address of a draft is held for it. Without that, a page could be
     * written for a week and lose its address to something else on the day it
     * was published.
     */
    public function testTheAddressOfADraftIsAlreadyTaken(): void
    {
        $container = $this->site();
        $this->pages($container)->create('A draft', 'about-us');

        $this->expectException(PathRefused::class);
        $this->expectExceptionMessage("'about-us' is already the address of something else");

        $container->getByType(PathRegistry::class)
            ->register(new ContentRef('demo.section', '99'), 'about-us', 'Something else');
    }

    /** Decision R6: a page cannot be saved at an address a static route already answers at. */
    public function testAPageCannotBeSavedUnderAReservedBeginning(): void
    {
        $container = $this->site();

        $this->expectException(PathRefused::class);
        $this->expectExceptionMessage("cannot start with 'admin'");

        $this->pages($container)->create('A page called admin', 'admin');
    }

    /** And nothing is left behind when it is refused: a page nothing can reach is litter, not a draft. */
    public function testAPageWhoseAddressWasRefusedIsNotLeftBehind(): void
    {
        $container = $this->site();
        $pages = $this->pages($container);

        try {
            $pages->create('A page called admin', 'admin');
        } catch (PathRefused) {
            // The refusal is what the test above is about; this one is about
            // what the database looks like afterwards.
        }

        self::assertSame([], $pages->all());
    }

    /** Decision R4: moving a page leaves its old address behind as a permanent redirect. */
    public function testMovingAPageLeavesTheOldAddressPointingAtTheNewOne(): void
    {
        $container = $this->site();
        $pages = $this->pages($container);

        $page = $pages->create('About us', 'about-us');
        $pages->publish($page);
        $pages->moveTo($page, 'who-we-are');

        $response = $this->respondTo($container, '/about-us');

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(IResponse::S301_MovedPermanently, $response->getCode());
        self::assertSame(self::HOST . '/who-we-are', $response->getUrl());
    }

    /**
     * The register keeps its own copy of the title, for trails and menus that
     * must not have to call into the module that owns each step. A copy has to
     * be told when the original changes.
     */
    public function testRenamingAPageRenamesItInTheRegister(): void
    {
        $container = $this->site();
        $pages = $this->pages($container);

        $page = $pages->create('About us', 'about-us');
        $pages->revise($page, 'Who we are', '', '', '', '');

        $address = $container->getByType(PathRegistry::class)->find('about-us');

        self::assertNotNull($address);
        self::assertSame('Who we are', $address->label);
    }

    private function pages(Container $container): Pages
    {
        return $container->getByType(Pages::class);
    }

    private function page(Container $container, string $path): HTMLDocument
    {
        $response = $this->respondTo($container, $path);
        self::assertInstanceOf(TextResponse::class, $response, 'the address answered with something other than a page');
        $source = $response->getSource();
        self::assertInstanceOf(\Stringable::class, $source);

        return HTMLDocument::createFromString((string) $source, LIBXML_NOERROR);
    }

    /** The whole of one request: the router says what the path means, and the page it names answers. */
    private function respondTo(Container $container, string $path): Response
    {
        $httpRequest = $container->getByType(IRequest::class);
        self::assertInstanceOf(StandInHttpRequest::class, $httpRequest);
        $httpRequest->arriveAt(self::HOST . $path);

        $params = $this->route($container, $path);
        self::assertNotNull($params, sprintf('nothing is routed at %s', $path));

        $name = $params['presenter'] ?? null;
        self::assertIsString($name);

        $presenter = $container->getByType(IPresenterFactory::class)->createPresenter($name);
        self::assertInstanceOf(Presenter::class, $presenter);

        return $presenter->run(new ApplicationRequest($name, 'GET', $params));
    }

    /** @return array<string, mixed>|null */
    private function route(Container $container, string $path): ?array
    {
        $httpRequest = $container->getByType(IRequest::class);
        self::assertInstanceOf(StandInHttpRequest::class, $httpRequest);
        $httpRequest->arriveAt(self::HOST . $path);

        return $container->getByType(Router::class)->match($httpRequest);
    }

    /** A build with this module and a tenant to work inside, which is the least a page needs. */
    private function site(): Container
    {
        $container = Boot::container(
            ModuleList::of(['cms' => true, 'crm' => false, 'shop' => false], Bootstrap::rootDirectory()),
            config: ['services' => ['http.request' => ['factory' => StandInHttpRequest::class]]],
        );
        Migrations::run($container);
        Tenants::enter($container, 'Ammonite Bikes', Tenants::HOST);

        return $container;
    }
}

<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Content;

use Dom\HTMLDocument;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request as ApplicationRequest;
use Nette\Application\Response;
use Nette\Application\Responses\RedirectResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\DI\Container;
use Nette\Http\IRequest;
use Nette\Http\IResponse;
use Nette\Http\Request as HttpRequest;
use Nette\Http\UrlScript;
use Nette\Routing\Router;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Content\PathRegistry;
use Trilobit\Core\Contract\Content\ContentRef;
use Trilobit\Core\Routing\AdminRoutes;
use Trilobit\Tests\Database;
use Trilobit\Tests\Double\Content\DemoContentTypes;
use Trilobit\Tests\Double\DemoModule;
use Trilobit\Tests\Double\StandInHttpRequest;
use Trilobit\Tests\Migrations;

/**
 * The public address space end to end: a request goes in, the register decides
 * what it means, and a page or a permanent redirect comes back.
 *
 * The content belongs to a module that does not exist yet - see
 * Trilobit\Tests\Double\DemoModule for why standing in for one is the right
 * answer rather than inventing an entity inside a real module. What is under
 * test is the mechanism, and the mechanism is finished before there is
 * anything to put through it.
 *
 * Pages are run with the framework's own canonicalisation left on, which is
 * the only way the claim in R12 can be made honestly: a product reached
 * through its second category has to come back as a page, not as a redirect
 * to the first one.
 */
#[CoversNothing]
final class PublicAddressTest extends TestCase
{
    private const string HOST = 'http://localhost';

    private string $schema = '';

    protected function tearDown(): void
    {
        if ($this->schema !== '') {
            Database::drop($this->schema);
        }
    }

    public function testAContentAddressIsDrawnByThePageItsTypeIsBoundTo(): void
    {
        $container = $this->catalogue();

        $document = $this->page($container, '/bikes/mountain');

        $content = $document->querySelector('[data-testid="demo-content"]');
        self::assertNotNull($content);
        self::assertSame('Mountain bikes', $document->querySelector('[data-testid="demo-heading"]')?->textContent);
        self::assertSame('section', $content->getAttribute('data-kind'));
        self::assertSame('2', $content->getAttribute('data-content-id'));
    }

    /** Decision R8: neither the module nor the presenter is anywhere in the address. */
    public function testTheAddressCarriesNeitherAModuleNorAPresenter(): void
    {
        $container = $this->catalogue();
        $match = $this->match($container, '/bikes/mountain');

        self::assertSame(DemoModule::PAGE, $match['presenter'] ?? null);
        self::assertSame('section', $match['action'] ?? null);
    }

    public function testAnAddressNobodyClaimedIsNotRouted(): void
    {
        $container = $this->catalogue();

        self::assertNull($this->route($container, '/bikes/nothing-here'));
    }

    /**
     * Decision R6 seen from the reading side: the catch-all never answers
     * under a reserved beginning, whether or not anything is registered there.
     */
    public function testTheCatchAllNeverAnswersUnderAReservedBeginning(): void
    {
        $container = $this->catalogue();
        $match = $this->route($container, '/' . AdminRoutes::PATH);

        self::assertSame('Core:Admin:Dashboard', $match['presenter'] ?? null);
    }

    /** Decision R5: one spelling answers, the others lead to it permanently. */
    public function testAnyOtherSpellingOfAnAddressIsPermanentlyRedirected(): void
    {
        $container = $this->catalogue();

        self::assertSame(
            self::HOST . '/bikes/mountain',
            $this->redirectFrom($container, '/Bikes/Mountain/'),
        );
    }

    /** Decision R4: an address that moved keeps answering, and says where it went. */
    public function testARenamedAddressIsPermanentlyRedirected(): void
    {
        $container = $this->catalogue();
        $container->getByType(PathRegistry::class)->rename('bikes/mountain', 'bikes/off-road');

        self::assertSame(
            self::HOST . '/bikes/off-road',
            $this->redirectFrom($container, '/bikes/mountain'),
        );
    }

    /** Decision R4: and so does everything that was filed under it. */
    public function testADescendantOfARenamedAddressIsPermanentlyRedirectedToo(): void
    {
        $container = $this->catalogue();
        $container->getByType(PathRegistry::class)->rename('bikes/mountain', 'bikes/off-road');

        self::assertSame(
            self::HOST . '/bikes/off-road/mountain-bike-x',
            $this->redirectFrom($container, '/bikes/mountain/mountain-bike-x'),
        );
    }

    /**
     * Decision R12: every address of one product answers with the page, and
     * the ones that are not the permalink say which one is.
     */
    public function testEveryAddressOfAProductAnswersWithThePage(): void
    {
        $container = $this->catalogue();

        $canonical = $this->page($container, '/bikes/mountain/mountain-bike-x');
        $secondary = $this->page($container, '/sale/mountain-bike-x');

        foreach ([$canonical, $secondary] as $document) {
            self::assertSame('Mountain bike X', $document->querySelector('[data-testid="demo-heading"]')?->textContent);
        }

        self::assertSame(
            self::HOST . '/bikes/mountain/mountain-bike-x',
            $canonical->querySelector('link[rel="canonical"]')?->getAttribute('href'),
        );
        self::assertSame(
            self::HOST . '/bikes/mountain/mountain-bike-x',
            $secondary->querySelector('link[rel="canonical"]')?->getAttribute('href'),
        );
    }

    /** Decision R12: the trail is drawn from the address the visitor arrived at. */
    public function testTheTrailFollowsTheAddressTheVisitorUsed(): void
    {
        $container = $this->catalogue();

        self::assertSame(
            ['Bikes', 'Mountain bikes', 'Mountain bike X'],
            $this->trailIn($this->page($container, '/bikes/mountain/mountain-bike-x')),
        );
        self::assertSame(
            ['Sale', 'Mountain bike X'],
            $this->trailIn($this->page($container, '/sale/mountain-bike-x')),
        );
    }

    /**
     * A page reached by a static route has no permalink to name, so nothing is
     * drawn - an empty canonical element would be a claim about a page that
     * has no address in the register at all.
     */
    public function testAPageWithNoAddressNamesNoCanonicalOne(): void
    {
        $container = $this->catalogue();
        $document = HTMLDocument::createFromString(
            $this->textOf($this->respondTo($container, '/')),
            LIBXML_NOERROR,
        );

        self::assertNull($document->querySelector('link[rel="canonical"]'));
    }

    /**
     * The register outlives the module that wrote into it, so an address whose
     * type nothing in this build draws is not routed rather than routed to an
     * error - the row is waiting for the module to come back, and the rest of
     * the address space is untouched by its absence.
     */
    public function testAnAddressOfATypeNoEnabledModuleDrawsIsNotRouted(): void
    {
        $container = $this->catalogue(withProducts: false);

        self::assertNull($this->route($container, '/bikes/mountain/mountain-bike-x'));
        self::assertNotNull($this->route($container, '/bikes/mountain'));
    }

    /**
     * Decision R3: a page points at another module's content by type and
     * identifier, and the module turns that into a link. Nothing in the page
     * names the module.
     */
    public function testAPageDrawsALinkIntoAModuleThatIsPresent(): void
    {
        $link = $this->page($this->catalogue(), '/bikes/mountain')->querySelector('[data-testid="related-link"]');

        self::assertNotNull($link);
        self::assertSame('/bikes/mountain/mountain-bike-x', $link->getAttribute('href'));
        self::assertSame('Mountain bike X', $link->textContent);
    }

    /**
     * Decision R3, and the half nobody meets while every module happens to be
     * switched on: with the owning module absent the port answers null, and
     * the page has to come back whole and without an anchor - not with an
     * empty one, and not with an error.
     */
    public function testAPageLinkingIntoAModuleThatIsAbsentDrawsNoLinkAndNoError(): void
    {
        $document = $this->page($this->catalogue(withProducts: false), '/bikes/mountain');

        self::assertSame('Mountain bikes', $document->querySelector('[data-testid="demo-heading"]')?->textContent);
        self::assertNull($document->querySelector('[data-testid="related-link"]'));
        self::assertNull($document->querySelector('a[href=""]'), 'a dead anchor is worse than no anchor');
    }

    /** @return list<string> */
    private function trailIn(HTMLDocument $document): array
    {
        $labels = [];
        foreach ($document->querySelectorAll('[data-testid="breadcrumb"]') as $crumb) {
            $labels[] = trim((string) $crumb->textContent);
        }

        return $labels;
    }

    private function page(Container $container, string $path): HTMLDocument
    {
        return HTMLDocument::createFromString($this->textOf($this->respondTo($container, $path)), LIBXML_NOERROR);
    }

    private function textOf(Response $response): string
    {
        self::assertInstanceOf(TextResponse::class, $response, 'the address answered with something other than a page');
        $source = $response->getSource();
        self::assertInstanceOf(\Stringable::class, $source);

        return (string) $source;
    }

    private function redirectFrom(Container $container, string $path): string
    {
        $response = $this->respondTo($container, $path);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(IResponse::S301_MovedPermanently, $response->getCode());

        return $response->getUrl();
    }

    /**
     * The whole of one request: the router decides what the path means, and
     * the page it names answers.
     *
     * The build is told which address the visitor asked for before either
     * happens, because a presenter takes its request once, when it is
     * constructed, and builds its link generator out of it there. Without
     * that, every page would look as though it had been reached at the root
     * and the framework's own canonicalisation would redirect away from every
     * address in the register - which would make the claim in R12 impossible
     * to state.
     */
    private function respondTo(Container $container, string $path): Response
    {
        $httpRequest = $container->getByType(IRequest::class);
        self::assertInstanceOf(StandInHttpRequest::class, $httpRequest);
        $httpRequest->arriveAt(self::HOST . $path);

        $params = $container->getByType(Router::class)->match($httpRequest);
        self::assertNotNull($params, sprintf('nothing is routed at %s', $path));

        $name = $params['presenter'] ?? null;
        self::assertIsString($name);

        $presenter = $container->getByType(IPresenterFactory::class)->createPresenter($name);
        self::assertInstanceOf(Presenter::class, $presenter);

        return $presenter->run(new ApplicationRequest($name, 'GET', $params));
    }

    /** @return array<string, mixed> */
    private function match(Container $container, string $path): array
    {
        $params = $this->route($container, $path);
        self::assertNotNull($params, sprintf('nothing is routed at %s', $path));

        return $params;
    }

    /** @return array<string, mixed>|null */
    private function route(Container $container, string $path): ?array
    {
        return $container->getByType(Router::class)->match(new HttpRequest(new UrlScript(self::HOST . $path, '/')));
    }

    /**
     * Sections two deep with one product filed in two of them - the smallest
     * shape every decision about the address space says something about.
     *
     * The rows are written whether or not this build has the module that owns
     * products, because that is the situation a customer's database is really
     * in: the register keeps what was put there, and switching a module off
     * takes away the pages rather than the rows.
     */
    private function catalogue(bool $withProducts = true): Container
    {
        $this->schema = Database::schemaFor(self::class);
        $container = DemoModule::container($withProducts);
        Migrations::run($container);

        $registry = $container->getByType(PathRegistry::class);
        $registry->register(new ContentRef(DemoContentTypes::SECTION, '1'), 'bikes', 'Bikes');
        $registry->register(new ContentRef(DemoContentTypes::SECTION, '2'), 'bikes/mountain', 'Mountain bikes', 'bikes');
        $registry->register(new ContentRef(DemoContentTypes::SECTION, '3'), 'sale', 'Sale');

        $product = DemoModule::relatedContent();
        $registry->register($product, 'bikes/mountain/mountain-bike-x', 'Mountain bike X', 'bikes/mountain');
        $registry->register($product, 'sale/mountain-bike-x', 'Mountain bike X', 'sale');

        return $container;
    }
}

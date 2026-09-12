<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Preference;

use Dom\Element;
use Dom\HTMLDocument;
use Nette\DI\Container;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Preference\PreferenceCatalogue;
use Trilobit\Core\Presentation\Styleguide\StyleguidePage;
use Trilobit\Core\Presentation\Styleguide\StyleguidePages;
use Trilobit\Core\Routing\StyleguideRoutes;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Combination\Build;
use Trilobit\Tests\Double\StandInHttpRequest;

/**
 * How wide the content is, decided the whole way through: a cookie on the
 * device, a page that may insist on something else, and the attribute the
 * browser is finally handed.
 *
 * Trilobit\Tests\Unit\Core\Preference\PreferencesTest makes the same claims
 * about the object; this makes them about a page the application really
 * rendered, which is where they are worth something. The two ways it could hold
 * in the object and fail here are both real: a presenter reading the choice
 * before the layout is drawn, and a presenter overruling it in a method the
 * framework calls at the wrong moment.
 *
 * The style guide is used because it is the one place in this checkout where
 * one presenter answers at pages drawn at two different widths - which is also
 * the point being made. A width belongs to the page, and a presenter is not a
 * page: every page of the guide is the same action of the same class, and the
 * one that insists on a width says so in the list of pages rather than in the
 * class. The page is found in that list here rather than named, so that moving
 * it cannot leave this test measuring an ordinary page.
 */
#[CoversNothing]
final class ContentWidthTest extends TestCase
{
    /** The front page of the guide, which insists on nothing. */
    private const string ORDINARY = '/' . StyleguideRoutes::PATH;

    private ?Container $container = null;

    public function testAPageIsDrawnAtTheWidthSomebodyChose(): void
    {
        $this->device()->carry($this->cookie(), 'wide');

        self::assertSame('wide', $this->widthOf(self::ORDINARY));
    }

    /** Nobody has chosen anything, so the page is drawn at what this build starts in. */
    public function testAVisitorWhoHasChosenNothingIsDrawnAtTheBuildsWidth(): void
    {
        self::assertSame('content', $this->widthOf(self::ORDINARY));
    }

    public function testAPageThatInsistsOnAWidthOverrulesTheChoice(): void
    {
        $this->device()->carry($this->cookie(), 'content');

        self::assertSame($this->insisting()->width, $this->widthOf($this->insistingPath()));
    }

    /**
     * The claim the shape has to carry: a width is a property of the page and
     * not of the class behind it. Both pages below are answered by one
     * presenter, and they are drawn at different widths in the same build and
     * with the same device in front of them.
     */
    public function testTwoPagesOfOnePresenterAreDrawnAtDifferentWidths(): void
    {
        $this->device()->carry($this->cookie(), 'content');

        self::assertSame('content', $this->widthOf(self::ORDINARY));
        self::assertSame($this->insisting()->width, $this->widthOf($this->insistingPath()));
    }

    /**
     * What a page insists on is how it is drawn and nothing more. If it reached
     * the switch, the control would show the width of this one report as though
     * it were the setting - and the next thing the person clicked would save it.
     */
    public function testThePageThatInsistsDoesNotChangeWhatTheControlsShow(): void
    {
        $this->device()->carry($this->cookie(), 'content');

        $control = $this->documentOf($this->insistingPath())
            ->querySelector('[data-preference="content-width"][data-preference-value="content"]');

        // The insisting page carries no switch of its own, which is the simplest
        // way for the two never to disagree. The claim below is that it stayed
        // that way rather than that a control happens to be drawn correctly.
        self::assertNull($control);
    }

    /** Overruling one preference says nothing about the others. */
    public function testAnInsistedWidthLeavesTheThemeAlone(): void
    {
        $this->device()->carry($this->cookie(), 'content');
        $this->device()->carry(
            $this->catalogue()->preference(PreferenceCatalogue::THEME)->cookie(),
            'ledger',
        );

        self::assertSame('ledger', $this->attributeOf('data-theme', $this->insistingPath()));
    }

    /**
     * The one page of the guide that insists on a width, found where the guide
     * says so. Exactly one: a second would be a second specimen of the same
     * thing, and none would leave every case above comparing a page with
     * itself.
     */
    private function insisting(): StyleguidePage
    {
        $insisting = array_values(array_filter(
            $this->container()->getByType(StyleguidePages::class)->pages(),
            static fn(StyleguidePage $page): bool => $page->width !== null,
        ));

        self::assertCount(1, $insisting, 'the style guide has to have exactly one page that insists on a width');
        self::assertNotSame('content', $insisting[0]->width, 'a page insisting on the default width shows nothing');

        return $insisting[0];
    }

    private function insistingPath(): string
    {
        return self::ORDINARY . '/' . $this->insisting()->path();
    }

    private function widthOf(string $path): ?string
    {
        return $this->attributeOf('data-content-width', $path);
    }

    /** What the html element of that page carries, which is the whole of how a preference reaches a browser. */
    private function attributeOf(string $attribute, string $path): ?string
    {
        $root = $this->documentOf($path)->documentElement;
        self::assertInstanceOf(Element::class, $root, 'the page has no html element at all');

        return $root->getAttribute($attribute);
    }

    private function documentOf(string $path): HTMLDocument
    {
        return HTMLDocument::createFromString(Build::renderPath($this->container(), $path), LIBXML_NOERROR);
    }

    private function cookie(): string
    {
        return $this->catalogue()->preference(PreferenceCatalogue::CONTENT_WIDTH)->cookie();
    }

    private function catalogue(): PreferenceCatalogue
    {
        return $this->container()->getByType(PreferenceCatalogue::class);
    }

    private function device(): StandInHttpRequest
    {
        $request = $this->container()->getByType(StandInHttpRequest::class);
        self::assertInstanceOf(StandInHttpRequest::class, $request);

        return $request;
    }

    private function container(): Container
    {
        return $this->container ??= Boot::container(
            ModuleList::of([], Bootstrap::rootDirectory()),
            styleguide: true,
            config: ['services' => ['http.request' => ['factory' => StandInHttpRequest::class]]],
        );
    }
}

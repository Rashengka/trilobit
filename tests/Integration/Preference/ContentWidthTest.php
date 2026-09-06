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
 * The style guide is used because it is the one page in this checkout that
 * answers at two actions with two different widths - which is also the point
 * being made. A width belongs to the page, and a presenter is not a page: one
 * class answers at several addresses, and the class is the wrong place to say
 * how wide any of them is.
 */
#[CoversNothing]
final class ContentWidthTest extends TestCase
{
    private const string PRESENTER = 'Core:Styleguide:Overview';

    /** The action that insists, and what it insists on; see OverviewPresenter. */
    private const string INSISTING_ACTION = 'fullWidth';

    private const string INSISTED_WIDTH = 'full';

    private ?Container $container = null;

    public function testAPageIsDrawnAtTheWidthSomebodyChose(): void
    {
        $this->device()->carry($this->cookie(), 'wide');

        self::assertSame('wide', $this->widthOf(self::PRESENTER));
    }

    /** Nobody has chosen anything, so the page is drawn at what this build starts in. */
    public function testAVisitorWhoHasChosenNothingIsDrawnAtTheBuildsWidth(): void
    {
        self::assertSame('content', $this->widthOf(self::PRESENTER));
    }

    public function testAPageThatInsistsOnAWidthOverrulesTheChoice(): void
    {
        $this->device()->carry($this->cookie(), 'content');

        self::assertSame(self::INSISTED_WIDTH, $this->widthOf(self::PRESENTER, self::INSISTING_ACTION));
    }

    /**
     * The claim the shape has to carry: a width is a property of the page and
     * not of the class behind it. Both actions below are answered by one
     * presenter, and they are drawn at different widths in the same build and
     * with the same device in front of them.
     */
    public function testTwoActionsOfOnePresenterAreDrawnAtDifferentWidths(): void
    {
        $this->device()->carry($this->cookie(), 'content');

        self::assertSame('content', $this->widthOf(self::PRESENTER));
        self::assertSame(self::INSISTED_WIDTH, $this->widthOf(self::PRESENTER, self::INSISTING_ACTION));
    }

    /**
     * What a page insists on is how it is drawn and nothing more. If it reached
     * the switch, the control would show the width of this one report as though
     * it were the setting - and the next thing the person clicked would save it.
     */
    public function testThePageThatInsistsDoesNotChangeWhatTheControlsShow(): void
    {
        $this->device()->carry($this->cookie(), 'content');

        $control = $this->documentOf(self::PRESENTER, self::INSISTING_ACTION)
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

        self::assertSame('ledger', $this->attributeOf('data-theme', self::PRESENTER, self::INSISTING_ACTION));
    }

    private function widthOf(string $presenter, string $action = 'default'): ?string
    {
        return $this->attributeOf('data-content-width', $presenter, $action);
    }

    /** What the html element of that page carries, which is the whole of how a preference reaches a browser. */
    private function attributeOf(string $attribute, string $presenter, string $action = 'default'): ?string
    {
        $root = $this->documentOf($presenter, $action)->documentElement;
        self::assertInstanceOf(Element::class, $root, 'the page has no html element at all');

        return $root->getAttribute($attribute);
    }

    private function documentOf(string $presenter, string $action): HTMLDocument
    {
        return HTMLDocument::createFromString(
            Build::render($this->container(), $presenter, $action),
            LIBXML_NOERROR,
        );
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

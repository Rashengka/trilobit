<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Component\ComponentRegistry;

/**
 * c-carousel is a strip of slides the browser scrolls a slide at a time, and
 * what a screen reader is told about it is all in the markup: a region
 * described as a carousel and named, and every slide a group described as a
 * slide and named for its place among the others (the APG carousel pattern).
 * That is what is held here. What the browser does with it - the snap, the
 * keys, the buttons turning it - is measured in tests/e2e/carousel.spec.ts.
 */
#[CoversNothing]
final class CarouselTest extends TestCase
{
    private const string COMPONENT = 'c-carousel';

    private const string CALL = "{embed block carousel, label: 'Specimens', slides: ['Head', 'Body', 'Tail'], id: 'shown'}"
        . '{block carouselSlide}<p class="slide-text">{$slide}</p>{/block}'
        . '{/embed}';

    public function testItIsARegionNamedAndDescribedAsACarousel(): void
    {
        $carousel = $this->carousel();

        self::assertSame('section', $carousel->localName);
        self::assertSame('carousel', $carousel->getAttribute('aria-roledescription'));
        self::assertSame('Specimens', $carousel->getAttribute('aria-label'));
    }

    public function testEverySlideIsAGroupNamedForItsPlaceAmongTheOthers(): void
    {
        $slides = $this->all($this->carousel(), '.c-carousel__slide');

        self::assertSame(['1 of 3', '2 of 3', '3 of 3'], array_map(static fn(Element $slide): ?string => $slide->getAttribute('aria-label'), $slides));
        foreach ($slides as $slide) {
            self::assertSame('group', $slide->getAttribute('role'));
            self::assertSame('slide', $slide->getAttribute('aria-roledescription'));
        }

        self::assertSame(['shown-1', 'shown-2', 'shown-3'], array_map(static fn(Element $slide): ?string => $slide->getAttribute('id'), $slides));
    }

    /** What the caller puts on a slide is drawn on it, one slide for each of what it was given, in order. */
    public function testEverySlideHoldsWhatTheCallerDrewForIt(): void
    {
        $texts = array_map(
            static fn(Element $slide): string => trim($slide->querySelector('.slide-text')->textContent ?? ''),
            $this->all($this->carousel(), '.c-carousel__slide'),
        );

        self::assertSame(['Head', 'Body', 'Tail'], $texts);
    }

    /** Without a stop for the keyboard, a browser with no script has no way to turn it but a pointer. */
    public function testTheStripIsAStopForTheKeyboard(): void
    {
        $track = $this->carousel()->querySelector('.c-carousel__track');

        self::assertNotNull($track);
        self::assertSame('0', $track->getAttribute('tabindex'));
        self::assertSame('shown', $track->getAttribute('id'));
        self::assertNotNull($track->getAttribute('aria-label'), 'a stop for the keyboard with no name');
    }

    public function testTheWaysBackAndOnAreButtonsNamedForWhereTheyGo(): void
    {
        $carousel = $this->carousel();

        foreach (['.c-carousel__previous' => 'Previous slide', '.c-carousel__next' => 'Next slide'] as $selector => $name) {
            $button = $carousel->querySelector($selector);
            self::assertNotNull($button, 'there is no ' . $selector);
            self::assertSame('button', $button->localName);
            self::assertSame('button', $button->getAttribute('type'));
            self::assertSame($name, $this->spoken($button));
            self::assertSame('shown', $button->getAttribute('aria-controls'));
        }
    }

    public function testThereIsAnIndicatorForEverySlideAndTheFirstIsTheOneShown(): void
    {
        $indicators = $this->all($this->carousel(), '.c-carousel__indicator');

        self::assertSame(['Slide 1', 'Slide 2', 'Slide 3'], array_map($this->spoken(...), $indicators));
        self::assertSame(['shown-1', 'shown-2', 'shown-3'], array_map(static fn(Element $button): ?string => $button->getAttribute('aria-controls'), $indicators));
        self::assertSame(['true', null, null], array_map(static fn(Element $button): ?string => $button->getAttribute('aria-current'), $indicators));
    }

    public function testTheStyleGuideShowsEveryVariant(): void
    {
        self::assertSame(['default'], new ComponentRegistry()->find(self::COMPONENT)?->variants);
    }

    private function carousel(): Element
    {
        $carousel = ComponentRendering::render('carousel.latte', self::CALL)->querySelector('.c-carousel');
        self::assertNotNull($carousel, 'c-carousel drew nothing carrying .c-carousel');

        return $carousel;
    }

    /** @return list<Element> */
    private function all(Element $root, string $selector): array
    {
        $found = [];
        foreach ($root->querySelectorAll($selector) as $element) {
            $found[] = $element;
        }

        return $found;
    }

    /** The text of $element with its runs of white space made one, which is what a screen reader reads of a button. */
    private function spoken(Element $element): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $element->textContent ?? ''));
    }
}

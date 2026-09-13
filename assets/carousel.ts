/**
 * Turning a c-carousel with the buttons under it, and marking which slide it
 * shows.
 *
 * The strip turns without any of this: it is the browser's own scrolling,
 * snapped a slide at a time, which swiping, the scroll wheel and the arrow keys
 * already move. What is here only scrolls that same strip - to the next slide,
 * the previous one, or the one an indicator names - and moves aria-current onto
 * the indicator of the slide in view, however it got there. Back from the first
 * slide is the last one, and on from the last is the first.
 *
 * How far and how fast is left to the page: the distance is measured from where
 * the slide is drawn, which is right in either direction of writing, and the
 * scroll takes the strip's own scroll-behavior, which assets/base.css makes a
 * glide only for somebody who has not asked for reduced motion.
 *
 * **Nothing is set up per carousel and nothing is cleaned up.** Both listeners
 * sit on the document and find the carousel at the moment they are asked, so a
 * carousel Naja draws into the page later works without being found first, and
 * one Naja takes away leaves nothing behind. Nothing in the page says a
 * carousel was prepared either - Naja keeps the page in its history as markup,
 * and a mark would come back with it over a carousel nothing was listening to
 * (kb-common: nette-naja/kh-0001). The one thing written into the page is
 * aria-current, which is true of the page itself.
 *
 * **It never turns on its own**, and there is no timer here to make it; see
 * src/Core/Presentation/components/carousel.latte for why.
 */

const CAROUSEL = '.c-carousel';
const TRACK = '.c-carousel__track';
const SLIDE = '.c-carousel__slide';
const INDICATOR = '.c-carousel__indicator';
const PREVIOUS = '.c-carousel__previous';
const NEXT = '.c-carousel__next';

/** The strips whose indicators are already to be marked in the next frame, so a scroll marks them once a frame. */
const marking = new WeakSet<Element>();

export function turnTheCarousels(): void {
    document.addEventListener('click', (event: MouseEvent): void => {
        const control = event.target instanceof Element ? event.target.closest(`${PREVIOUS}, ${NEXT}, ${INDICATOR}`) : null;
        const carousel = control?.closest(CAROUSEL);
        const track = carousel?.querySelector<HTMLElement>(TRACK);
        if (control === null || control === undefined || carousel === null || carousel === undefined || track === null || track === undefined) {
            return;
        }

        const slides = [...track.querySelectorAll<HTMLElement>(SLIDE)];
        const target = control.matches(INDICATOR)
            ? [...carousel.querySelectorAll(INDICATOR)].indexOf(control)
            : (shownIn(track, slides) + (control.matches(NEXT) ? 1 : -1) + slides.length) % slides.length;

        // Scrolled to where the slide is rather than by how far away it is:
        // the strip stops at every slide it passes on the way (scroll-snap-stop),
        // and a scroll given as a distance is one it would stop on one slide in.
        const slide = slides[target];
        if (slide !== undefined) {
            track.scrollTo({ left: track.scrollLeft + slide.getBoundingClientRect().left - track.getBoundingClientRect().left });
        }
    });

    // A scroll does not bubble, so it is caught on its way down instead.
    document.addEventListener(
        'scroll',
        (event: Event): void => {
            const track = event.target;
            if (!(track instanceof HTMLElement) || !track.matches(TRACK) || marking.has(track)) {
                return;
            }

            marking.add(track);
            requestAnimationFrame(() => {
                marking.delete(track);
                markTheOneShown(track);
            });
        },
        { capture: true, passive: true },
    );
}

/** The index of the slide of $track in view: the one whose start is nearest the strip's. */
function shownIn(track: HTMLElement, slides: HTMLElement[]): number {
    const edge = track.getBoundingClientRect().left;
    const distances = slides.map((slide) => Math.abs(slide.getBoundingClientRect().left - edge));

    return Math.max(0, distances.indexOf(Math.min(...distances)));
}

function markTheOneShown(track: HTMLElement): void {
    const carousel = track.closest(CAROUSEL);
    if (carousel === null) {
        return;
    }

    const shown = shownIn(track, [...track.querySelectorAll<HTMLElement>(SLIDE)]);
    carousel.querySelectorAll(INDICATOR).forEach((indicator, index) => {
        if (index === shown) {
            indicator.setAttribute('aria-current', 'true');
        } else {
            indicator.removeAttribute('aria-current');
        }
    });
}

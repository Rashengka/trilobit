/**
 * Going from one picture of a c-lightbox to another while one is open: by the
 * buttons under it, by the arrow keys, and by Home and End.
 *
 * Opening a picture and closing it need none of this - each is the browser's
 * own dialog, opened by its small picture and closed by its cross or Escape;
 * see src/Core/Presentation/components/lightbox.latte. What is here only
 * closes the dialog that is open and opens another of the same set. The other
 * is opened from its own small picture, which is given the focus first: a
 * dialog gives the focus back to whatever had it when it was opened, so
 * closing whichever picture is open gives it to the small picture of the one
 * looked at last, and that is where somebody going back to the page is.
 *
 * Back from the first picture is the last one, and on from the last is the
 * first, like c-carousel. The arrow keys follow the direction of the text:
 * in a page written right to left, the arrow pointing left goes on.
 *
 * **Nothing is set up per lightbox and nothing is cleaned up.** Both listeners
 * sit on the document and find the set at the moment they are asked, so a
 * lightbox Naja draws into the page later works without being found first,
 * and nothing in the page says one was prepared (kb-common: nette-naja/kh-0001).
 */

const LIGHTBOX = '.c-lightbox';
const DIALOG = '.c-lightbox__dialog';
const PREVIOUS = '.c-lightbox__previous';
const NEXT = '.c-lightbox__next';

export function turnTheLightboxes(): void {
    document.addEventListener('click', (event: MouseEvent): void => {
        const button = event.target instanceof Element ? event.target.closest(`${PREVIOUS}, ${NEXT}`) : null;
        const dialog = button?.closest(DIALOG);
        if (event.defaultPrevented || button === null || button === undefined || !(dialog instanceof HTMLDialogElement)) {
            return;
        }

        const pressed = button.matches(NEXT) ? NEXT : PREVIOUS;
        go(dialog, pressed === NEXT ? 1 : -1, pressed);
    });

    document.addEventListener('keydown', (event: KeyboardEvent): void => {
        const dialog = event.target instanceof Element ? event.target.closest(DIALOG) : null;
        if (event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey || !(dialog instanceof HTMLDialogElement) || !dialog.open) {
            return;
        }

        const onward = getComputedStyle(dialog).direction === 'rtl' ? 'ArrowLeft' : 'ArrowRight';
        const back = onward === 'ArrowRight' ? 'ArrowLeft' : 'ArrowRight';
        const set = setOf(dialog);
        const at = set.indexOf(dialog);

        let to: number | null = null;
        if (event.key === onward) {
            to = at + 1;
        } else if (event.key === back) {
            to = at - 1;
        } else if (event.key === 'Home') {
            to = 0;
        } else if (event.key === 'End') {
            to = set.length - 1;
        }

        if (to === null || set.length < 2) {
            return;
        }

        event.preventDefault();
        go(dialog, to - at, null);
    });
}

/** Every dialog of the lightbox $dialog is in, in the order of its pictures. */
function setOf(dialog: HTMLDialogElement): HTMLDialogElement[] {
    const lightbox = dialog.closest(LIGHTBOX);

    return lightbox === null ? [dialog] : [...lightbox.querySelectorAll<HTMLDialogElement>(DIALOG)];
}

/**
 * Closes $from and opens the picture $by places from it, round the set. The
 * focus goes to the button of the same kind in the other picture when one was
 * pressed, so that pressing it again goes on again; otherwise the dialog puts
 * it where it always does.
 */
function go(from: HTMLDialogElement, by: number, pressed: string | null): void {
    const set = setOf(from);
    const to = set[(((set.indexOf(from) + by) % set.length) + set.length) % set.length];
    if (to === undefined || to === from) {
        return;
    }

    from.close();
    document.querySelector<HTMLElement>(`[commandfor="${CSS.escape(to.id)}"][command="show-modal"]`)?.focus({ preventScroll: true });
    to.showModal();

    if (pressed !== null) {
        to.querySelector<HTMLElement>(pressed)?.focus();
    }
}

/**
 * Putting away a dismissible c-notice: its c-close hides it. A toast of
 * c-toast is put away the same way, by the c-close beside its sentence, and
 * everything below is as true of it: "notice" is either.
 *
 * **Nothing is set up per notice and nothing is cleaned up.** The click is
 * listened for on the document, so a notice Naja draws into the page later
 * works without being found first, and a notice Naja takes away leaves nothing
 * behind. There is no mark in the page saying a notice was prepared, either -
 * Naja keeps the page in its history as markup, and a mark would come back
 * with it over a notice nothing was listening to (kb-common:
 * nette-naja/kh-0001). What is kept is the one thing that is true of the page
 * itself: the hidden attribute on a notice that was put away.
 *
 * **Where the focus goes.** The button that had it is gone with its notice, and
 * a browser left alone puts the focus on the document itself, which sends
 * somebody on a keyboard or a screen reader back to the top of the page. It goes
 * instead to what comes next - the first thing after the notice that takes the
 * focus - which is where Tab would have taken them from the notice anyway, so
 * they carry on reading where they were. When nothing comes after it, it goes
 * to the last thing before it. The focus is only moved when it was in the
 * notice: a click that left it somewhere else leaves it there.
 */

const CLOSE = '.c-notice--dismissible > .c-close, .c-toast__item > .c-close';

/** What takes the focus by the keyboard, before whether it is drawn and enabled is asked. */
const FOCUSABLE = [
    'a[href]',
    'button',
    'input:not([type="hidden"])',
    'select',
    'textarea',
    'summary',
    '[tabindex]',
].join(', ');

export function dismissTheNotices(): void {
    document.addEventListener('click', (event: MouseEvent): void => {
        const button = event.target instanceof Element ? event.target.closest(CLOSE) : null;
        const notice = button?.parentElement;
        if (!(notice instanceof HTMLElement)) {
            return;
        }

        const hadTheFocus = notice.contains(document.activeElement);
        notice.hidden = true;

        if (hadTheFocus) {
            nextTo(notice)?.focus();
        }
    });
}

/** The first thing after $notice that takes the focus, or failing that the last one before it. */
function nextTo(notice: HTMLElement): HTMLElement | undefined {
    const candidates = [...document.querySelectorAll<HTMLElement>(FOCUSABLE)].filter(
        (candidate) =>
            !notice.contains(candidate) &&
            candidate.tabIndex >= 0 &&
            !candidate.matches(':disabled') &&
            candidate.checkVisibility(),
    );

    const follows = (candidate: HTMLElement): boolean =>
        (notice.compareDocumentPosition(candidate) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0;

    return candidates.find(follows) ?? candidates.filter((candidate) => !follows(candidate)).at(-1);
}

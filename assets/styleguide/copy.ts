/**
 * The button beside a block of code that copies it, as Bootstrap's
 * documentation has - see sgCode in
 * src/Core/Presentation/Styleguide/templates/furniture.latte.
 *
 * The button is drawn hidden and shown here only where the browser will let a
 * page write to the clipboard, so that nobody is offered a button that does
 * nothing. It is a real button, so the keyboard reaches it and presses it
 * without anything written here; what it did is put into the status beside
 * it, which is announced where the focus is, without moving it.
 */

const BUTTON = '[data-sg-copy]';
const FRAME = '[data-sg-code]';
const STATUS = '[data-sg-copy-status]';

const COPIED = 'Copied to the clipboard.';
const REFUSED = 'Could not copy. Select the code and copy it by hand.';

/** Long enough to be read, short enough not to be there the next time somebody looks. */
const SAID_FOR = 4000;

const silences = new WeakMap<Element, number>();

function canCopy(): boolean {
    return typeof navigator.clipboard?.writeText === 'function';
}

/** Shows every button at or under `root` that has something to do. */
export function offerCopyingWithin(root: Element | Document): void {
    if (!canCopy()) {
        return;
    }

    const buttons = [...root.querySelectorAll<HTMLElement>(BUTTON)];
    if (root instanceof HTMLElement && root.matches(BUTTON)) {
        buttons.push(root);
    }

    for (const button of buttons) {
        button.hidden = false;
    }
}

/**
 * One listener for every button, on the document, so that a button arriving
 * with a redrawn part of the page is answered like one that was there from
 * the start.
 */
export function copyWhenAsked(): void {
    document.addEventListener('click', (event: MouseEvent): void => {
        const source = event.target;
        const button = source instanceof Element ? source.closest(BUTTON) : null;
        const frame = button?.closest(FRAME);
        const code = frame?.querySelector('code');
        const status = frame?.querySelector(STATUS);
        if (code === null || code === undefined || status === null || status === undefined) {
            return;
        }

        navigator.clipboard.writeText(code.textContent ?? '').then(
            () => say(status, COPIED),
            () => say(status, REFUSED),
        );
    });
}

/**
 * Puts a sentence into a status and takes it out again. Emptied first, so
 * that the same sentence said twice in a row is announced twice rather than
 * taken for no change.
 */
function say(status: Element, sentence: string): void {
    window.clearTimeout(silences.get(status));
    status.textContent = '';

    window.requestAnimationFrame((): void => {
        status.textContent = sentence;
        silences.set(status, window.setTimeout((): void => {
            status.textContent = '';
        }, SAID_FOR));
    });
}

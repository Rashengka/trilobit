import type { Extension } from 'naja';

/**
 * What c-modal and c-offcanvas need beyond the browser's own dialog, which is
 * two things. Everything else - the focus taken in and given back, the page
 * behind made inert, Escape - is the browser's; see
 * src/Core/Presentation/components/modal.latte.
 *
 * **The buttons, where the browser does not answer them.** A dialog is opened
 * and closed by a button naming it (commandfor) and saying what to do
 * (command: show-modal, close). That is Invoker Commands, and a browser that
 * has them acts on it with no script at all. Where it does not, the listener
 * below does the same for the same two attributes. It listens on the
 * document, so nothing is set up per dialog and nothing is left behind: a
 * dialog Naja draws into the page later is answered like any other, and there
 * is no mark in the page saying a dialog was prepared - Naja keeps snippets in
 * its history as markup, and a mark would come back with it over a dialog
 * nothing prepared (kb-common: nette-naja/kh-0001). In a browser that has
 * Invoker Commands nothing is listened for, so the two can never both act on
 * one click.
 *
 * Before it opens a dialog the listener puts the focus on the button. A
 * dialog gives the focus back to whatever had it when it was opened, and not
 * every browser puts the focus on a button that is clicked.
 *
 * **A dialog Naja redraws while it is open.** Naja replaces a snippet with the
 * server's markup, and a dialog in it comes back closed - the server never
 * draws one open, because a dialog drawn open is not a modal - while the one
 * that was open leaves the page with the focus in it. The decision is that
 * what somebody had open stays open. The page behind a modal is inert, so
 * whatever asked for the redraw was inside the dialog, and closing it under
 * them would take away what they were in the middle of - typically a form the
 * server has just refused. So the dialog that comes back is shown as a modal
 * again, from its button, so that closing it still gives the focus back there;
 * and the focus goes back to the element it was on, found by its id.
 *
 * The way back is different. history.back() brings back what Naja kept, which
 * is the server's markup and so a closed dialog. The page returns to how it
 * was, the dialog with it, and the focus is given to the dialog's button
 * rather than to the top of the page.
 *
 * tests/e2e/layers.spec.ts measures both, the first in a browser made to have
 * no Invoker Commands.
 */

/** What opens and closes a dialog. */
const COMMANDED = 'button[commandfor][command]';

/** What takes the focus by the keyboard, for a dialog whose focused element did not come back. */
const FOCUSABLE = [
    'a[href]',
    'button:not(:disabled)',
    'input:not([type="hidden"]):not(:disabled)',
    'select:not(:disabled)',
    'textarea:not(:disabled)',
    '[tabindex]:not([tabindex="-1"])',
].join(', ');

export function answerTheDialogButtons(): void {
    if ('commandForElement' in HTMLButtonElement.prototype) {
        return;
    }

    document.addEventListener('click', (event: MouseEvent): void => {
        const button = event.target instanceof Element ? event.target.closest(COMMANDED) : null;
        if (event.defaultPrevented || !(button instanceof HTMLButtonElement) || button.disabled) {
            return;
        }

        const dialog = document.getElementById(button.getAttribute('commandfor') ?? '');
        if (!(dialog instanceof HTMLDialogElement)) {
            return;
        }

        const command = button.getAttribute('command');
        if (command === 'show-modal' && !dialog.open) {
            button.focus({ preventScroll: true });
            dialog.showModal();
        } else if (command === 'close' && dialog.open) {
            // The value of the button is the dialog's answer, as it is where the browser answers.
            dialog.close(button.hasAttribute('value') ? button.value : undefined);
        }
    });
}

/** A dialog that was open as a modal when Naja began to redraw the snippet it is in. */
interface OpenDialog {
    readonly id: string;
    /** The id of what had the focus in it, when that had one. */
    readonly focused: string | null;
}

/** Kept from Naja's beforeUpdate to its afterUpdate of the same snippet, and for no longer. */
const openIn = new WeakMap<Element, readonly OpenDialog[]>();

/**
 * Registered before naja.initialize(), like the comboboxes' extension, and
 * doing nothing until a snippet with an open dialog in it is redrawn.
 */
export const dialogsInSnippets: Extension = {
    initialize(naja): void {
        naja.snippetHandler.addEventListener('beforeUpdate', (event) => {
            const { snippet } = event.detail;
            const focused = document.activeElement;
            const open = [snippet, ...snippet.querySelectorAll('dialog')]
                .filter(
                    (element): element is HTMLDialogElement =>
                        element instanceof HTMLDialogElement && element.id !== '' && element.matches(':modal'),
                )
                .map((dialog) => ({
                    id: dialog.id,
                    focused: focused !== null && focused.id !== '' && dialog.contains(focused) ? focused.id : null,
                }));

            if (open.length > 0) {
                openIn.set(snippet, open);
            } else {
                openIn.delete(snippet);
            }
        });

        naja.snippetHandler.addEventListener('afterUpdate', (event) => {
            const { snippet, fromCache } = event.detail;
            const open = openIn.get(snippet) ?? [];
            openIn.delete(snippet);

            for (const { id, focused } of open) {
                const dialog = document.getElementById(id);
                if (fromCache) {
                    closeOnTheWayBack(dialog, id);
                } else if (dialog instanceof HTMLDialogElement) {
                    keepOpen(dialog, focused);
                }
            }
        });
    },
};

/** The button that opens the dialog with $id, which is what it gives the focus back to. */
function openerOf(id: string): HTMLElement | null {
    return document.querySelector<HTMLElement>(`[commandfor="${CSS.escape(id)}"][command="show-modal"]`);
}

function keepOpen(dialog: HTMLDialogElement, focused: string | null): void {
    if (!dialog.open) {
        // The focus goes to the button first, so that the dialog takes the
        // button as what to give the focus back to when it closes.
        openerOf(dialog.id)?.focus({ preventScroll: true });
        dialog.showModal();
    }

    const again = focused === null ? null : document.getElementById(focused);
    if (again instanceof HTMLElement && dialog.contains(again)) {
        again.focus();
    } else if (!dialog.contains(document.activeElement)) {
        (dialog.querySelector<HTMLElement>('[autofocus]') ?? dialog.querySelector<HTMLElement>(FOCUSABLE))?.focus();
    }
}

function closeOnTheWayBack(dialog: HTMLElement | null, id: string): void {
    if (dialog instanceof HTMLDialogElement && dialog.open) {
        // A snippet that is the dialog itself stays in the page, open; closing
        // it gives the focus back the way closing it by hand does.
        dialog.close();

        return;
    }

    openerOf(id)?.focus({ preventScroll: true });
}

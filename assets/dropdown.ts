/**
 * The keys of c-dropdown's menu: the keyboard of the menu button of the WAI-ARIA
 * Authoring Practices, over a menu the browser opens and closes.
 *
 * What the browser does is not repeated here. The menu is a native popover, so
 * the button opens and closes it, a click outside or Escape closes it, and when
 * it closes with the focus inside, the focus goes back to the button. What it
 * does not do is move the focus into a menu, or around one - that is this file:
 *
 *   - opened, the focus goes to the first entry; opened by ArrowUp on the
 *     button, to the last, and by ArrowDown to the first;
 *   - ArrowDown and ArrowUp move to the next and the previous entry, round from
 *     either end to the other; Home and End go to the first and the last;
 *   - a letter goes to the next entry starting with it;
 *   - an entry chosen closes the menu, which gives the focus back to the button;
 *   - Tab closes it and goes on past the dropdown, Shift+Tab closes it onto the
 *     button - the entries are not in the order of Tab, so the menu is left as
 *     one step.
 *
 * Everything is heard on the document and nothing is set up per menu or marked
 * as set up, so a dropdown Naja draws later works without being found first,
 * and one Naja takes away leaves nothing behind (kb-common: nette-naja/kh-0001).
 * The one thing kept in here rather than on the page is which end the next menu
 * to open is to land on, for the moment between the key and the opening.
 */

const BUTTON = '.c-dropdown > [popovertarget]';
const MENU = '.c-dropdown__menu';
const ENTRY = '[role="menuitem"]';

let landOn: 'first' | 'last' = 'first';

export function keepTheDropdownsToTheKeyboard(): void {
    document.addEventListener('keydown', (event: KeyboardEvent): void => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }

        if (target.matches(BUTTON)) {
            onTheButton(event, target);

            return;
        }

        const menu = target.closest(MENU);
        if (menu instanceof HTMLElement) {
            inTheMenu(event, menu, target);
        }
    });

    document.addEventListener(
        'toggle',
        (event: Event): void => {
            const menu = event.target;
            if (!(menu instanceof HTMLElement) || !menu.matches(MENU) || (event as ToggleEvent).newState !== 'open') {
                return;
            }

            const entries = entriesOf(menu);
            (landOn === 'last' ? entries.at(-1) : entries[0])?.focus();
            landOn = 'first';
        },
        // toggle does not bubble; it is heard on its way down instead.
        { capture: true },
    );

    document.addEventListener('click', (event: MouseEvent): void => {
        const entry = event.target instanceof Element ? event.target.closest(ENTRY) : null;
        const menu = entry?.closest(MENU);
        if (menu instanceof HTMLElement && menu.matches(':popover-open')) {
            menu.hidePopover();
        }
    });
}

function onTheButton(event: KeyboardEvent, button: HTMLElement): void {
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
        return;
    }

    const menu = button.parentElement?.querySelector(MENU);
    if (!(menu instanceof HTMLElement)) {
        return;
    }

    event.preventDefault();
    const end = event.key === 'ArrowUp' ? 'last' : 'first';

    if (menu.matches(':popover-open')) {
        const entries = entriesOf(menu);
        (end === 'last' ? entries.at(-1) : entries[0])?.focus();

        return;
    }

    // Opened by the button, the way a press opens it, so that the browser
    // knows which button it belongs to and gives the focus back to it.
    landOn = end;
    button.click();
}

function inTheMenu(event: KeyboardEvent, menu: HTMLElement, target: HTMLElement): void {
    const entries = entriesOf(menu);
    const at = entries.indexOf(target);
    const go = (index: number): void => {
        event.preventDefault();
        entries[(index + entries.length) % entries.length]?.focus();
    };

    switch (event.key) {
        case 'ArrowDown':
            go(at + 1);
            break;
        case 'ArrowUp':
            go(at < 0 ? -1 : at - 1);
            break;
        case 'Home':
            go(0);
            break;
        case 'End':
            go(-1);
            break;
        case 'Tab':
            // Tab goes on from the button once the menu is closed, which is
            // past the menu; Shift+Tab has already arrived.
            if (event.shiftKey) {
                event.preventDefault();
            }
            menu.hidePopover();
            break;
        default:
            if (event.key.length === 1 && event.key.trim() !== '' && !event.ctrlKey && !event.metaKey && !event.altKey) {
                const letter = event.key.toLocaleLowerCase();
                const after = [...entries.slice(at + 1), ...entries.slice(0, at + 1)];
                const found = after.find((entry) => (entry.textContent ?? '').trim().toLocaleLowerCase().startsWith(letter));
                if (found !== undefined) {
                    event.preventDefault();
                    found.focus();
                }
            }
    }
}

function entriesOf(menu: HTMLElement): HTMLElement[] {
    return [...menu.querySelectorAll<HTMLElement>(ENTRY)];
}

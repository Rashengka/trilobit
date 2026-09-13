/**
 * Asking the server for the last part of an address, made of a title.
 *
 * **Nothing here knows what an address may be.** Which letters are allowed,
 * which beginnings are reserved, how long an address may grow and whether one
 * is already taken are all answered by the register on the server - see
 * Trilobit\Core\Content\PathRegistry::suggest() - by the same check saving
 * makes. A second copy of those rules in the browser would be the one that
 * drifted, and it would drift quietly: the suggestion would look right and be
 * refused on save. So this file carries the question and the answer and
 * nothing else.
 *
 * A control opts in by carrying `data-suggest-segment` with the address to
 * ask, and names the controls of its own form it reads and fills: the title
 * (`data-suggest-from`), what it is filed under (`data-suggest-under`) and the
 * last part (`data-suggest-into`). Pressing it asks; filling in the title
 * while the last part is still empty asks too, so that somebody who writes
 * the title first is not made to press anything.
 *
 * Whatever the server says about the answer - that the title has nothing in
 * it to make an address of, say - is written into an element of the same form
 * carrying `data-suggest-status`, so that a press is never met with nothing.
 */
const SUGGEST = 'data-suggest-segment';

interface Suggestion {
    readonly segment: string;
    readonly message: string;
}

type Control = HTMLInputElement | HTMLSelectElement;

function controlOf(form: HTMLFormElement, name: string | null): Control | null {
    if (name === null) {
        return null;
    }

    const control = form.elements.namedItem(name);

    return control instanceof HTMLInputElement || control instanceof HTMLSelectElement ? control : null;
}

function isSuggestion(value: unknown): value is Suggestion {
    return typeof value === 'object'
        && value !== null
        && typeof (value as Record<string, unknown>).segment === 'string'
        && typeof (value as Record<string, unknown>).message === 'string';
}

async function suggest(trigger: Element, onlyWhenEmpty: boolean): Promise<void> {
    const form = trigger.closest('form');
    if (form === null) {
        return;
    }

    const from = controlOf(form, trigger.getAttribute('data-suggest-from'));
    const under = controlOf(form, trigger.getAttribute('data-suggest-under'));
    const into = controlOf(form, trigger.getAttribute('data-suggest-into'));
    const status = form.querySelector('[data-suggest-status]');
    if (from === null || into === null || (onlyWhenEmpty && into.value !== '')) {
        return;
    }

    // What the last part held when the question was asked. The answer arrives
    // later, and somebody who went on typing into the field in the meantime
    // has written their own - which an answer to the earlier question must
    // not overwrite. Leaving the title and typing the last part straight away
    // is exactly that order, and nothing would say the typed part was lost.
    const asked = into.value;
    const address = new URL(trigger.getAttribute(SUGGEST) ?? '', window.location.href);
    address.searchParams.set('title', from.value);
    address.searchParams.set('category', under?.value ?? '');

    let answer: unknown = null;
    try {
        const response = await fetch(address, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        answer = response.ok ? await response.json() : null;
    } catch {
        answer = null;
    }

    if (into.value !== asked) {
        return;
    }

    if (!isSuggestion(answer)) {
        if (status !== null) {
            status.textContent = 'No address could be suggested just now; write the last part by hand.';
        }

        return;
    }

    if (answer.segment !== '') {
        into.value = answer.segment;
    }

    if (status !== null) {
        status.textContent = answer.message;
    }
}

document.addEventListener('click', (event: MouseEvent): void => {
    const trigger = event.target instanceof Element ? event.target.closest(`[${SUGGEST}]`) : null;
    if (trigger === null) {
        return;
    }

    event.preventDefault();
    void suggest(trigger, false);
});

document.addEventListener('change', (event: Event): void => {
    const control = event.target;
    if (!(control instanceof HTMLInputElement) || control.form === null) {
        return;
    }

    for (const trigger of control.form.querySelectorAll(`[${SUGGEST}]`)) {
        if (trigger.getAttribute('data-suggest-from') === control.name) {
            void suggest(trigger, true);
        }
    }
});

/**
 * c-combobox: a <select> of one answer, drawn by Tom Select as a control that
 * can be searched - opened by a click, by typing or by the down arrow, and never
 * by tabbing into it, the way a native select is not.
 *
 * **A select opts in by carrying `data-combobox`.** It stays in the page,
 * hidden, and it is still what the form sends: the library writes every choice
 * back into it. Its label names the control, and whatever its aria-describedby
 * names - the hint and the reason c-field draws under it - describes the
 * control too, because that is carried over here; the library would drop it,
 * and the sentence under a field would then be read to nobody.
 *
 * **A line to search it by is offered from SEARCH_FROM choices up.** Over a
 * handful of choices a line to type into is slower than reading the list, and
 * the lists this is used for grow with the data - the categories of a site
 * that has three today and forty next year - so a template cannot know which
 * it is drawing. `data-combobox-search="on"` or `"off"` says it outright where
 * the author does know. Without the line the control itself takes the focus
 * and answers the keys a select does, the space bar included.
 *
 * **What the library leaves out is added here** (.ai/plans/14-vyberovy-prvek.md,
 * decision 4): Home, End, Page Up and Page Down in the open list, a live region
 * saying how many choices match what was typed, and the description above.
 *
 * **Naja.** An instance hangs on one element, so a snippet Naja redraws would
 * bring a select nobody drew - a native select where a combobox was, with no
 * error anywhere. The extension below releases every combobox in a snippet
 * before it is replaced and draws every one in it afterwards, whether the
 * snippet came from the server or from Naja's history cache. Drawing twice is
 * harmless: the guard is the library's own handle on the element
 * (`select.tomselect`), never a class or an attribute in the DOM - those would
 * be copied into the history cache with the markup and claim a combobox that
 * is no longer there.
 *
 * **Which is also why the first drawing waits for naja.initialize()** (see
 * assets/app.ts). Naja reads every snippet as it initialises and keeps that
 * markup for the way back; a combobox drawn before it would be kept too, and
 * history.back() would bring back a control with nothing behind it.
 *
 * **A select of several answers is left as it is**, and says so in the
 * console. Nothing uses one yet. **Exit condition:** the first select of
 * several answers that needs searching.
 *
 * **Dependent selects** - the answer in one choosing what another offers - are
 * not here yet; the plan is to feed the second through Naja, so that the
 * request shows in Tracy like any other. The instance is kept on the element
 * for that: its options can be replaced while it lives, and loadingClass is
 * already named so that loading and "nothing to offer" can look different.
 * **Exit condition:** the first dependent pair of selects in a form.
 */
import type { Extension } from 'naja';
import TomSelect from 'tom-select/base';

const MARK = 'data-combobox';

const SEARCH = 'data-combobox-search';

/** From how many choices up a list is offered a line to search it by, when the select does not say. */
export const SEARCH_FROM = 10;

const MOVES = ['Home', 'End', 'PageUp', 'PageDown'] as const;

type Move = (typeof MOVES)[number];

/** The element the library draws in front of, with the handle it keeps on it. */
type Drawn = HTMLSelectElement & { tomselect?: TomSelect };

function selectsIn(root: ParentNode): Drawn[] {
    const found: Drawn[] = [...root.querySelectorAll<HTMLSelectElement>(`select[${MARK}]`)];
    if (root instanceof HTMLSelectElement && root.hasAttribute(MARK)) {
        found.unshift(root);
    }

    return found;
}

/** Draws every select under $root that asks for it and is not drawn yet. */
export function enhanceWithin(root: ParentNode): void {
    for (const select of selectsIn(root)) {
        enhance(select);
    }
}

/** Takes every combobox under $root down again, leaving the select as it was written. */
export function releaseWithin(root: ParentNode): void {
    for (const select of selectsIn(root)) {
        select.tomselect?.destroy();
    }
}

/**
 * Registered before naja.initialize() and doing nothing until a snippet is
 * redrawn: the first drawing of the page is assets/app.ts's, after it.
 */
export const comboboxesInSnippets: Extension = {
    initialize(naja): void {
        naja.snippetHandler.addEventListener('beforeUpdate', (event) => releaseWithin(event.detail.snippet));
        naja.snippetHandler.addEventListener('afterUpdate', (event) => enhanceWithin(event.detail.snippet));
    },
};

function offersSearch(select: HTMLSelectElement): boolean {
    const said = select.getAttribute(SEARCH);
    if (said === 'on') {
        return true;
    }

    if (said === 'off') {
        return false;
    }

    if (said !== null) {
        console.error(`${SEARCH}="${said}" on ${nameOf(select)} is neither "on" nor "off"; the number of choices decides.`);
    }

    return [...select.options].filter((option) => option.value !== '').length >= SEARCH_FROM;
}

function nameOf(select: HTMLSelectElement): string {
    return select.name || select.id || 'a select';
}

function enhance(select: Drawn): void {
    if (select.tomselect !== undefined) {
        return;
    }

    if (select.multiple) {
        console.error(`${MARK} draws a select of one answer; ${nameOf(select)} takes several and is left as it is.`);

        return;
    }

    const searchable = offersSearch(select);
    const combobox = new TomSelect(select, {
        // The empty choice a form offers ("none") is a choice like the others,
        // as it is in a native select, and not a placeholder that cannot be
        // chosen back once something else was.
        allowEmptyOption: true,
        // The default is fifty, and the rest of the list is then silently not
        // there to be found.
        maxOptions: null,
        openOnFocus: false,
        wrapperClass: 'c-combobox',
        controlClass: 'c-combobox__control',
        dropdownClass: 'c-combobox__dropdown',
        dropdownContentClass: 'c-combobox__list',
        itemClass: 'c-combobox__item',
        optionClass: 'c-combobox__option',
        loadingClass: 'c-combobox--loading',
        controlInput: searchable
            ? '<input type="text" class="c-combobox__input" autocomplete="off" size="1">'
            : null,
        render: {
            no_results: () => '<div class="c-combobox__message">Nothing matches what is typed.</div>',
            loading: () => '<div class="c-combobox__message">Loading the choices…</div>',
            optgroup_header: (data: { label?: unknown }, escape: (text: string) => string) =>
                `<div class="c-combobox__heading">${escape(String(data.label ?? ''))}</div>`,
        },
    });

    const focus = combobox.focus_node;
    for (const attribute of ['aria-describedby', 'aria-invalid']) {
        const value = select.getAttribute(attribute);
        if (value !== null) {
            focus.setAttribute(attribute, value);
        }
    }

    // With a line to search by, the element with the focus is that line, and
    // it is empty until somebody types: a screen reader would say the label
    // and no answer, where a native select says the answer chosen. So the
    // answer is named with the label, and named again whenever it changes.
    // Without the line the control holds the answer and says it as its
    // value; naming it too would read it out twice.
    if (searchable) {
        const label = focus.getAttribute('aria-labelledby');
        const nameAfterAnswer = (): void => {
            const item = combobox.control.querySelector<HTMLElement>('.c-combobox__item');
            if (item !== null) {
                item.id = `${select.id}-ts-answer`;
            }

            const names = [label, item?.id].filter((id): id is string => typeof id === 'string' && id !== '');
            focus.setAttribute('aria-labelledby', names.join(' '));
        };

        nameAfterAnswer();
        combobox.on('change', nameAfterAnswer);
    }

    // Hidden by a clip rather than taken out of the page, so that the form
    // still sends it - and a clipped element is still in the accessibility
    // tree, as a second list with no name once its label points at the control
    // instead. The control stands in for it there too.
    const hidden = select.getAttribute('aria-hidden');
    select.setAttribute('aria-hidden', 'true');

    const status = document.createElement('span');
    status.setAttribute('role', 'status');
    status.className = 'u-visually-hidden';
    combobox.wrapper.after(status);

    combobox.on('type', (query: string) => {
        combobox.wrapper.classList.toggle('c-combobox--searching', query !== '');
        status.textContent = query === '' ? '' : matching(combobox.currentResults?.items.length ?? 0);
    });
    combobox.on('dropdown_close', () => {
        combobox.wrapper.classList.remove('c-combobox--searching');
        status.textContent = '';
    });

    focus.addEventListener('keydown', (event) => answerKey(combobox, event, searchable));

    // The library opens on a click only by opening on focus, so turning that
    // off took the click with it; and a click on an open list blurs the whole
    // control. A native select opens on a click and closes on the next, and
    // keeps the focus either way.
    //
    // The focus is moved here and now, rather than by the library's focus(),
    // which does it a moment later: until the library has seen the focus it
    // swallows every key, and what somebody typed straight after the click
    // was lost without a trace.
    combobox.hook('instead', 'onClick', () => {
        if (combobox.isDisabled) {
            return;
        }

        if (combobox.isOpen) {
            combobox.close();

            return;
        }

        focus.focus();
        combobox.refreshOptions(true);
    });

    combobox.on('destroy', () => {
        status.remove();
        if (hidden === null) {
            select.removeAttribute('aria-hidden');
        } else {
            select.setAttribute('aria-hidden', hidden);
        }

        // The library points the label at the control it drew and does not
        // point it back. Left like that, the select drawn again after a redraw
        // would find no label of its own, and the control would have no name.
        for (const label of document.querySelectorAll<HTMLLabelElement>(`label[for="${CSS.escape(focus.id)}"]`)) {
            label.htmlFor = select.id;
        }
    });
}

function matching(count: number): string {
    if (count === 0) {
        return 'No choice matches.';
    }

    return count === 1 ? '1 choice matches.' : `${count} choices match.`;
}

function isMove(key: string): key is Move {
    return (MOVES as readonly string[]).includes(key);
}

/**
 * The keys of a list the library does not answer.
 *
 * With a line to type into and the list closed, Home and End belong to the
 * line - they move the caret - and nothing here takes them. Without the line
 * they open the list, the way a native select's do, and so does the space bar,
 * which chooses once the list is open.
 */
function answerKey(combobox: TomSelect, event: KeyboardEvent, searchable: boolean): void {
    if (event.altKey || event.ctrlKey || event.metaKey || combobox.isLocked) {
        return;
    }

    if (!searchable && event.key === ' ') {
        event.preventDefault();
        if (combobox.isOpen && combobox.activeOption !== null) {
            combobox.onOptionSelect(event, combobox.activeOption);
        } else {
            combobox.open();
        }

        return;
    }

    if (!isMove(event.key) || (!combobox.isOpen && searchable)) {
        return;
    }

    event.preventDefault();
    if (!combobox.isOpen) {
        combobox.open();
    }

    const options = [...combobox.selectable()].filter((node): node is HTMLElement => node instanceof HTMLElement);
    const last = options.length - 1;
    if (last < 0) {
        return;
    }

    const at = combobox.activeOption === null ? -1 : options.indexOf(combobox.activeOption);
    const page = Math.max(1, Math.floor(combobox.dropdown_content.clientHeight / Math.max(1, options[0].offsetHeight)));
    const target = {
        Home: 0,
        End: last,
        PageUp: Math.max(0, at - page),
        PageDown: Math.min(last, Math.max(0, at) + page),
    }[event.key];

    combobox.setActiveOption(options[target]);
}

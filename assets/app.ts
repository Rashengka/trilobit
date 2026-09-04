/**
 * The shared bundle, loaded on every page regardless of module - see
 * src/Core/Presentation/Front/templates/@layout.latte's `scripts` block.
 */
import naja from 'naja';

import './app.css';

/**
 * The whole of switching a theme.
 *
 * Every value on the page is read out of a custom property, and the properties
 * are re-declared per theme under [data-theme] - so moving one attribute on
 * <html> repaints the page and moves the navigation with it, without a request
 * and without a rebuild. See assets/themes/ledger.css.
 *
 * The choice is deliberately not remembered. What a build starts in is
 * configuration (trilobit.theme), and a stored preference silently disagreeing
 * with it is how somebody comes to report a bug about a theme nobody set.
 *
 * @param trigger the attribute a control carries, e.g. data-theme-choice
 * @param target the attribute on <html> it writes, e.g. data-theme
 * @param clearedBy the value that means "leave it to the browser again"
 */
function switchable(trigger: string, target: string, clearedBy?: string): void {
    document.addEventListener('click', (event: MouseEvent): void => {
        const source = event.target;
        if (!(source instanceof Element)) {
            return;
        }

        const control = source.closest(`[${trigger}]`);
        const value = control?.getAttribute(trigger);
        if (value === null || value === undefined) {
            return;
        }

        const root = document.documentElement;
        if (value === clearedBy) {
            root.removeAttribute(target);
        } else {
            root.setAttribute(target, value);
        }

        for (const other of document.querySelectorAll(`[${trigger}]`)) {
            other.setAttribute('aria-pressed', String(other === control));
        }
    });
}

switchable('data-theme-choice', 'data-theme');
switchable('data-theme-mode-choice', 'data-theme-mode', 'system');

naja.initialize({ history: true });

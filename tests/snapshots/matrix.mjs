// What the snapshot matrix is made of, and every decision about it that does
// not need a browser: which combinations there are, what a picture is called,
// what counts as a page that could not be pictured, and the page that shows
// the pictures side by side.
//
// Plain JavaScript rather than TypeScript, so that Node's own test runner can
// import it as it is (tests/frontend/snapshots.test.mjs) and bin/snapshots.mjs
// can import it without a build; tests/snapshots/matrix.spec.ts imports it too.

/**
 * The themes a picture is taken in. Written down here rather than read from the
 * build, because the point of the matrix is to show these two; a theme the
 * build no longer has is not silently skipped - the server drops the cookie,
 * the page comes back in another theme, and the spec refuses the picture.
 */
export const THEMES = ['atrium', 'ledger'];

/** The modes, the two a person can choose explicitly. */
export const MODES = ['light', 'dark'];

/** A phone and a desktop window, in CSS pixels. */
export const WIDTHS = [390, 1440];

/** The window height each width is opened at; a picture is of the whole page regardless. */
export const HEIGHTS = { 390: 844, 1440: 900 };

/** The browsers there are, as bin/e2e-docker.mjs and playwright.config.ts name them. */
export const BROWSERS = ['chromium', 'firefox'];

/** The environment variable that carries a request from bin/snapshots.mjs to the spec. */
export const REQUEST_VARIABLE = 'TRILOBIT_SNAPSHOT_REQUEST';

/** Every theme × mode × width, in the order the index page lays them out. */
export function combinations() {
    const all = [];
    for (const theme of THEMES) {
        for (const mode of MODES) {
            for (const width of WIDTHS) {
                all.push({ theme, mode, width });
            }
        }
    }

    return all;
}

/** The browsers a --browser value selects; anything else is refused. */
export function browsersFor(value) {
    if (value === 'all') {
        return [...BROWSERS];
    }
    if (!BROWSERS.includes(value)) {
        throw new Error(`--browser takes ${[...BROWSERS, 'all'].join(', ')}; "${value}" is none of them.`);
    }

    return [value];
}

/**
 * A path as one readable word of a file name: `/admin/shop/products` becomes
 * `admin-shop-products`, `/` becomes `home`. Anything that is not a letter or a
 * digit becomes a hyphen, so a query string survives as words instead of
 * characters a file system or a URL would read differently.
 */
export function slugOf(path) {
    const slug = path
        .replace(/^\/+/, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

    return slug === '' ? 'home' : slug;
}

/** `<path>__<theme>-<mode>-<width>-<browser>.png`. */
export function fileName(path, { theme, mode, width }, browser) {
    return `${slugOf(path)}__${theme}-${mode}-${width}-${browser}.png`;
}

/**
 * The paths asked for, checked before anything is started: each has to be a
 * path of this site, and no two may end up as the same file name - the second
 * picture would overwrite the first and the index would show one page twice.
 */
export function checkPaths(paths) {
    if (paths.length === 0) {
        throw new Error('no path was given; name at least one, such as /_styleguide/foundations/colours.');
    }

    const seen = new Map();
    for (const path of paths) {
        // A browser reads a backslash as a slash, so "/\\example.com" is "//example.com":
        // another host, not a path of this site.
        if (!path.startsWith('/') || path.startsWith('//') || path.startsWith('/\\')) {
            throw new Error(`"${path}" is not a path of this site; it has to start with a single "/".`);
        }
        if (path.includes('\\')) {
            throw new Error(`"${path}" holds a backslash, which a browser reads as a slash; write the path with "/".`);
        }
        const slug = slugOf(path);
        if (seen.has(slug)) {
            throw new Error(`"${seen.get(slug)}" and "${path}" would both be saved as ${slug}__*.png.`);
        }
        seen.set(slug, path);
    }

    return paths;
}

/**
 * Why a page cannot be pictured, or null when it can.
 *
 * $answer is what the browser ended up with: the status of the last response,
 * whether it was redirected on the way, and the origin and path it arrived at.
 * Every redirect is refused, the one to the sign-in page by name: a picture of
 * the sign-in page taken in place of the page asked for would look like a page
 * that rendered, and that is exactly the picture a critic must not be shown.
 * So is arriving anywhere but $origin, the server the run started.
 */
export function refusal(requested, answer, origin) {
    if (answer.status === null) {
        return `${requested} gave no response at all.`;
    }
    if (answer.origin !== origin) {
        return `${requested} ended up on ${answer.origin}, not on ${origin}.`;
    }
    if (/\/sign-in$/.test(answer.arrivedAt)) {
        return `${requested} redirected to the sign-in page (${answer.arrivedAt}); pass --sign-in to picture the administration.`;
    }
    if (answer.redirected) {
        return `${requested} redirected to ${answer.arrivedAt}; ask for that address instead.`;
    }
    if (answer.status !== 200) {
        return `${requested} answered ${answer.status}.`;
    }

    return null;
}

/**
 * Why a page drawn as $drawn cannot be filed as $wanted, or null when it can.
 *
 * Both are { theme, mode } - asked for through the cookies, and read back off
 * data-theme and data-theme-mode on <html>. They part when the build no longer
 * has the theme (the server drops a cookie it does not know) or a page draws
 * itself in something of its own; the picture would then be filed under a
 * name that says what it is not.
 */
export function drawnRefusal(requested, wanted, drawn) {
    if (drawn.theme === wanted.theme && drawn.mode === wanted.mode) {
        return null;
    }

    return `${requested} was drawn as ${drawn.theme ?? 'no theme'} ${drawn.mode ?? 'no mode'}, not ${wanted.theme} ${wanted.mode}.`;
}

/**
 * Why a click before the picture spoiled it, or null when it did not: the
 * click led to another page - a link, a submitted form - and the picture
 * would be of that page under the name of this one. Only the fragment may
 * change, which is how an in-page control says what it opened.
 */
export function clickRefusal(requested, selector, before, after) {
    const withoutFragment = (address) => address.replace(/#.*$/, '');
    if (withoutFragment(before) === withoutFragment(after)) {
        return null;
    }

    return `clicking ${selector} on ${requested} went on to ${after}; the picture would be of another page.`;
}

/**
 * The width of the classic scrollbar both browsers draw beside a page that
 * scrolls, in CSS pixels. Chromium on Linux draws it this wide on its own;
 * Firefox is told to by tests/snapshots/matrix.spec.ts, which reads the value
 * from here, so that the setting and the check below cannot part.
 */
export const SCROLLBAR_WIDTH = 15;

/**
 * Why a page laid out as $measured cannot be filed under $wanted pixels wide,
 * or null when it can.
 *
 * $measured is read in the page: the width of the window (innerWidth) and the
 * width it lends the document beside a scrollbar (clientWidth). The window has
 * to be exactly the width asked for, and the document either as wide - no
 * scrollbar - or exactly one scrollbar narrower. A document wider than that
 * (scrollWidth) is not looked at: a page that overflows is pictured
 * overflowing, which is what the picture is for. A browser that kept a window
 * of its own instead of the one it was given would otherwise have its picture
 * filed under a width it was never taken at.
 */
export function widthRefusal(requested, browser, wanted, measured) {
    if (measured.window !== wanted) {
        return `${requested} was laid out in ${browser} in a window ${measured.window}px wide (${measured.layout}px beside the scrollbar), not ${wanted}px.`;
    }
    if (measured.layout !== measured.window && measured.window - measured.layout !== SCROLLBAR_WIDTH) {
        return `${requested} was laid out in ${browser} ${measured.layout}px wide beside the scrollbar of a window ${measured.window}px wide; the scrollbar is ${SCROLLBAR_WIDTH}px or none.`;
    }

    return null;
}

/**
 * What a picture whose window had to be given its size again says about it,
 * or null for one that did not: the width asked for, and the window and the
 * layout as first measured - whichever of the two was off.
 */
export function resizedNote(result) {
    if (result.resized !== true) {
        return null;
    }
    const first = result.firstLayout;
    const measured = first === null || first === undefined
        ? 'of a size not recorded'
        : `${first.window}px wide with the page laid out ${first.width}px wide beside it`;

    return `asked for ${result.width}px, the window was at first ${measured}, and was given its size again`;
}

/** A value as text an HTML page can carry. */
function escape(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
}

/**
 * The index page: a section per path, a column per theme and mode, a row per
 * browser and width. A picture is a thumbnail linking to the file; a picture
 * that could not be taken is a red cell saying why, and so is one that was
 * expected and never reported - a run that stopped half way must not look like
 * a shorter matrix. A picture whose window had to be given its size again
 * carries a marked note saying so (resizedNote), as the summary does.
 */
export function indexPage({ title, paths, browsers, results }) {
    const byFile = new Map(results.map((result) => [result.file, result]));
    const columns = [];
    for (const theme of THEMES) {
        for (const mode of MODES) {
            columns.push({ theme, mode });
        }
    }

    const sections = paths.map((path) => {
        const rows = [];
        for (const browser of browsers) {
            for (const width of WIDTHS) {
                const cells = columns.map(({ theme, mode }) => {
                    const file = fileName(path, { theme, mode, width }, browser);
                    const result = byFile.get(file);
                    if (result === undefined) {
                        return `<td class="missing">never reported</td>`;
                    }
                    if (result.error !== null) {
                        return `<td class="failed">${escape(result.error)}</td>`;
                    }

                    const picture = `<a href="${escape(file)}"><img src="${escape(file)}" alt="${escape(file)}" loading="lazy"></a>`;
                    const note = resizedNote(result);
                    if (note !== null) {
                        return `<td class="resized" title="${escape(note)}">${picture}<p>RESIZED: ${escape(note)}</p></td>`;
                    }

                    return `<td>${picture}</td>`;
                });
                rows.push(`<tr><th scope="row">${escape(browser)}<br>${width}px</th>${cells.join('')}</tr>`);
            }
        }
        const head = columns.map(({ theme, mode }) => `<th scope="col">${theme} ${mode}</th>`).join('');

        return `<section><h2>${escape(path)}</h2><table><thead><tr><th></th>${head}</tr></thead><tbody>${rows.join('')}</tbody></table></section>`;
    });

    return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${escape(title)}</title>
<style>
body { font: 14px/1.4 system-ui, sans-serif; margin: 1rem; background: #f4f4f5; color: #18181b; }
table { border-collapse: collapse; margin-bottom: 2rem; }
th, td { border: 1px solid #d4d4d8; padding: .25rem; vertical-align: top; }
td { width: 240px; background: #fff; }
img { display: block; width: 240px; height: auto; max-height: 520px; object-fit: cover; object-position: top; }
.failed, .missing { background: #fee2e2; color: #991b1b; }
.resized { background: #fef3c7; }
.resized p { margin: .25rem 0 0; color: #92400e; font-size: 12px; }
</style>
</head>
<body>
<h1>${escape(title)}</h1>
${sections.join('\n')}
</body>
</html>
`;
}

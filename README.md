# Trilobit

A modular e-shop, CRM and CMS built on Nette and Latte. Open source, MIT.

A trilobite has three lobes along one axis, and so does this: three modules -
shop, CRM, content - on one shared spine, each of them switchable on its own.

## What is here today

The spine, the mechanism that switches modules on and off, the data layer that
mechanism reaches into, and the gate around all of it. There is a homepage,
three modules that are empty apart from a page saying they are here, and one
table per module so that switching a module off is something a test can watch
happen to a real database.

There is an administration at `/admin`: accounts that can sign in to it, and a
menu holding exactly what the enabled modules contributed. See "The
administration" below.

Public addresses are here too, and finished before there is any content to put
at them: one register in Core that a page and a product both claim addresses in,
so that neither carries the name of its module in a URL. See "Public addresses"
below.

There is also a design system: a set of components every page is drawn out of,
two themes that change the palette and the layout without a rebuild, and a style
guide at `/_styleguide` that shows the components by rendering them the way the
application does. See "The design system" below.

| path | what it is |
|---|---|
| `www/index.php` | the front controller; the document root is `www/`, nothing above it is reachable |
| `bin/trilobit` | the console; `app:warmup` writes what this build is made of to `var/build`, `app:tenant` makes a business and the hosts it answers at, `app:account` makes somebody who can sign in |
| `src/Core/Bootstrap.php` | turns a checkout into a compiled container |
| `src/Core/Module/` | what a module's name implies, and which modules this build has |
| `src/Core/DI/CoreExtension.php` | the five places a module hands something to Core |
| `src/Core/Security/` | who may sign in, and what the session then carries |
| `src/Core/Presentation/Front/` | the homepage, the shared layout and the base every public page is built on |
| `src/Core/Content/` | the register of public addresses: what may be saved, what answers where |
| `src/Core/Routing/` | the four layers of the address space, most specific first |
| `src/Core/Presentation/Admin/` | the administration at `/admin`: signing in, the overview, and the base every administration page is built on |
| `src/Core/Presentation/components/` | the components every page is built out of, one Latte block per file |
| `src/Core/Presentation/Styleguide/` | the page that shows those components, at `/_styleguide` |
| `assets/base.css`, `assets/themes/` | the design system: structure in one file, values in one file per theme |
| `src/Cms/`, `src/Crm/`, `src/Shop/` | the three switchable modules, each with its own extension, route, page, entity and migration |
| `src/*/Domain/` | the entities of a module; the mapping is registered by the module, never centrally |
| `src/*/Migrations/` | the migrations of a module, one namespace each |
| `src/Core/Doctrine/` | the naming rule tables follow, and the filter that keeps a switched-off module's tables out of reach |
| `config/modules.neon` | which modules this installation is made of |
| `config/` | `common.neon` for every environment, `services.neon` for this checkout |
| `vite.config.ts`, `assets/` | the front-end build; see "Front-end assets" below |
| `compose.yaml` | the database to develop and test against |
| `bin/check-leaks` | the guard that keeps private content out of a public repository |
| `tests/` | one directory per level of test, listed in `phpunit.xml` |

Pages are here; a catalogue and contacts are not. `Cms` owns the pages a site
says things on and the menus they are listed in, and its administration is at
`/admin/cms`. `Crm` and `Shop` still have one entity each, and it is a marker
carrying nothing but the date it was installed: a module that maps no entity
owns no table, and a module that owns no table cannot be used to show that
switching it off leaves its data alone. Each marker goes away with its
migration once its module has entities of its own, the way `cms_marker` did.

Core's own entities are real. It owns accounts, roles, settings and a media
library - the four things every build has whichever modules are switched on, and
the only tables a module is allowed to point a foreign key at.

## Modules

`config/modules.neon` is the only place that says which modules an installation
has:

```neon
parameters:
    trilobit:
        modules:
            cms: true
            crm: true
            shop: false
```

Core is not in that list and cannot be. Everything else follows from the name
alone - `shop` means `src/Shop`, the namespace `Trilobit\Shop`, the
configuration file `src/Shop/config/services.neon` and the compiler extension
`Trilobit\Shop\DI\ShopExtension` - so Core holds no list of modules and no
condition on one being enabled.

A module that is switched off is absent rather than hidden. It registers no
service, so nothing of it is in the compiled container; its configuration file
is never loaded, so its presenters are never scanned and a link into it fails
loudly instead of rendering an empty page; and it contributes no route, so its
path is claimed by nobody and the router says so. There is no catch-all route
for it to be caught by.

A module hands things to Core by tagging a service, never by Core naming the
module. Today that is routes, homepage signposts and administration menu
entries; event listeners and ports use the same mechanism and are waiting for
something to carry.

After changing the file, run `bin/trilobit app:warmup`. It rewrites
`var/build/modules.json` and `var/build/sources.css`, which is how the parts
that never start PHP - the asset bundler, the stylesheet build - find out the
same thing. Running it twice changes nothing.

### Writing another module

Create `src/<Name>/DI/<Name>Extension.php` and
`src/<Name>/config/services.neon`, add the lower-case name to
`config/modules.neon`, and give the module a layer and a ruleset line in
`deptrac.yaml`. All three are checked: the architecture suite fails if the
configuration and the source tree disagree about which modules exist, and
`deptrac analyse --fail-on-uncovered` fails if a directory under `src/` has no
rule. The one rule that matters is the one expressed by absence - a module may
depend on Core and on libraries, and never on another module.

## Tenants and domains

One installation runs several businesses, and which one a request belongs to is
settled from the host it arrived at, before its path is routed. Two businesses
therefore both have a page at `/kontakt`, both have media called `logo.png`,
and neither can read a row of the other's.

One business answers at as many hosts as it likes and they are aliases of each
other - another entrance to the same site, not a second site. A host is unique
across the whole installation, because two businesses claiming one host is not
a collision to resolve at read time; it is the question "whose request is this"
having two answers.

**A host nobody claims is refused.** There is no default business and no
fallback, on purpose: serving an unknown host out of some business hands one of
them the site of another, and looks exactly like a working page while it does
so. Development is not an exception and gets no switch - `localhost` is written
into `core_domain` like any other host, so a developer's machine takes the path
a visitor takes.

```sh
bin/trilobit app:tenant 'Your Business' localhost www.example.com
```

### What belongs to a business and what does not

| table | whose |
|---|---|
| `core_content_path`, `core_media_file`, `core_tenant_membership`, `core_domain` | the business's |
| `core_setting` | the installation's - a setting is true of the installation, not of one business |
| `core_user`, `core_role` | the installation's - see below |
| `core_audit_entry` | the installation's, for now |

An account is global and belonging to a business is a relationship:
`core_user.email` is unique across the installation, and `core_tenant_membership`
says which person holds which role in which business. What follows from that is
the point of it - a permission cannot be written down without saying where it
applies, so rights cannot seep from one business into another by being recorded
somewhere that has no business in it.

### The guard

The column is only worth having if no query can leave it out. A query that
forgets the business does not fail: it answers with rows, and they are somebody
else's. So `Trilobit\Core\Tenancy\TenantFilter`, a Doctrine filter, puts the
condition into every query over a table that belongs to one, and refuses to
build a constraint at all before it is settled which business this is - a
filter that stood down when it had nothing to compare against would be absent
exactly where it was needed.

The default is deny. An entity belongs to a business unless it carries
`#[Shared(because: '...')]`, which has to be written on purpose and states the
reason, so an entity nobody thought about is one the filter cannot scope.
`tests/Architecture/EveryTenantedEntityIsScopedTest` asks that of every mapped
entity at build time rather than at the first query: adding an entity to `src/`
with neither the association nor the attribute fails it by name.

Switching business also empties the object manager, because an object already
loaded is handed back without a query - past a filter that only ever sees SQL.

### Language

Every address carries the language it is in, and every business says which of
three ways its addresses tell you: a translated slug, a prefix in the path, or
the domain. It is one column with three values rather than a set of flags, so a
combination has nowhere to be written down.

Nothing reads either of them yet - the register answers in one language, as it
did before. They exist now because the unique index over an address is
`(tenant, language, path)`, and an index is migrated once or twice depending
only on whether the columns were there the first time.

## Public addresses

Pages, categories and products share one address space and none of them carries
the name of the module it belongs to: `/about` and `/bikes/mountain/mountain-bike-x`
sit beside each other at the root of the site. That is what makes the address a
person reads independent of how the application happens to be divided up.

The router is built from the most specific thing to the least, and the order is
the whole design:

| layer | what answers | where it is written |
|---|---|---|
| 0 | the root, `/` | `Trilobit\Core\Routing\RouterFactory` |
| 1 | static routes - `/admin/...`, `/_styleguide`, a module's own pages | one `RouteProvider` per module |
| 2 | short addresses of a record - `/r/12` - which answer 301 and draw nothing | `Trilobit\Core\Routing\ShortLinks` |
| 3 | everything else, looked up in the register of public addresses | `Trilobit\Core\Routing\ContentRouter`, always last |

### The register

`core_content_path` holds one row per address: the business it belongs to, the
language it is in, the whole path, the kind of content and the owning module's
own identifier for it, a label, and the address above it in the tree. An
address is unique within a business and a language, which is what lets two
businesses both have a page at `/kontakt`. The whole path rather than one segment of it, because
reading is the hot path and has to cost one lookup over a unique index whatever
the depth. The parent beside it, so that the tree stays a tree for breadcrumbs
and for renaming a branch. There is no limit on how deeply content may nest;
the limit is on how long an address may be, which is what the index can carry.

A module writes into it through `Trilobit\Core\Content\PathRegistry` and says
which kinds of content it draws through
`Trilobit\Core\Content\ContentTypeProvider`. Core holds no list of modules:
an address whose kind nothing in this build publishes is not routed at all, and
its row waits in the register for the module to come back.

### What is refused, and when

Everything is settled while somebody is saving, never while somebody is
reading. An address settled at read time would be settled by the order the
modules happen to be registered in, and that order changes when one of them is
switched off.

- **An address under a beginning something else answers at.** A page called
  `admin` is refused rather than saved and then never reachable.
  `Trilobit\Core\Content\ReservedSegments` holds Core's own beginnings, every
  declared module's name whether it is switched on or not, and whatever a route
  provider declares. `tests/Architecture/ReservedSegmentsCoverEveryRouteTest`
  walks the router that was actually built and fails on a static route whose
  beginning nobody reserved, so a new route without a reservation fails the
  build.
- **An address somebody else holds**, and one longer than the unique index can
  carry.
- **Any spelling but the stored one.** Addresses are lower case, without
  diacritics, with single slashes between the segments and none at either end.
  Every other spelling of an address that answers is redirected to it, 301.

### More than one address for one thing

A product is reachable at one address per category it belongs to, and every one
of them answers with the page rather than a redirect - a redirect would take
away the context the link was given in. One of them is the permalink; the rest
name it in `<link rel="canonical">`, and only the permalink belongs in a
sitemap. Which one it is, is a decision somebody makes through
`PathRegistry::makeCanonical()`; filing a product into another category never
moves it.

The trail of breadcrumbs is drawn from the address the visitor arrived at, so
the same product shows a different trail depending on how it was reached. That
is the same decision seen from the other side, and the reason more than one
address is worth having at all.

### Moving an address

`PathRegistry::rename()` moves an address and everything filed under it, and
leaves every address it vacates behind as a permanent redirect. Without that,
every rename quietly breaks every link from outside while the application looks
perfectly healthy.

### Linking into another module

A page pointing at a product stores a type and an identifier - never a class,
never a foreign key - and asks
`Trilobit\Core\Contract\Content\ContentLinkResolver` to turn it into a link
when it renders. In a build without the module that owns the product the port
answers null and no anchor is drawn: not an empty one, and not an error. That
is the half nobody meets while everything happens to be switched on, so it is
the half with a test.

## Pages and menus

`Cms` is the first module with content of its own. A page is what somebody
wrote, kept in `cms_page`, and where it answers, kept in Core's register - so
there is no slug column beside it, because the register is the one table an
address is unique in across every module. A page is written at `/admin/cms/pages`
and answers wherever the register leads to it.

A page that is not published answers 404. Its address stays claimed while it is
a draft, so nothing else can take it while it is being written, and from
outside the installation there is nothing there - which is the only answer that
does not tell a stranger something is being written.

A menu is arranged at `/admin/cms/menus`, and an entry leads to one of three
things: a page of this site, held as a relation; an address written out; or a
page of some module, held as a presenter's name. The third can name a module
that is switched off, which is exactly why it is text and not a foreign key -
the row waits for the module to come back, the way a row in the register does.

Such an entry is left out of the site. Asking the framework for a link into a
module that is not in the build does not stop the page: it draws a broken href
and carries on, so the page looks finished and the menu does not work. The
entry is therefore dropped while the page is being prepared, by
`Trilobit\Core\Presentation\Link\Destinations`, which asks whether this build
has the page at all. The administration does the opposite and shows the entry
with a word about why the site leaves it out, because whoever arranged it is
the only person who can decide what should happen to it.

## Front-end assets

`npm run build` bundles the shared code every page loads (`assets/app.ts` -
Naja, and `assets/app.css`'s Tailwind import) plus one bundle per switched-on
module (`src/<Name>/assets/entry.ts`), through Vite. `npm run dev` starts
Vite's dev server instead; which one a page actually gets is decided by
`{asset 'vite:...'}` in the Latte templates and needs no `if ($devMode)`
anywhere in the application.

A module that is off contributes no bundle. `vite.config.ts` reads
`var/build/modules.json` - the same file `bin/trilobit app:warmup` writes for
the stylesheet build - and only lists an entry point for a module that file
names; a checkout with no `var/build/modules.json` fails the build with a
message naming the command to run, rather than bundling every module's code
regardless of `config/modules.neon`.

### The build is in the repository

`www/build` is committed, so a clone runs without Node at all: point a document
root at `www/` and the pages arrive styled. Trilobit is meant to be runnable on
ordinary hosting, and a JavaScript toolchain is a steep price to ask before
somebody can look at it. Two smaller things follow from the same decision: a
deployment never builds, which is the riskiest moment to depend on a package
registry, and a rollback is a `git checkout`.

It is paid for in two ways.

The files are named without a content hash - `app.js`, not `app-0btNOuvg.js`.
A hashed name is not a modification to git but the deletion of one file and the
arrival of another, so the file's history and the delta the packer would have
stored are both lost, and the manifest changes on every build. What the hash
was also doing - telling a browser the file had changed - is done by
`bin/build-versions.mjs`, which records what each built file's contents hash
to, and by `Trilobit\Core\Asset\VersionedViteMapper`, which turns that into
`app.js?v=1a2b3c4d`. Git never sees a query string.

And a committed build can go stale: edit a `.ts`, forget to rebuild, commit,
and the repository shows new source while the application runs old code, with
nothing red anywhere, because every check reads the same stale file. So it is a
gate and not discipline. `npm run check:build` rebuilds into a throwaway
directory and compares; the `Build drift` workflow runs the same command on
every push, separately from `composer check`, which has to keep running with no
Node at all. It compares the *output*, so a change to the source that the
bundler drops - an exported function nobody calls - produces the same bytes,
needs no rebuild, and passes in silence.

When it fails, the fix is `npm run build` and committing what it writes. So it
is for a merge conflict inside `www/build`: `.gitattributes` deliberately
refuses to resolve one, because a merged bundle is a file neither side wrote.

`npm run e2e` runs Playwright. On a machine with Google Chrome installed it
uses that one, so nothing has to be downloaded; set `PLAYWRIGHT_CHANNEL` to
choose another, or leave it unset on a build server to use Playwright's own.
Unlike `composer check`, it writes to the database this checkout is configured
for: `tests/e2e/administration.spec.ts` brings the migrations up to date and
makes itself an account under a reserved documentation address, because a
password in a public repository is a disclosure git keeps forever.
`npm run test:frontend` runs what is claimed about the build itself under
Node's own runner, because `composer test` has no Node to run it with.

## The design system

Two files decide how the application looks, and they are split by what is in
them rather than by what they style.

| file | what is in it |
|---|---|
| `assets/base.css` | the reset, the layout primitives, every component's shape and behaviour, a few utilities |
| `assets/themes/<name>.css` | values: palette, type scale, spacing, radii, shadows, and the layout tokens the page shell is built out of |

`base.css` carries no value of its own - no colour, no length, no type scale.
Everything it uses is read out of a custom property a theme declares.
`tests/Architecture/BaseCssHoldsNoLiteralsTest` fails on a hexadecimal colour, a
colour notation, a named colour or a number with an absolute unit, so the split
is a mechanism rather than an intention: one colour written in "just for now"
looks harmless every time, and a theme cannot overrule it.

The default theme declares its tokens in Tailwind's `@theme static` block, so
Tailwind emits them as custom properties on `:root` and generates its own
utilities from the ones whose names fall in a namespace it knows. A second theme
re-declares the same names under `[data-theme="..."]`. There is no parallel set
of variables beside Tailwind's, and no build step involved in choosing between
them: the whole of a theme is one attribute on `<html>`.

### Two themes, and why the second one moves things

`atrium` is the theme the application starts in. `ledger` changes the palette and
the type - and also moves the navigation from under the banner to a column down
the left, narrows the content column and squares the corners. That is deliberate:
a second theme that only repainted would pass while the markup still had its
appearance written into it, which is exactly the failure the split exists to
prevent.

It moves the navigation without a template changing and without the order of the
elements in the page changing. The shell is one grid whose areas, columns and
rows are tokens; the layout writes banner, navigation, content and footer in that
order in every theme, and a theme decides where those areas are.
`tests/e2e/theme.spec.ts` switches `data-theme` on a live page and reads the
computed style and the resolved geometry back: the palette changes, the
navigation ends up beside the content instead of above it, and a marker left on
`window` beforehand is still there, which is what says no reload happened.

Light and dark are a variant inside a theme rather than two themes. Every colour
is a `light-dark()` pair, `base.css` maps `data-theme-mode` onto `color-scheme`,
and the browser resolves the rest. Adding a mode therefore costs one argument per
colour instead of a second copy of the theme.

### How wide the content runs

The content column has three widths, named rather than numbered: `content` is the
reading column the application has always had, `wide` is roomier, and `full` is
the whole region the content sits in. Names rather than a scale, because a number
would be chosen by eye on every page and the layout would stop being a layout.

Each theme says what each of the three measures, in `--layout-width-content`,
`--layout-width-wide` and `--layout-width-full`. `base.css` says which of them
`--layout-content-width` takes, out of `data-content-width` on `<html>`, and
everything meant to line up with the content column reads that one token -
`.l-container`, and the link list `atrium` aligns against it - so a page has one
content width rather than one per element that remembered to be told.

Those three rules are the one thing in `base.css` that sits outside the cascade
layers. A theme declares its tokens unlayered, and unlayered beats every layer
whatever is in it, so the same rules inside `@layer components` would be
overruled by the theme they are reading from - in that one theme and in no other,
which is the kind of difference nobody goes looking for.

A width offered but not drawn is a silent failure: the control appears, the click
works, the attribute lands on `<html>`, and the page is drawn at whatever the one
before it was. `tests/Template/ContentWidthModesTest` fails when a width this
build offers has no rule taking it to a token of its own, and the two checks
above - every token `base.css` reads is declared by every theme, and the themes
declare the same set as each other - close the rest of the triangle.
`tests/e2e/content-width.spec.ts` measures the three in both themes, in a window
made wide enough that they cannot come out the same.

#### Who chooses, and when a page overrules

Which of the three a page is drawn at is the reader's setting, kept the way the
theme is kept (below). A page may overrule it, and that is the exception rather
than the rule: a report with a column for every day of a month overflows at any
width and is unusable at a narrow one, and a page like that cannot wait for
somebody to remember to switch.

A page says so by calling `overruleContentWidth()` from its render method, which
is what makes the width belong to the page rather than to the class behind it -
one presenter answers at several addresses and they need not be drawn alike.
`/_styleguide` and `/_styleguide/full-width` are two actions of one presenter and
show exactly that.

Nothing is written down when a page overrules. The reader's setting is untouched,
the switch goes on showing it, and the next page is drawn at it again -
`Trilobit\Core\Preference\Preferences` keeps "what this page is drawn at", "what
the person prefers" and "what is worth remembering" apart on purpose, because the
alternative is a report quietly turning into a setting.

### Components

A component is a Latte block in a file of its own under
`src/Core/Presentation/components/`, and a record in
`Trilobit\Core\Presentation\Component\ComponentRegistry`. Its classes name what
it is (`c-card`, `c-card__media`), never what it looks like, and no Tailwind
utility is used inside one - a utility in the markup is a decision about
appearance that a theme cannot overrule. Page templates use utilities freely for
spacing and layout; only the components abstain.

Two tests hold the register and the directory together.
`tests/Template/ComponentRegistryTest` fails when a file has no record or a
record has no file, and `tests/Template/StyleguideShowsEveryComponentTest`
renders the style guide and fails when a registered variant has no specimen on
it. So a component nobody has shown does not pass `composer check`.

### The style guide

`/_styleguide` is a page of the application, not a separate tool: same base
presenter, same Latte engine, same layout, and it includes the same component
files the homepage does. A catalogue rendering its own HTML would drift away
from the application and nobody would see it happen.

It exists only where `trilobit.styleguide` is on - by default in debug mode, off
in production, and `config/local.neon` overrides either. Off means the route is
never registered, so the path is claimed by nobody and the answer is 404 rather
than 403: a tool that is not there has nothing to admit to.

The page carries a switcher for the theme, for the light/dark mode and for how
wide the content runs.

### What somebody prefers, and where it is kept

The switches are preferences, and a preference is one entry in
`Trilobit\Core\Preference\PreferenceCatalogue`. Its name decides everything
else: `theme` is drawn as `data-theme` on `<html>` and kept in a cookie called
`trilobit-theme`, `theme-mode` as `data-theme-mode` in `trilobit-theme-mode`,
`content-width` as `data-content-width` in `trilobit-content-width`. Adding a
fourth is that one entry, a control in a template, and a rule per answer in
`base.css` and in each theme - not a column and not a migration.
`tests/Template/StyleguideOffersEveryPreferenceTest` fails when the catalogue and
the controls part company either way round: an answer nobody can pick is a mode
that does not exist, and a control for an answer the catalogue has not got posts
a choice the server refuses while the page goes on looking right.

A choice is kept in two places, and they answer different questions:

| who | where |
|---|---|
| a device | one cookie per preference, `HttpOnly`, written by the server |
| a person | `core_user.preferences`, one JSON column |

The page is always drawn out of the cookies, so the first paint is already
right. A change writes the cookie, and the account too when somebody is signed
in. Signing in lets the profile win over the device - a device may be borrowed -
except for a preference the profile has no opinion about, which it takes over
from the device instead, so a theme picked before registering is not lost by
registering. Signing out changes nothing: a device keeps the look it had.

Only a deliberate choice is ever stored. Somebody who has not touched the
switches has no cookie and no row, and follows `trilobit.theme` in
`config/common.neon` - which is what keeps a remembered choice from silently
disagreeing with what a deployment configured. A stored value naming a theme the
build no longer has is dropped rather than honoured, so removing or renaming a
theme returns the people who chose it to configuration instead of leaving them
on a page whose tokens nothing declares.

One cookie per preference rather than one holding them all, because a single one
would have to be read, changed and written back: two choices made in the same
round trip would both read the old one and the second would drop the first,
invisibly, because the switch has already changed the page in front of the
person. `assets/app.ts` sends the changes one at a time for the same reason,
which is what keeps the account from losing one of a pair the same way.

`tests/e2e/preference.spec.ts` tells the whole of that as one story in a real
browser, and `Trilobit\Tests\Integration\Preference\RememberedPreferencesTest`
holds each rule on its own.

## The administration

`/admin` is Core's own and is in every build. It holds the sign-in page, the
overview, and a menu made of whatever the enabled modules contributed.

Make somebody who can sign in:

```sh
bin/trilobit app:account you@example.com --name 'Your Name'
```

The password is generated and printed once. It is never an argument - an
argument is in the shell history of the machine it was typed on and in that
machine's process list while the command runs - and what is stored is a hash of
it, made by `Nette\Security\Passwords`. Run the command again for an address
that already exists and it replaces the password rather than refusing, which is
what somebody who has lost theirs needs and what a deployment script calling it
every time needs.

Three decisions are worth stating.

**A visitor who is not signed in is redirected, never refused.** They have done
nothing wrong, and 403 on a page that exists tells somebody who is guessing that
it does. `tests/Integration/Admin/AdministrationTest` asserts the status code as
well as the destination, because a 500 carrying a `Location` header would
satisfy "goes to the sign-in page" and nothing else about it.

**The menu holds exactly what the modules contributed.** Core puts nothing in
it; the way back to the overview is the mark in the banner, the same way the way
back to the front page is the mark in the public banner. That is what lets
counting the entries answer a question about the modules rather than about Core,
and the count is asserted for all eight builds the application can be shipped as
- against the rendered page, not against the container behind it - in
`tests/Combination/AllModuleCombinationsTest`. Today a module's entry points at
that module's own public page, because no module has an administration section
yet; when one does, that is one line in its `<Module>Menu`.

**Roles and permissions are carried, not yet enforced.** An account holds roles,
a role carries a list of permissions, and the identity in the session carries
both - the overview shows them. Nothing checks one, because there is no page in
the administration that some accounts may open and others may not. The first
module that contributes one is when that changes.

The sign-in form carries no CSRF token of its own. nette/forms 3.3 deprecates
its token control as redundant beside the check the framework now makes on every
signal - the request has to come from this site, read off the browser's
`Sec-Fetch-Site` header - and a second mechanism beside the first is one more to
keep in step. `tests/e2e/administration.spec.ts` signs in through a real browser,
which is the only place that check can be seen working.

## The database

Every table carries the name of the module that owns it: `core_user`,
`shop_marker`, `cms_page`. That reads like a convention and it is the mechanism
the whole idea of a switchable module rests on.

A build without a module never loads that module's mapping, so nothing in it
knows those tables should exist - while the customer's database still has them,
full of records. Left alone, a schema comparator reads that as "tables with
nothing in the model to justify them" and writes a migration that drops them.
The only thing that can tell it otherwise is the name, so a build is given a
filter over the tables it may see, assembled from the modules it is made of.
`tests/Integration/Doctrine/DisabledModuleSchemaTest` states the claim and then
takes the filter off the same connection to show the drop appearing without it.

Each module registers its own mapping and its own migrations directory from its
own configuration file, so a build without the module has neither. Migrations
are recorded by full class name in a shared table, which is what lets a module
be switched back on and be brought up to date on its own.

Pending migrations run in the order they were written, not in the order their
class names sort in. A name here carries the module's namespace, so the
alphabet would otherwise decide which module goes first - and an installation
starting from an empty schema would try to create a module's table with a
foreign key into one of Core's that does not exist yet. See
`Trilobit\Core\Doctrine\ChronologicalComparator`.

### Generating a migration

Schema is generated, never written:

```sh
bin/trilobit migrations:diff --namespace='Trilobit\Shop\Migrations'
bin/trilobit migrations:migrate
```

The generator refuses to run in two situations, both of them things Doctrine
cannot know about on its own.

It refuses when any module is switched off, because the mapping of that module
is not loaded and a change to its entities would simply be absent from the
comparison - the migration would look finished, and what was missing would have
left no trace. Switch everything on, generate, switch back.

And it requires `--namespace`, and takes it as more than a destination:
Doctrine's own `--namespace` decides which directory the file is written to and
nothing else, so a migration asked for in one module's namespace would be
written with every table in the mapping in it - including tables belonging to
modules that could later be switched off. The namespace picks the comparison
too, through the table prefix the module owns.

What comes out is committed as it came out. A description and a docblock are
added by hand; the statements, their order and the class name are not touched.
A migration is worth trusting because it is provably what Doctrine derived from
the mapping, and a hand-written one loses that even when the SQL is identical.

One thing to expect: `bin/trilobit orm:validate-schema` reports the migrations
bookkeeping table (`core_migration`) as a difference, because the object mapper
knows nothing about it. `migrations:diff` excludes it and is the tool of record.

## Requirements

- PHP 8.4 or newer. Both 8.4 and 8.5 run in CI.
- Composer.
- MariaDB 11 LTS. It is the only tested target: the generated DDL differs
  between dialects, so "MySQL or MariaDB" would mean neither of them verified.
  `compose.yaml` starts the right one.
- Node, to change the front end - not to run it. `www/build` is committed, so a
  clone serves styled pages with no Node anywhere. `composer check` never needs
  it either: the suites render pages against a fixture manifest rather than
  against a real `www/build`, and `tests/Combination/NoRealBuildRequiredTest`
  moves a real one out of the way to prove it.

## Installation

```sh
git clone https://github.com/Rashengka/trilobit.git
cd trilobit
composer install
cp .env.example .env
docker compose up -d
bin/trilobit migrations:migrate
bin/trilobit app:warmup
bin/trilobit app:tenant 'Your Business' localhost
bin/trilobit app:account you@example.com
```

`app:warmup` writes down which modules this build is made of, for the parts
that never start PHP. `app:tenant` makes the business requests belong to and
the hosts it answers at - without it every request is refused, because a host
that names no business is never served by a default one; see "Tenants and
domains" above. `app:account` makes somebody who can sign in to `/admin`
and prints their password once; see "The administration" above. The scripts and
the stylesheet are already in the clone,
under `www/build`; run `npm ci && npm run build` only once you change something
under `assets/` or `src/*/assets/`, or once you switch a module on or off -
`www/build` is built for the modules `config/modules.neon` names.

Then serve `www/`:

```sh
php -S localhost:8000 -t www
```

`http://localhost:8000/` answers with the homepage and
`http://localhost:8000/admin` with the sign-in page; the last line above printed
the password to use there, once. For a real deployment point
the document root at `www/` and leave the rest of the checkout outside it;
`www/.htaccess` covers Apache.

Two settings are worth knowing about:

- `.env` holds what differs between deployments. Every value in `.env.example`
  is empty on purpose - a committed file carrying a host, a user name or a
  password is a disclosure git keeps forever. A variable set in the process
  environment wins over the file, so a container needs no `.env` at all.
- `TRILOBIT_DEBUG=1` turns on the debug bar and the detailed error page. It is
  a variable rather than a check on the visitor's address, because an address
  check is unreliable in production and would mean an address written into a
  public repository. Set it while working on the checkout: with it off the
  framework never rechecks the compiled container, so a change to a compiler
  extension has no effect until `var/tmp` is cleared. A change to a `.neon`
  file is picked up either way - the boot puts what those files say into the
  cache key.
- The database settings say where MariaDB is. Left empty they fall back to what
  `config/common.neon` names beside them, which is what `compose.yaml` starts;
  fill them in for anything else. Tests that need a database say so and skip
  when none answers, so a run without one looks different from a run with one.

`config/local.neon` is optional and applies to one machine; see
`config/local.neon.example`.

## Working on it

Enable the pre-commit hook and give the leak guard its list of private
patterns. Both are one-off, and the second one is not optional - without it the
guard exits with code 2 rather than reporting a success it cannot vouch for.

```sh
git config core.hooksPath .githooks

mkdir -p ~/.config/trilobit
cp .check-leaks.local.example ~/.config/trilobit/check-leaks.local
$EDITOR ~/.config/trilobit/check-leaks.local
```

That second file holds the words and path fragments that must never appear in a
commit: source trees that are not yours to publish, customer and project names,
class and table names taken from them. It is not in the repository and not in
any ignore list either - a committed list of forbidden words would be exactly
the disclosure it is meant to prevent.

### The gate

```sh
composer check
```

It runs, in this order:

| step | command | what it decides |
|---|---|---|
| `leaks` | `bin/check-leaks --all` | nothing private has reached a tracked file |
| `cs` | `php-cs-fixer check --diff` | the code is written the agreed way |
| `cs:sniff` | `phpcs` | the rules a formatter cannot express |
| `stan` | `phpstan analyse` | static analysis at level `max` |
| `deptrac` | `deptrac analyse --fail-on-uncovered` | no layer depends on something it may not |
| `rector` | `rector process --dry-run` | nothing is written in a way the project has moved past |
| `test` | `phpunit` | every suite in `phpunit.xml` |

The cheapest and most expensive-to-miss check runs first: whoever starts the
gate and walks away learns about a disclosure at once, not a minute later.

`cs` and `cs:sniff` are two tools over the same files, so the boundary between
them is drawn on purpose rather than left to chance. php-cs-fixer owns
everything about the shape of the code: whitespace, braces, import order, the
form of a `declare`. phpcs owns what is left, and it is the part a formatter
has no way to reach - what a thing is called, whether an import is still used,
whether a catch block can be reached, whether a class lives in the file its
namespace points at. Where a rule exists in both, `phpcs.xml` configures the
sniff to agree with the fixer or turns it off, with the reason and the name of
the rule on the other side written next to it. That is not tidiness: two
formatters that disagree make `composer check` pass or fail depending on which
one ran last, and `cs:fix` runs both.

Nothing in that list has a baseline, and none of it may acquire one.
`tests/Architecture/NoBaselineTest` fails if a baseline file appears anywhere in
the repository. Raising a threshold back up is something nobody ever gets round
to, so lowering it is made impossible rather than discouraged. The same goes for
the analysis level: `max` from the first commit, because it is never raised
afterwards either.

### The test suites

`phpunit.xml` declares one suite per level, including the ones that are still
empty. A suite that only appears once it has a test in it is a suite nobody
notices is missing.

| suite | what belongs in it | what may not |
|---|---|---|
| `unit` | plain objects and functions | container, database, filesystem, network |
| `architecture` | reading the sources and the configuration | running the application |
| `template` | compiling and rendering Latte | container, database |
| `integration` | a real container and a real database | a browser |
| `combination` | booting each combination of modules and running its migrations | anything past booting and migrating |
| `install` | a fresh clone, installed from scratch | writing into your working copy |
| `tooling` | the leak guard | - |

`tests/Tooling/CheckLeaksTest.php` is a standalone script rather than a test
case, because the guard has to work before Composer does. `LeakGuardTest` runs
it as a child process so that `composer check` covers it too.

Run one suite with `vendor/bin/phpunit --testsuite unit`.

The suites that need a database make a schema of their own per test class and
drop it afterwards, so two of them can never read each other's tables. Locally
that needs the grant `docker/mariadb/init` makes on a fresh volume; a checkout
whose database volume predates that file needs `docker compose down -v` once.
The schema in a test is built by running the migrations rather than from the
mapping - a schema built from metadata would be right every time and would
never once have shown that the migrations themselves are complete.

## Licence

MIT. See `LICENSE`.

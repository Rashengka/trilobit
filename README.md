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

There is also a design system: a set of components every page is drawn out of,
two themes that change the palette and the layout without a rebuild, and a style
guide at `/_styleguide` that shows the components by rendering them the way the
application does. See "The design system" below.

| path | what it is |
|---|---|
| `www/index.php` | the front controller; the document root is `www/`, nothing above it is reachable |
| `bin/trilobit` | the console; `app:warmup` writes what this build is made of to `var/build` |
| `src/Core/Bootstrap.php` | turns a checkout into a compiled container |
| `src/Core/Module/` | what a module's name implies, and which modules this build has |
| `src/Core/DI/CoreExtension.php` | the four places a module hands something to Core |
| `src/Core/Presentation/Front/` | the homepage, the shared layout and the base every public page is built on |
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

Catalogue, contacts and pages are not here. Each module has one entity, and in
three of them it is a marker carrying nothing but the date it was installed: a
module that maps no entity owns no table, and a module that owns no table cannot
be used to show that switching it off leaves its data alone. They go away with
their migrations once the modules have entities of their own.

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
module. Today that is routes and administration menu entries; event listeners
and ports use the same mechanism and are waiting for something to carry.

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

The page carries a switcher for the theme and for the light/dark mode. Neither
choice is remembered between page loads; what a build starts in is
`trilobit.theme` in `config/common.neon`.

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
```

`app:warmup` writes down which modules this build is made of, for the parts
that never start PHP. The scripts and the stylesheet are already in the clone,
under `www/build`; run `npm ci && npm run build` only once you change something
under `assets/` or `src/*/assets/`, or once you switch a module on or off -
`www/build` is built for the modules `config/modules.neon` names.

Then serve `www/`:

```sh
php -S localhost:8000 -t www
```

`http://localhost:8000/` answers with the homepage. For a real deployment point
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

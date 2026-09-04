# Trilobit

A modular e-shop, CRM and CMS built on Nette and Latte. Open source, MIT.

A trilobite has three lobes along one axis, and so does this: three modules -
shop, CRM, content - on one shared spine, each of them switchable on its own.

## What is here today

The spine, the mechanism that switches modules on and off, and the gate around
both. There is a homepage, three modules that are empty apart from a page
saying they are here, and no database yet.

| path | what it is |
|---|---|
| `www/index.php` | the front controller; the document root is `www/`, nothing above it is reachable |
| `bin/trilobit` | the console; `app:warmup` writes what this build is made of to `var/build` |
| `src/Core/Bootstrap.php` | turns a checkout into a compiled container |
| `src/Core/Module/` | what a module's name implies, and which modules this build has |
| `src/Core/DI/CoreExtension.php` | the four places a module hands something to Core |
| `src/Core/Presentation/Front/` | the homepage, the shared layout and the base every public page is built on |
| `src/Cms/`, `src/Crm/`, `src/Shop/` | the three switchable modules, each with its own extension, route and page |
| `config/modules.neon` | which modules this installation is made of |
| `config/` | `common.neon` for every environment, `services.neon` for this checkout |
| `bin/check-leaks` | the guard that keeps private content out of a public repository |
| `tests/` | one directory per level of test, listed in `phpunit.xml` |

Entities and migrations are not here yet. Catalogue, contacts and pages are not
here either: the three modules carry the wiring every module has and nothing
else, because the wiring is what decides what "switched off" means, and it is
worth having right before there is anything to switch off.

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

## Requirements

- PHP 8.4 or newer. Both 8.4 and 8.5 run in CI.
- Composer.
- MariaDB 11 LTS, once there is a database to talk to. It is the only tested
  target: the generated DDL differs between dialects, so "MySQL or MariaDB"
  would mean neither of them verified.

## Installation

```sh
git clone https://github.com/Rashengka/trilobit.git
cd trilobit
composer install
cp .env.example .env
```

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
  public repository.

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
| `stan` | `phpstan analyse` | static analysis at level `max` |
| `deptrac` | `deptrac analyse --fail-on-uncovered` | no layer depends on something it may not |
| `rector` | `rector process --dry-run` | nothing is written in a way the project has moved past |
| `test` | `phpunit` | every suite in `phpunit.xml` |

The cheapest and most expensive-to-miss check runs first: whoever starts the
gate and walks away learns about a disclosure at once, not a minute later.

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
| `integration` | a real container, later a real database | a browser |
| `combination` | booting each combination of modules | anything past booting |
| `install` | a fresh clone, installed from scratch | writing into your working copy |
| `tooling` | the leak guard | - |

`tests/Tooling/CheckLeaksTest.php` is a standalone script rather than a test
case, because the guard has to work before Composer does. `LeakGuardTest` runs
it as a child process so that `composer check` covers it too.

Run one suite with `vendor/bin/phpunit --testsuite unit`.

## Licence

MIT. See `LICENSE`.

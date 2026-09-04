# Trilobit

A modular e-shop, CRM and CMS built on Nette and Latte. Open source, MIT.

A trilobite has three lobes along one axis, and so does this: three modules -
shop, CRM, content - on one shared spine, each of them switchable on its own.

## What is here today

The spine and the gate around it. There is one page, no module, and no database
yet; what exists is the skeleton every later piece has to fit into, and the
checks that stop it drifting.

| path | what it is |
|---|---|
| `www/index.php` | the front controller; the document root is `www/`, nothing above it is reachable |
| `src/Core/Bootstrap.php` | turns a checkout into a compiled container |
| `src/Core/DI/CoreExtension.php` | the four places a module hands something to Core |
| `src/Core/Presentation/Front/` | the homepage, its template class and its Latte templates |
| `config/` | `common.neon` for every environment, `services.neon` for this checkout |
| `bin/check-leaks` | the guard that keeps private content out of a public repository |
| `tests/` | one directory per level of test, listed in `phpunit.xml` |

Modules, entities and migrations are not here yet. When they arrive they arrive
as directories beside `src/Core/`, without Core learning their names.

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

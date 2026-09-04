# Trilobit

A modular e-shop, CRM and CMS built on Nette and Latte. Open source, MIT.

## What is here today

The application does not exist yet. This repository currently holds one thing:
the guard that keeps content which does not belong in a public repository out
of it. It is first on purpose - anything committed before it would have to be
reviewed by hand.

| path | what it is |
|---|---|
| `bin/check-leaks` | the guard; reads, never writes |
| `.check-leaks.yaml` | its public configuration: structural rules only |
| `.check-leaks.local.example` | template for the private pattern file, which lives outside the repository |
| `.githooks/pre-commit` | runs the guard over what is staged |
| `tests/Tooling/CheckLeaksTest.php` | proves every rule fires, discriminates, and that the hook really blocks a commit |

## Setting up a clone

Two steps, both one-off:

```sh
git config core.hooksPath .githooks

mkdir -p ~/.config/trilobit
cp .check-leaks.local.example ~/.config/trilobit/check-leaks.local
$EDITOR ~/.config/trilobit/check-leaks.local
```

The second file holds the words and path fragments that must never appear in a
commit: source trees that are not yours to publish, customer and project names,
class and table names taken from them. It is not in the repository and not in
any ignore list either - a committed list of forbidden words would be exactly
the disclosure it is meant to prevent, and git would keep it forever.

Without that file `bin/check-leaks` exits with code **2** and refuses to report
success. A guard that passes everything when it is unconfigured is worse than
no guard, because it looks like one.

## Running the guard

```sh
bin/check-leaks                            # what is staged (the hook's mode)
bin/check-leaks --range origin/main...HEAD # a pull request, for CI
bin/check-leaks --all                      # every tracked file
bin/check-leaks --history                  # every commit and message on every branch
bin/check-leaks --files path/to/file       # files that are not staged yet
```

Exit codes: `0` clean, `1` finding, `2` tool error. Findings are printed as
`file:line [rule] masked-snippet` followed by one sentence on what to do. The
snippet is masked because a CI log of a public repository is public too.

`--history` is slow and is meant to be run once, immediately before the
repository is made public, and afterwards only on suspicion.

### Demo content

Inside `src/*/DataFixtures/`, `tests/**/Fixtures/` and `demo/` the rules are
narrower rather than looser: addresses end in `example.com`, phone numbers use
the reserved prefix, company numbers stay in the reserved range, links point at
the reserved hosts, and people are named from `demo_names` in
`.check-leaks.yaml`. Invented data is written to a convention the tool can
check, so that a leak cannot hide in the one place where fake data is expected.

### Suppressing a single finding

A last resort, on one line, naming the rule and the reason:

```php
$address = 'name@sub.example'; // check-leaks:allow rule=email reason=RFC 2606 example in a docblock
```

Without both `rule=` and `reason=` the comment is ignored and the finding
stands. Every suppression is listed on every run, green ones included, so a
growing number is visible in every log, and `max_suppressions` in
`.check-leaks.yaml` caps how many may be in effect at once.

Only a suppression that actually switched a finding off counts against that
cap. A comment that suppresses nothing is reported as a stale suppression
instead: it should be deleted, and it must not eat the budget meant for real
exceptions.

## Tests

```sh
php tests/Tooling/CheckLeaksTest.php
```

It runs without composer, because the guard has to work before the application
exists. Every rule in `.check-leaks.yaml` needs a sample that trips it and a
counterexample that does not, or the test fails. The hook is exercised in a
throw-away repository, never in yours.

A full PHPUnit suite, static analysis and a `composer check` gate arrive with
the application skeleton; this test becomes a case in it.

<?php

/**
 * Test for bin/check-leaks.
 *
 * It is a standalone script on purpose: the leak guard has to work before the
 * application, and therefore before composer, exists. Run it with
 *
 *   php tests/Tooling/CheckLeaksTest.php
 *
 * Once the project has a test suite this becomes a PHPUnit test case; the
 * checks below are already written as one assertion per claim.
 *
 * Nothing here touches the working repository: every check runs in a temporary
 * directory, with a temporary HOME, and the hook check builds its own clone.
 */

declare(strict_types=1);

const ROOT = __DIR__ . '/../..';
const FIXTURES = __DIR__ . '/fixtures';

/** Where a sample is placed, so that the path-dependent rules see what they expect. */
const SAMPLE_PATHS = [
    'dsn' => '.env.example', // check-leaks:allow rule=dsn reason=the key is a rule name, not a data source
    'demo_email' => 'src/Shop/DataFixtures/DemoData.php',
    'demo_phone' => 'src/Shop/DataFixtures/DemoData.php',
    'demo_company_id' => 'src/Shop/DataFixtures/DemoData.php',
    'demo_url' => 'src/Shop/DataFixtures/DemoData.php',
    'demo_name' => 'src/Shop/DataFixtures/DemoData.php',
];
const DEFAULT_SAMPLE_PATH = 'src/Shop/Domain/Sample.php';

/**
 * The tool scans this file too. A complete suppression written out here would
 * be read as a suppression OF this file, and reported as stale because there
 * is nothing on that line to suppress - true, but noise, and noise is where a
 * genuinely stale suppression goes unnoticed. Assembling the marker keeps it
 * out of the scanner's reach while the content handed to the tool is byte for
 * byte what a real suppression looks like.
 */
const ALLOW = 'check-leaks' . ':allow';

/** Invented patterns; they exist nowhere but here and in private_pattern.sample. */
const LOCAL_PATTERNS = ['zzz-marker-corpus', '/srv/private/zzz-marker-corpus'];

exit(run());

function run(): int
{
    $failures = [];

    checkRuleSamples($failures);
    checkFixtureDirectory($failures);
    checkExemptPaths($failures);
    checkMissingLocalConfig($failures);
    checkEmptyLocalConfig($failures);
    checkSuppressions($failures);
    checkSuppressionCap($failures);
    checkFileNameIsChecked($failures);
    checkStagedModeAndHook($failures);
    checkRepositoryIsClean($failures);

    if ($failures === []) {
        printf("\nAll checks passed.\n");

        return 0;
    }

    printf("\n%d failing check(s):\n", count($failures));
    foreach ($failures as $failure) {
        printf("  - %s\n", $failure);
    }

    return 1;
}

/**
 * Every rule declared in .check-leaks.yaml has a sample that trips it and a
 * counterexample that does not. A rule without both is a failing test, so a
 * rule cannot be added without proving that it fires and that it discriminates.
 *
 * @param list<string> $failures
 */
function checkRuleSamples(array &$failures): void
{
    foreach (declaredRules() as $rule) {
        $dirty = FIXTURES . '/' . $rule . '.sample';
        $clean = FIXTURES . '/' . $rule . '.clean.sample';

        if (!is_file($dirty) || !is_file($clean)) {
            $failures[] = sprintf('rule "%s" is missing %s.sample or %s.clean.sample', $rule, $rule, $rule);
            continue;
        }

        $path = SAMPLE_PATHS[$rule] ?? DEFAULT_SAMPLE_PATH;

        [$code, $out] = checkFiles([$path => file_get_contents($dirty)]);
        assertSame(1, $code, sprintf('%s.sample must be a finding (output: %s)', $rule, oneLine($out)), $failures);
        assertContains('[' . $rule . ']', $out, sprintf('%s.sample must be reported by rule %s', $rule, $rule), $failures);

        [$code, $out] = checkFiles([$path => file_get_contents($clean)]);
        assertSame(0, $code, sprintf('%s.clean.sample must pass (output: %s)', $rule, oneLine($out)), $failures);
    }
}

/**
 * The fixture directory is skipped by the tool, but only for the .sample
 * extension. Anything else there would be an unchecked hiding place.
 *
 * @param list<string> $failures
 */
function checkFixtureDirectory(array &$failures): void
{
    $rules = declaredRules();
    foreach (scandir(FIXTURES) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (!str_ends_with($entry, '.sample')) {
            $failures[] = sprintf('tests/Tooling/fixtures/%s does not end with .sample and would not be checked', $entry);
            continue;
        }
        $rule = preg_replace('/(\.clean)?\.sample$/', '', $entry);
        if (!in_array($rule, $rules, true)) {
            $failures[] = sprintf('tests/Tooling/fixtures/%s belongs to no rule declared in .check-leaks.yaml', $entry);
        }
    }
}

/**
 * The Czech rule has to stand down where Czech belongs, and nowhere else.
 *
 * @param list<string> $failures
 */
function checkExemptPaths(array &$failures): void
{
    $czech = (string) file_get_contents(FIXTURES . '/czech_text.sample');

    foreach (['translations/cs.neon', 'LICENSE'] as $path) {
        [$code, $out] = checkFiles([$path => $czech]);
        assertSame(0, $code, sprintf('Czech in %s is allowed (output: %s)', $path, oneLine($out)), $failures);
    }

    [$code] = checkFiles(['src/Core/Ui/Menu.php' => $czech]);
    assertSame(1, $code, 'Czech in ordinary source is a finding', $failures);

    // Commit trailers have to carry a no-reply address; those are listed one by
    // one in allowed_emails, so a real mailbox on the same domain still fails.
    [$code, $out] = checkFiles(['src/Core/App.php' => 'Co-Authored-By: A Robot <noreply@anthropic.com>']);
    assertSame(0, $code, sprintf('an address listed in allowed_emails passes (output: %s)', oneLine($out)), $failures);

    // Built rather than written down: the counterexample is an address the tool
    // must reject, and rejected addresses do not belong in a committed file.
    $otherMailbox = 'someone' . strstr('noreply@anthropic.com', '@');
    [$code] = checkFiles(['src/Core/App.php' => 'Written-By: A Person <' . $otherMailbox . '>']);
    assertSame(1, $code, 'another mailbox on the same domain is still a finding', $failures);
}

/**
 * The decision the whole design rests on: no private pattern file means no
 * green run.
 *
 * @param list<string> $failures
 */
function checkMissingLocalConfig(array &$failures): void
{
    $workspace = workspace(['src/Core/App.php' => "<?php\n"], null);
    [$code, $out, $err] = execute([binary(), '--files', 'src/Core/App.php'], $workspace['dir'], $workspace['home']);

    assertSame(2, $code, sprintf('missing local configuration exits with 2 (output: %s)', oneLine($out . $err)), $failures);
    assertContains('missing the local pattern file', $err, 'the error says what is missing', $failures);
    assertContains('check-leaks.local', $err, 'the error names the file to create', $failures);
}

/**
 * An empty pattern file would pass everything while looking green.
 *
 * @param list<string> $failures
 */
function checkEmptyLocalConfig(array &$failures): void
{
    $workspace = workspace(['src/Core/App.php' => "<?php\n"], []);
    [$code, , $err] = execute([binary(), '--files', 'src/Core/App.php'], $workspace['dir'], $workspace['home']);

    assertSame(2, $code, 'an empty local pattern file exits with 2', $failures);
    assertContains('no patterns', $err, 'the error explains that the file is empty', $failures);
}

/**
 * @param list<string> $failures
 */
function checkSuppressions(array &$failures): void
{
    $line = trim((string) file_get_contents(FIXTURES . '/email.sample'));
    $lines = explode("\n", $line);
    $offending = end($lines);

    [$code] = checkFiles([DEFAULT_SAMPLE_PATH => $offending . ' // check-leaks:allow']);
    assertSame(1, $code, 'a suppression without a rule and a reason does not suppress', $failures);

    [$code] = checkFiles([DEFAULT_SAMPLE_PATH => $offending . ' // check-leaks:allow rule=email']);
    assertSame(1, $code, 'a suppression without a reason does not suppress', $failures);

    [$code, $out, $err] = checkFiles([
        DEFAULT_SAMPLE_PATH => $offending . ' // ' . ALLOW . ' rule=email reason=address of the sample itself',
    ]);
    assertSame(0, $code, sprintf('a complete suppression suppresses (output: %s)', oneLine($out . $err)), $failures);
    assertContains('suppressed', $err, 'suppressions are reported even on a green run', $failures);
    assertContains('address of the sample itself', $err, 'the summary carries the reason', $failures);
}

/**
 * Without a cap the suppressions would quietly grow until the tool checked
 * nothing at all.
 *
 * @param list<string> $failures
 */
function checkSuppressionCap(array &$failures): void
{
    $cap = declaredCap();
    $lines = explode("\n", trim((string) file_get_contents(FIXTURES . '/email.sample')));
    $offending = end($lines);

    $content = '';
    for ($i = 0; $i <= $cap; $i++) {
        $content .= $offending . ' // ' . ALLOW . ' rule=email reason=sample number ' . $i . "\n";
    }

    [$code, $out] = checkFiles([DEFAULT_SAMPLE_PATH => $content]);
    assertSame(1, $code, 'more suppressions than max_suppressions is a failure', $failures);
    assertContains('Too many suppressions', $out, 'the failure says why', $failures);
}

/**
 * The file name is content too - that is where a leak usually shows up first.
 *
 * @param list<string> $failures
 */
function checkFileNameIsChecked(array &$failures): void
{
    [$code, $out] = checkFiles(['src/Core/' . LOCAL_PATTERNS[0] . '.php' => "<?php\n"]);
    assertSame(1, $code, 'a private pattern in the file name is a finding', $failures);
    assertContains('[private_pattern]', $out, 'the file name finding names the rule', $failures);
}

/**
 * The only way to know the hook actually runs. It happens in a throw-away
 * repository, never in the working one.
 *
 * @param list<string> $failures
 */
function checkStagedModeAndHook(array &$failures): void
{
    $workspace = workspace([], LOCAL_PATTERNS);
    $repo = $workspace['dir'] . '/repo';
    $home = $workspace['home'];

    if (execute(['git', 'rev-parse', '--verify', 'HEAD'], realpath(ROOT), $home)[0] === 0) {
        execute(['git', 'clone', '--quiet', 'file://' . realpath(ROOT), $repo], $workspace['dir'], $home);
        $howBuilt = 'clone';
    } else {
        // Before the first commit there is nothing to clone; copy instead.
        mkdir($repo, 0777, true);
        execute(['git', 'init', '--quiet'], $repo, $home);
        foreach (['bin/check-leaks', '.githooks/pre-commit', '.check-leaks.yaml'] as $file) {
            @mkdir($repo . '/' . dirname($file), 0777, true);
            copy(ROOT . '/' . $file, $repo . '/' . $file);
            chmod($repo . '/' . $file, 0755);
        }
        $howBuilt = 'copy';
    }

    if (!is_file($repo . '/bin/check-leaks')) {
        $failures[] = 'could not build a temporary repository for the hook check';

        return;
    }
    printf("hook check runs against a temporary repository built by %s\n", $howBuilt);

    execute(['git', 'config', 'core.hooksPath', '.githooks'], $repo, $home);
    @mkdir($repo . '/src/Shop/Domain', 0777, true);
    file_put_contents($repo . '/src/Shop/Domain/Leak.php', file_get_contents(FIXTURES . '/email.sample'));
    execute(['git', 'add', 'src/Shop/Domain/Leak.php'], $repo, $home);

    [$code, $out] = execute([$repo . '/bin/check-leaks', '--staged'], $repo, $home);
    assertSame(1, $code, sprintf('--staged sees the staged sample (output: %s)', oneLine($out)), $failures);
    assertContains('[email]', $out, '--staged names the rule', $failures);

    $before = trim(execute(['git', 'rev-list', '--count', '--all'], $repo, $home)[1]);
    [$commitCode, $commitOut, $commitErr] = execute(
        ['git', '-c', 'user.name=Leak Test', '-c', 'user.email=leak-test@example.com', 'commit', '-m', 'Add a sample file'],
        $repo,
        $home
    );
    $after = trim(execute(['git', 'rev-list', '--count', '--all'], $repo, $home)[1]);

    assertSame(true, $commitCode !== 0, 'the hook blocks git commit', $failures);
    assertSame($before, $after, 'no commit was created', $failures);
    assertContains('[email]', $commitOut . $commitErr, 'the blocked commit shows the finding', $failures);
}

/**
 * The repository has to satisfy its own guard.
 *
 * @param list<string> $failures
 */
function checkRepositoryIsClean(array &$failures): void
{
    // A pattern that cannot appear anywhere, generated per run rather than written down.
    $home = tempDirectory('home');
    writeLocalConfig($home, [bin2hex(random_bytes(6))]);

    [$code, $out, $err] = execute([binary(), '--all'], realpath(ROOT), $home);
    assertSame(0, $code, sprintf('bin/check-leaks --all is clean over this repository (output: %s)', oneLine($out . $err)), $failures);
}

/**
 * @param array<string, string> $files
 * @return array{0: int, 1: string, 2: string}
 */
function checkFiles(array $files): array
{
    $workspace = workspace($files, LOCAL_PATTERNS);
    $arguments = [binary(), '--files'];
    foreach (array_keys($files) as $path) {
        $arguments[] = $path;
    }

    return execute($arguments, $workspace['dir'], $workspace['home']);
}

/**
 * @param array<string, string> $files
 * @param list<string>|null $patterns null means: do not create the file at all
 * @return array{dir: string, home: string}
 */
function workspace(array $files, ?array $patterns): array
{
    $dir = tempDirectory('work');
    copy(ROOT . '/.check-leaks.yaml', $dir . '/.check-leaks.yaml');

    foreach ($files as $path => $contents) {
        $full = $dir . '/' . $path;
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, $contents);
    }

    $home = tempDirectory('home');
    if ($patterns !== null) {
        writeLocalConfig($home, $patterns);
    }

    return ['dir' => $dir, 'home' => $home];
}

/**
 * @param list<string> $patterns
 */
function writeLocalConfig(string $home, array $patterns): void
{
    $target = $home . '/.config/trilobit';
    mkdir($target, 0777, true);
    file_put_contents($target . '/check-leaks.local', implode("\n", $patterns) . "\n");
}

function tempDirectory(string $prefix): string
{
    $dir = sys_get_temp_dir() . '/check-leaks-' . $prefix . '-' . bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    return $dir;
}

function binary(): string
{
    return (string) realpath(ROOT . '/bin/check-leaks');
}

/**
 * @param list<string> $command
 * @return array{0: int, 1: string, 2: string}
 */
function execute(array $command, string $cwd, string $home): array
{
    $line = implode(' ', array_map('escapeshellarg', $command));
    $environment = [
        'HOME' => $home,
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin:/usr/local/bin',
        'GIT_CONFIG_GLOBAL' => $home . '/.gitconfig',
        'GIT_CONFIG_SYSTEM' => '/dev/null',
        'GIT_TERMINAL_PROMPT' => '0',
    ];

    $process = proc_open($line, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $environment);
    if (!is_resource($process)) {
        return [-1, '', 'could not start: ' . $line];
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

/**
 * @param list<string> $failures
 */
function assertSame(mixed $expected, mixed $actual, string $message, array &$failures): void
{
    if ($expected === $actual) {
        printf("  ok  %s\n", $message);

        return;
    }
    printf("FAIL  %s (expected %s, got %s)\n", $message, var_export($expected, true), var_export($actual, true));
    $failures[] = $message;
}

/**
 * @param list<string> $failures
 */
function assertContains(string $needle, string $haystack, string $message, array &$failures): void
{
    if (str_contains($haystack, $needle)) {
        printf("  ok  %s\n", $message);

        return;
    }
    printf("FAIL  %s (no \"%s\" in: %s)\n", $message, $needle, oneLine($haystack));
    $failures[] = $message;
}

function oneLine(string $text): string
{
    $text = trim((string) preg_replace('/\s+/', ' ', $text));

    return strlen($text) > 200 ? substr($text, 0, 200) . '...' : $text;
}

/**
 * Read the rule names straight from the public configuration, with a parser of
 * its own, so that a mistake in the tool cannot hide a missing sample.
 *
 * @return list<string>
 */
function declaredRules(): array
{
    $rules = [];
    $inside = false;
    foreach (file(ROOT . '/.check-leaks.yaml') ?: [] as $line) {
        $line = rtrim($line, "\r\n");
        if (preg_match('/^rules:\s*$/', $line) === 1) {
            $inside = true;
            continue;
        }
        if (!$inside) {
            continue;
        }
        if (preg_match('/^\S/', $line) === 1) {
            break;
        }
        if (preg_match('/^  ([a-z0-9_]+):\s*$/', $line, $m) === 1) {
            $rules[] = $m[1];
        }
    }

    return $rules;
}

function declaredCap(): int
{
    foreach (file(ROOT . '/.check-leaks.yaml') ?: [] as $line) {
        if (preg_match('/^max_suppressions:\s*(\d+)/', $line, $m) === 1) {
            return (int) $m[1];
        }
    }

    return 0;
}

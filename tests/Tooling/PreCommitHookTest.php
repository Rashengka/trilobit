<?php

declare(strict_types=1);

namespace Trilobit\Tests\Tooling;

use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * .githooks/pre-commit, on a host whose PHP is older than the project's floor.
 *
 * The project runs in Docker, so the host may carry any PHP or none, and
 * bin/check-leaks cannot run on one below composer.json's require.php - it
 * stops on a parse error. The hook then has to run the check in the project's
 * php container instead, and the risk worth testing is not that it fails to
 * do so but that it fails quietly: a hook that cannot look and exits 0 would
 * read exactly like one that looked and found nothing.
 *
 * Every case runs in a throw-away repository with a PATH of its own. On it,
 * `php` is a stand-in that answers the version probe as the PHP it pretends
 * to be and, when that PHP is below the floor, fails on a script the way an
 * old PHP fails on this one. `docker` is a stand-in too: it records what it
 * was asked, and for `compose exec` it either answers with a set exit code
 * or, where a case needs the whole path, runs the command on the real PHP as
 * the container would - only /app does not exist here, so those cases keep
 * the index outside the checkout.
 *
 * Neither stand-in may meet a real one. A CI runner carries a real docker in
 * /usr/bin, and often a real php beside it, so the rest of the PATH is not
 * the system's directories but one of the sandbox's own, holding links to
 * the few tools the hook, bin/check-leaks and the stand-ins call - and never
 * to a php or a docker.
 */
#[CoversNothing]
final class PreCommitHookTest extends TestCase
{
    private const string FLOOR = '>=8.4';

    /**
     * Everything the hook, bin/check-leaks and the stand-ins run by name.
     * bin/check-leaks starts git through /bin/sh, and its shebang finds php
     * through /usr/bin/env; both are absolute, but the commands they look up
     * are not.
     */
    private const array TOOLS = ['sh', 'env', 'git', 'sed', 'grep'];

    /**
     * Where a tool from TOOLS is looked for on the machine running the test.
     */
    private const array SYSTEM_DIRECTORIES = ['/usr/bin', '/bin'];

    private string $sandbox;

    private string $repo;

    private string $home;

    private string $bin;

    private string $tools;

    private string $containerBin;

    private string $dockerLog;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/trilobit-pre-commit-' . bin2hex(random_bytes(6));
        $this->repo = $this->sandbox . '/repo';
        $this->home = $this->sandbox . '/home';
        $this->bin = $this->sandbox . '/bin';
        $this->tools = $this->sandbox . '/tools';
        $this->containerBin = $this->sandbox . '/container-bin';
        $this->dockerLog = $this->sandbox . '/docker.log';

        FileSystem::createDir($this->repo);
        FileSystem::createDir($this->bin);
        $this->linkTools();
        FileSystem::write($this->home . '/.config/trilobit/check-leaks.local', 'zzz-marker-pre-commit' . "\n");

        foreach (['.githooks/pre-commit', 'bin/check-leaks', '.check-leaks.yaml'] as $file) {
            FileSystem::copy($this->root() . '/' . $file, $this->repo . '/' . $file);
        }
        chmod($this->repo . '/.githooks/pre-commit', 0o755);
        chmod($this->repo . '/bin/check-leaks', 0o755);
        $this->requirePhp(self::FLOOR);

        $this->git(['init', '--quiet']);
    }

    protected function tearDown(): void
    {
        FileSystem::delete($this->sandbox);
    }

    public function testAnOldHostPhpLeavesTheCheckToTheContainer(): void
    {
        $this->hostPhp(7, 4);
        $this->docker(running: true);
        $this->stage('src/Core/Clean.php', "<?php\n");

        [$code, $out] = $this->hook();

        self::assertSame(0, $code, $out);
        $exec = $this->dockerExec();
        self::assertNotNull($exec, 'the hook never asked the container. Output: ' . $out);
        self::assertSame(['compose', 'exec', '-T'], array_slice($exec, 0, 3));
        self::assertContains('php', $exec);
        self::assertStringContainsString('bin/check-leaks --staged', implode(' ', $exec));
        self::assertNotContains('-e', $exec, 'no index of its own was named, so none is passed');
    }

    public function testTheContainersFindingBlocksTheCommit(): void
    {
        $this->hostPhp(7, 4);
        $this->docker(running: true, execExit: 1);
        $this->stage('src/Core/Clean.php', "<?php\n");

        self::assertSame(1, $this->hook()[0]);
    }

    public function testAPhpOneMinorBelowTheFloorIsNotEnough(): void
    {
        $this->hostPhp(8, 3);
        $this->docker(running: true);
        $this->stage('src/Core/Clean.php', "<?php\n");

        [$code, $out] = $this->hook();

        self::assertSame(0, $code, $out);
        self::assertNotNull($this->dockerExec(), 'PHP 8.3 is below a floor of 8.4. Output: ' . $out);
    }

    public function testAFloorItCannotReadSendsTheCheckToTheContainer(): void
    {
        $this->requirePhp('8.4.*');
        $this->hostPhp(9, 0);
        $this->docker(running: true);
        $this->stage('src/Core/Clean.php', "<?php\n");

        [$code, $out] = $this->hook();

        self::assertSame(0, $code, $out);
        self::assertNotNull($this->dockerExec(), 'an unread floor is not taken as met. Output: ' . $out);
    }

    public function testWithoutAComposerJsonThereIsNoFloorToMeet(): void
    {
        FileSystem::delete($this->repo . '/composer.json');
        $this->hostPhp(9, 0);
        $this->docker(running: true);
        $this->stage('src/Core/Clean.php', "<?php\n");

        [$code, $out] = $this->hook();

        self::assertSame(0, $code, $out);
        self::assertNotNull($this->dockerExec(), 'no floor is not taken as met. Output: ' . $out);
    }

    public function testAHostPhpOnTheFloorChecksOnTheHost(): void
    {
        $this->hostPhp(8, 4);
        $this->docker(running: true);

        $this->stage('src/Core/Clean.php', "<?php\n");
        [$cleanCode, $cleanOut] = $this->hook();
        self::assertSame(0, $cleanCode, $cleanOut);

        $this->stage('src/Core/Leak.php', FileSystem::read(__DIR__ . '/fixtures/email.sample'));
        [$leakCode, $leakOut] = $this->hook();
        self::assertSame(1, $leakCode, $leakOut);
        self::assertStringContainsString('[email]', $leakOut);

        self::assertFileDoesNotExist($this->dockerLog, 'a PHP on the floor does not need the container');
    }

    public function testATemporaryIndexInsideTheCheckoutIsTranslatedToApp(): void
    {
        $this->hostPhp(7, 4);
        $this->docker(running: true);
        $this->stage('src/Core/Clean.php', "<?php\n");
        FileSystem::copy($this->repo . '/.git/index', $this->repo . '/.git/next-index-1.lock');

        [$absoluteCode, $absoluteOut] = $this->hook(['GIT_INDEX_FILE' => $this->repo . '/.git/next-index-1.lock']);
        self::assertSame(0, $absoluteCode, $absoluteOut);
        self::assertSame('GIT_INDEX_FILE=/app/.git/next-index-1.lock', $this->passedIndex());

        FileSystem::delete($this->dockerLog);

        // git names the ordinary index relative to the hook's directory.
        [$relativeCode, $relativeOut] = $this->hook(['GIT_INDEX_FILE' => '.git/index']);
        self::assertSame(0, $relativeCode, $relativeOut);
        self::assertSame('GIT_INDEX_FILE=/app/.git/index', $this->passedIndex());
    }

    /**
     * A worktree's index lives in the main checkout's git directory, which the
     * worktree's stack mounts at the same path.
     */
    public function testAnIndexOutsideTheCheckoutIsPassedUnchanged(): void
    {
        $this->hostPhp(7, 4);
        $this->docker(running: true);
        $this->stage('src/Core/Clean.php', "<?php\n");
        $elsewhere = $this->sandbox . '/common/worktrees/one/index';
        FileSystem::copy($this->repo . '/.git/index', $elsewhere);

        [$code, $out] = $this->hook(['GIT_INDEX_FILE' => $elsewhere]);

        self::assertSame(0, $code, $out);
        self::assertSame('GIT_INDEX_FILE=' . $elsewhere, $this->passedIndex());
    }

    public function testTheContainerChecksTheStageTheCommitRecords(): void
    {
        $this->hostPhp(7, 4);
        $this->docker(running: true, runInContainer: true);
        $this->stage('src/Core/Leak.php', FileSystem::read(__DIR__ . '/fixtures/email.sample'));
        $index = $this->sandbox . '/common/worktrees/one/index';
        FileSystem::copy($this->repo . '/.git/index', $index);
        // The ordinary index loses the leak; only the named one still has it.
        $this->git(['rm', '--cached', '--quiet', 'src/Core/Leak.php']);
        $this->stage('src/Core/Clean.php', "<?php\n");

        [$code, $out] = $this->hook(['GIT_INDEX_FILE' => $index]);

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('[email]', $out);
    }

    /**
     * The silent failure this hook must not have: a container that reads some
     * other index - an empty one, say - would find nothing in it and pass.
     */
    public function testAContainerThatSeesAnotherStageRefusesToCheck(): void
    {
        $this->hostPhp(7, 4);
        $this->docker(running: true, runInContainer: true, containerIndex: $this->sandbox . '/no-such-index');
        $this->stage('src/Core/Leak.php', FileSystem::read(__DIR__ . '/fixtures/email.sample'));

        [$code, $out] = $this->hook();

        self::assertSame(2, $code, $out);
        self::assertStringContainsString('does not see the stage', $out);
    }

    public function testWithoutDockerTheCommitIsRefusedOutLoud(): void
    {
        $this->hostPhp(7, 4);
        $this->stage('src/Core/Clean.php', "<?php\n");
        $this->git(['config', 'core.hooksPath', '.githooks']);

        [$code, $out] = $this->execute(
            ['git', '-c', 'user.name=Hook Test', '-c', 'user.email=hook-test@example.com', 'commit', '-m', 'A commit'],
        );

        self::assertNotSame(0, $code, $out);
        self::assertStringContainsString('no docker', $out);
        self::assertStringContainsString('docker compose up -d', $out);
        self::assertNotSame(0, $this->execute(['git', 'rev-parse', '--verify', '--quiet', 'HEAD'])[0], 'no commit was created');
    }

    public function testAStoppedContainerIsRefusedOutLoud(): void
    {
        $this->hostPhp(7, 4);
        $this->docker(running: false);
        $this->stage('src/Core/Clean.php', "<?php\n");

        [$code, $out] = $this->hook();

        self::assertSame(2, $code, $out);
        self::assertStringContainsString('not running', $out);
        self::assertStringContainsString('docker compose up -d', $out);
        self::assertNull($this->dockerExec());
    }

    public function testADockerThatCannotAnswerIsRefusedOutLoud(): void
    {
        $this->hostPhp(7, 4);
        $this->docker(running: true, psExit: 1);
        $this->stage('src/Core/Clean.php', "<?php\n");

        [$code, $out] = $this->hook();

        self::assertSame(2, $code, $out);
        self::assertStringContainsString('docker compose up -d', $out);
        self::assertNull($this->dockerExec());
    }

    public function testNothingStagedStillPassesWithoutAskingAnybody(): void
    {
        $this->hostPhp(7, 4);
        $this->docker(running: true);

        [$code, $out] = $this->hook();

        self::assertSame(0, $code, $out);
        self::assertFileDoesNotExist($this->dockerLog);
    }

    private function requirePhp(string $constraint): void
    {
        FileSystem::write(
            $this->repo . '/composer.json',
            Json::encode(['name' => 'example/hook-test', 'require' => ['php' => $constraint]], pretty: true) . "\n",
        );
    }

    /**
     * A `php` that answers the version probe as the given PHP. Below the floor
     * it fails on a script the way an old PHP fails on bin/check-leaks; on or
     * above it, it is the real PHP running the script.
     */
    private function hostPhp(int $major, int $minor): void
    {
        $old = version_compare("{$major}.{$minor}", '8.4', '<') ? '1' : '0';
        $this->script('php', <<<SH
            #!/bin/sh
            if [ "\$1" = "-r" ]; then
                code=\$(printf '%s' "\$2" | sed -e 's/PHP_MAJOR_VERSION/{$major}/g' -e 's/PHP_MINOR_VERSION/{$minor}/g')
                shift 2
                exec '{$this->realPhp()}' -r "\$code" "\$@"
            fi
            if [ {$old} = 1 ]; then
                echo "PHP Parse error:  syntax error, unexpected '=>' in \$1 on line 1" >&2
                exit 255
            fi
            exec '{$this->realPhp()}' "\$@"
            SH);
    }

    private function docker(
        bool $running,
        int $execExit = 0,
        int $psExit = 0,
        bool $runInContainer = false,
        ?string $containerIndex = null,
    ): void {
        $services = $running ? 'database\nphp\n' : 'database\n';
        $run = $runInContainer ? '1' : '0';
        $index = $containerIndex === null ? '' : 'GIT_INDEX_FILE=' . $containerIndex;
        $containerPath = $this->containerBin . ':' . $this->systemPath();
        $this->script('docker', <<<SH
            #!/bin/sh
            { for argument in "\$@"; do printf '%s\\n' "\$argument"; done; printf '%s\\n' '----'; } >> '{$this->dockerLog}'
            if [ "\$1 \$2" = "compose ps" ]; then
                [ {$psExit} = 0 ] || { echo 'Cannot connect to the Docker daemon' >&2; exit {$psExit}; }
                printf '{$services}'
                exit 0
            fi
            if [ "\$1 \$2" = "compose exec" ] && [ {$run} = 1 ]; then
                shift 3
                environment=
                while [ "\$1" = "-e" ]; do environment="\$environment \$2"; shift 2; done
                shift
                exec env -i HOME='{$this->home}' PATH='{$containerPath}' GIT_CONFIG_SYSTEM=/dev/null \$environment {$index} "\$@"
            fi
            exit {$execExit}
            SH);
    }

    private function script(string $name, string $content): void
    {
        FileSystem::write($this->bin . '/' . $name, $content . "\n");
        chmod($this->bin . '/' . $name, 0o755);
    }

    private function stage(string $path, string $content): void
    {
        FileSystem::write($this->repo . '/' . $path, $content);
        $this->git(['add', $path]);
    }

    /**
     * @param list<string> $arguments
     */
    private function git(array $arguments): void
    {
        [$code, $out] = $this->execute(['git', ...$arguments]);
        self::assertSame(0, $code, $out);
    }

    /**
     * @param array<string, string> $environment
     * @return array{0: int, 1: string}
     */
    private function hook(array $environment = []): array
    {
        return $this->execute(['sh', $this->repo . '/.githooks/pre-commit'], $environment);
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     * @return array{0: int, 1: string}
     */
    private function execute(array $command, array $environment = []): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->repo, [
            'HOME' => $this->home,
            'PATH' => $this->bin . ':' . $this->systemPath(),
            'GIT_CONFIG_GLOBAL' => $this->home . '/.gitconfig',
            'GIT_CONFIG_SYSTEM' => '/dev/null',
            ...$environment,
        ]);
        self::assertIsResource($process);

        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertIsString($out);
        self::assertIsString($err);

        return [proc_close($process), $out . $err];
    }

    /**
     * The arguments of the `docker compose exec` call, or null when there was none.
     *
     * @return list<string>|null
     */
    private function dockerExec(): ?array
    {
        if (!is_file($this->dockerLog)) {
            return null;
        }
        foreach (explode("----\n", FileSystem::read($this->dockerLog)) as $call) {
            $arguments = explode("\n", rtrim($call, "\n"));
            if (array_slice($arguments, 0, 2) === ['compose', 'exec']) {
                return $arguments;
            }
        }

        return null;
    }

    private function passedIndex(): ?string
    {
        $exec = $this->dockerExec();
        self::assertNotNull($exec, 'the hook never asked the container');
        $at = array_search('-e', $exec, true);

        return $at === false ? null : ($exec[$at + 1] ?? null);
    }

    /**
     * Links every tool from TOOLS into the sandbox, from the first system
     * directory that has it, and the real PHP alone into the directory the
     * docker stand-in gives the container.
     *
     * A tool that is missing stops the test by name rather than leaving it
     * out: the hook would then fail for a reason no case is about, and a case
     * that expects a failure would pass on it. And the PATH is checked once it
     * is built, so that a php or a docker added to TOOLS one day cannot bring
     * back the machine's own - the very thing this directory is here to keep
     * out.
     */
    private function linkTools(): void
    {
        FileSystem::createDir($this->tools);
        foreach (self::TOOLS as $tool) {
            $found = null;
            foreach (self::SYSTEM_DIRECTORIES as $directory) {
                if (is_file($directory . '/' . $tool) && is_executable($directory . '/' . $tool)) {
                    $found = $directory . '/' . $tool;
                    break;
                }
            }
            if ($found === null) {
                self::fail(sprintf('The hook needs `%s`, and none of %s has it.', $tool, implode(', ', self::SYSTEM_DIRECTORIES)));
            }
            self::assertTrue(symlink($found, $this->tools . '/' . $tool), 'could not link ' . $found);
        }

        FileSystem::createDir($this->containerBin);
        self::assertTrue(symlink($this->realPhp(), $this->containerBin . '/php'), 'could not link ' . $this->realPhp());

        foreach (['php', 'docker'] as $intruder) {
            self::assertFileDoesNotExist(
                $this->tools . '/' . $intruder,
                "a real {$intruder} on the hook's PATH would answer in place of the stand-in",
            );
        }
        self::assertFileDoesNotExist($this->containerBin . '/docker', 'the container has no docker either');
    }

    /**
     * The sandbox's own directory of links to the tools in TOOLS, and nothing
     * else: no php and no docker but the stand-ins in $bin, wherever the
     * machine running the test keeps real ones.
     */
    private function systemPath(): string
    {
        return $this->tools;
    }

    private function realPhp(): string
    {
        return PHP_BINARY;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}

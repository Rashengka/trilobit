<?php

declare(strict_types=1);

namespace Trilobit\Tests\Tooling;

use PHPUnit\Framework\TestCase;

/**
 * Runs the leak guard's own test as a child process and reports its verdict.
 *
 * CheckLeaksTest.php is a standalone script on purpose: the guard has to work
 * before composer exists, so it may not depend on a test framework. Its claims
 * are therefore not repeated here - repeating them would create a second copy
 * that can drift from the first. This case only makes sure the script is part
 * of `composer check` and that nobody has to remember to run it by hand.
 *
 * A child process is not an implementation detail either: the script ends in
 * exit(), so loading it into this process would end the test run.
 */
final class LeakGuardTest extends TestCase
{
    public function testTheGuardPassesItsOwnTest(): void
    {
        [$code, $output] = $this->runGuard(dirname(__DIR__) . '/Tooling/CheckLeaksTest.php');

        self::assertSame(0, $code, $output);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runGuard(string $script): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([PHP_BINARY, $script], $descriptors, $pipes, dirname(__DIR__, 2));
        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertIsString($stdout);
        self::assertIsString($stderr);

        return [proc_close($process), $stdout . $stderr];
    }
}

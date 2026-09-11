<?php

declare(strict_types=1);

namespace Trilobit\Tests\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Tests\Runner\WhatTheWholeRunCost;

/**
 * The suite's own budget, tried from both sides of the line.
 *
 * Two things have to be true and neither can be read off a green run. The
 * verdict has to be right about the number, which is asserted here directly.
 * And going over has to end the run rather than print a line - which cannot be
 * asserted from inside a run that is passing, so a second PHPUnit is started
 * against tests/Fixtures/over-budget.xml, a configuration whose whole content
 * is one trivial test and a budget nothing fits into, and its exit status is
 * read.
 *
 * That second half is the one worth having. An extension that measured
 * perfectly and reported through a channel PHPUnit ignores would look exactly
 * like this one on every green run, and would be discovered on the day it was
 * needed.
 */
#[CoversNothing]
final class TheRunSaysWhatItCostTest extends TestCase
{
    public function testARunInsideItsBudgetHasNoComplaint(): void
    {
        self::assertNull(WhatTheWholeRunCost::verdict(120.0, 300.0));
    }

    /**
     * A budget is a ceiling somebody may stand on. Asserted because the printed
     * line and the verdict would otherwise disagree at exactly one value, and
     * that is the value somebody will be sitting on when they read it.
     */
    public function testARunExactlyOnItsBudgetHasNoComplaint(): void
    {
        self::assertNull(WhatTheWholeRunCost::verdict(300.0, 300.0));
    }

    public function testARunOverItsBudgetSaysBothNumbers(): void
    {
        $complaint = WhatTheWholeRunCost::verdict(451.2, 300.0);

        self::assertIsString($complaint);
        self::assertStringContainsString('451.2', $complaint);
        self::assertStringContainsString('300', $complaint);
    }

    /**
     * Processor time has to actually move when work is done, or the budget
     * would be held against a number that is always the same and would never
     * fire. Wall time is not compared against: the point of the measure is that
     * the two are allowed to disagree.
     */
    public function testProcessorTimeGrowsWithWorkDone(): void
    {
        $before = WhatTheWholeRunCost::processorSecondsSoFar();

        $sum = 0.0;
        for ($i = 0; $i < 2_000_000; $i++) {
            $sum += sqrt($i);
        }

        self::assertGreaterThan(0.0, $sum, 'the work has to have been done rather than optimised away');
        self::assertGreaterThan($before, WhatTheWholeRunCost::processorSecondsSoFar());
    }

    /**
     * The whole chain, from the other side of a process boundary: over budget
     * has to leave a non-zero status and say why, and it has to print the
     * ordinary line on the way past as well.
     */
    public function testARunOverItsBudgetEndsRed(): void
    {
        [$status, $output] = $this->runTheFixtureConfiguration();

        self::assertNotSame(0, $status, 'a run over its budget left a passing status: ' . $output);
        self::assertStringContainsString('over its budget', $output);
        self::assertStringContainsString('whole run:', $output, 'the ordinary line has to be printed too');
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runTheFixtureConfiguration(): array
    {
        $root = dirname(__DIR__, 2);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, $root . '/vendor/bin/phpunit', '--configuration', $root . '/tests/Fixtures/over-budget.xml'],
            $descriptors,
            $pipes,
            $root,
        );
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

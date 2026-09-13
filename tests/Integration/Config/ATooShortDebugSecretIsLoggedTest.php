<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Config;

use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tracy\Debugger;
use Tracy\ILogger;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Config\DebugGate;
use Trilobit\Core\Config\Environment;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Double\Config\RecordingLogger;

/**
 * A staging deployment whose debug secret is set and too short to be taken.
 *
 * Staging then runs without its debugger, as it does with no secret at all -
 * but the two are not the same state. No secret is a choice; a short one is a
 * mistake, and one that looks from the browser exactly like the choice. So it
 * is written to the log as a warning, once for every build it is made in
 * rather than on every request, which on a busy staging would bury everything
 * else in the file.
 *
 * Every case builds into a directory of its own, because "once for every
 * build" can only be observed on a build that has not been made before.
 */
#[CoversClass(Bootstrap::class)]
#[CoversClass(DebugGate::class)]
final class ATooShortDebugSecretIsLoggedTest extends TestCase
{
    /** Eighteen characters, made up. */
    private const string SHORT = 'short-' . 'short-' . 'short-';

    /** Made up for this test and long enough to count; no deployment has it. */
    private const string LONG_ENOUGH = 'made-up-' . 'made-up-' . 'made-up-' . 'made-up-' . 'made-up-';

    private ILogger $previous;

    private RecordingLogger $logger;

    private string $directory = '';

    protected function setUp(): void
    {
        $this->previous = Debugger::getLogger();
        $this->logger = new RecordingLogger();
        Debugger::setLogger($this->logger);

        $this->directory = sys_get_temp_dir() . '/trilobit-gate-' . bin2hex(random_bytes(6));
        FileSystem::createDir($this->directory);
    }

    protected function tearDown(): void
    {
        Debugger::setLogger($this->previous);
        FileSystem::delete($this->directory);
    }

    public function testOnStagingASecretTooShortIsLoggedAsAWarning(): void
    {
        $this->boot('staging', self::SHORT);

        $warnings = $this->logger->at(ILogger::WARNING);
        self::assertCount(1, $warnings);
        self::assertStringContainsString(DebugGate::VARIABLE, $warnings[0]);
        self::assertStringContainsString('18 characters', $warnings[0]);
        self::assertStringContainsString((string) DebugGate::SHORTEST_SECRET, $warnings[0]);
        self::assertFalse(str_contains($warnings[0], self::SHORT), 'the warning shows the secret itself');
    }

    /** The second request finds the build made and logs nothing more. */
    public function testItIsLoggedOnceForTheBuildRatherThanOnEveryRequest(): void
    {
        $this->boot('staging', self::SHORT);
        $this->boot('staging', self::SHORT);

        self::assertCount(1, $this->logger->at(ILogger::WARNING));
    }

    /**
     * A secret that was fine and is shortened on a running deployment still
     * gets its warning, although a build of that deployment already exists -
     * which is why the build is told whether the secret is too short.
     */
    public function testShorteningAGoodSecretIsLoggedAlthoughTheBuildWasMadeBefore(): void
    {
        $this->boot('staging', self::LONG_ENOUGH);
        $this->boot('staging', self::SHORT);

        self::assertCount(1, $this->logger->at(ILogger::WARNING));
    }

    /** No secret is how staging is closed on purpose, so there is nothing to warn about. */
    public function testNoSecretIsNotLogged(): void
    {
        $this->boot('staging', null);
        $this->boot('staging', '');

        self::assertSame([], $this->logger->at(ILogger::WARNING));
    }

    public function testASecretLongEnoughIsNotLogged(): void
    {
        $this->boot('staging', self::LONG_ENOUGH);

        self::assertSame([], $this->logger->at(ILogger::WARNING));
    }

    /** Neither dev nor prod reads the secret, so neither has anything to say about it. */
    public function testOutsideStagingTheSecretIsNotReadAndNotLogged(): void
    {
        $this->boot('dev', self::SHORT);
        $this->boot('prod', self::SHORT);

        self::assertSame([], $this->logger->at(ILogger::WARNING));
    }

    private function boot(string $mode, ?string $phrase): void
    {
        Boot::container(
            ModuleList::of([], Bootstrap::rootDirectory()),
            environment: Environment::fromValues(
                ['TRILOBIT_ENV' => $mode, ...($phrase === null ? [] : [DebugGate::VARIABLE => $phrase])],
            ),
            tempDirectory: $this->directory,
        );
    }
}

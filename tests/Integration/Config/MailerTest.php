<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Config;

use Nette\Mail\FileMailer;
use Nette\Mail\Mailer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Config\Environment;
use Trilobit\Core\Mail\Mailers;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Double\Mail\CapturingMailer;

/**
 * The mailer as a build hands it out.
 *
 * Two claims, and the first is the one every other suite stands on: no build a
 * test makes can send mail anywhere. The second is that a real build takes its
 * mailer from the environment the way Trilobit\Core\Mail\Mailers says, and
 * that a setting it refuses stops the build at the moment mail is first asked
 * for - not at the moment somebody wonders why nothing arrived.
 *
 * **The mail settings are put into the process environment, not into the
 * Environment handed to the boot.** That one decides the mode, which is
 * compiled in; the mailer reads the environment the running application reads,
 * which is Core's core.environment - the .env beside the application overlaid
 * with the process environment. A value placed anywhere else would never reach
 * it, and a build on a machine whose .env says nothing would quietly answer
 * with its default instead.
 */
#[CoversNothing]
final class MailerTest extends TestCase
{
    /** What the process environment said about the transport before the case, false for nothing. */
    private string|false|null $transportBefore = null;

    protected function tearDown(): void
    {
        if ($this->transportBefore !== null) {
            putenv($this->transportBefore === false
                ? Mailers::TRANSPORT
                : Mailers::TRANSPORT . '=' . $this->transportBefore);
            $this->transportBefore = null;
        }
    }

    public function testEveryBuildATestMakesKeepsItsMailInsteadOfSendingIt(): void
    {
        self::assertInstanceOf(CapturingMailer::class, Boot::coreAlone()->getByType(Mailer::class));
    }

    /**
     * One mailer, and it is Core's. nette/bootstrap registers a mailer of its
     * own the moment nette/mail is installed, and it sends through PHP's mail()
     * - which in a container with no sendmail hands the message to nothing and
     * reports success. Two of them would also leave anything asking by type
     * with no answer. So the framework's is not registered at all, and this is
     * asked of a real build rather than of the one the suites are given.
     */
    public function testABuildHasOneMailerAndItIsCores(): void
    {
        $container = Boot::container(
            ModuleList::of([], Bootstrap::rootDirectory()),
            environment: Environment::fromValues(['TRILOBIT_ENV' => 'dev']),
            capturingMail: false,
        );

        self::assertSame(['core.mailer'], $container->findByType(Mailer::class));
    }

    public function testARealBuildTakesItsMailerFromTheEnvironment(): void
    {
        $this->transport('file');
        $container = Boot::container(
            ModuleList::of([], Bootstrap::rootDirectory()),
            environment: Environment::fromValues(['TRILOBIT_ENV' => 'dev']),
            capturingMail: false,
        );

        self::assertInstanceOf(FileMailer::class, $container->getByType(Mailer::class));
    }

    public function testARealBuildRefusesMailItCouldNotDeliverWhenMailIsFirstAskedFor(): void
    {
        $this->transport('file');
        $container = Boot::container(
            ModuleList::of([], Bootstrap::rootDirectory()),
            environment: Environment::fromValues(['TRILOBIT_ENV' => 'prod']),
            capturingMail: false,
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(Mailers::TRANSPORT);

        $container->getByType(Mailer::class);
    }

    /** Says which way mail leaves, where the running application reads it; tearDown() puts it back. */
    private function transport(string $value): void
    {
        $this->transportBefore ??= getenv(Mailers::TRANSPORT);
        putenv(Mailers::TRANSPORT . '=' . $value);
    }
}

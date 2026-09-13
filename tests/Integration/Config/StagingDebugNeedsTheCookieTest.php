<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Config;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Config\DebugGate;
use Trilobit\Tests\WebServer;

/**
 * A staging deployment, asked through the real entry point for the one thing
 * only debug mode serves: the debug bar's own script.
 *
 * Tracy answers ?_tracy_bar=js while it is being switched on, before the
 * container, the router or a tenant exist, and only in debug mode; outside it
 * the request goes on to the application like any other. So the answer says
 * which of the two the boot decided on, and says it the same on a machine with
 * a database and without one. The style guide would not do: staging has it
 * whether debug mode is on or not.
 *
 * The first case is the one that proves the other two measure anything - a
 * probe that never showed the bar would pass them all.
 */
#[CoversNothing]
final class StagingDebugNeedsTheCookieTest extends TestCase
{
    /** Made up for this test and long enough to count; no deployment has it. */
    private const string INVENTED = 'made-up-' . 'made-up-' . 'made-up-' . 'made-up-' . 'made-up-';

    /** As long as the right one and wrong in every character. */
    private const string WRONG = 'mistake-' . 'mistake-' . 'mistake-' . 'mistake-' . 'mistake-';

    private const string PROBE = '/?_tracy_bar=js';

    public function testWithTheCookieTheDebugBarIsServed(): void
    {
        $response = $this->probe(self::INVENTED);

        self::assertSame(200, $response['status']);
        self::assertStringStartsWith('application/javascript', $response['type']);
        self::assertStringContainsString('Tracy.DebugBar', $response['body']);
    }

    public function testWithoutTheCookieItIsNot(): void
    {
        $this->assertNoDebugBar($this->probe(null));
    }

    public function testWithAWrongCookieItIsNot(): void
    {
        $this->assertNoDebugBar($this->probe(self::WRONG));
    }

    /** @return array{status: int, type: string, body: string} */
    private function probe(?string $cookie): array
    {
        return WebServer::request(
            ['TRILOBIT_ENV' => 'staging', DebugGate::VARIABLE => self::INVENTED],
            self::PROBE,
            $cookie === null ? [] : ['Cookie: ' . DebugGate::COOKIE . '=' . $cookie],
        );
    }

    /** @param array{status: int, type: string, body: string} $response */
    private function assertNoDebugBar(array $response): void
    {
        self::assertStringStartsNotWith('application/javascript', $response['type']);
        self::assertStringNotContainsString('Tracy.DebugBar', $response['body']);
    }
}

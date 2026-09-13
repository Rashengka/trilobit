<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Config;

use Nette\Utils\FileSystem;
use Nette\Utils\Finder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tracy\Debugger;
use Tracy\ILogger;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Config\DebugGate;
use Trilobit\Core\Config\Secrets;
use Trilobit\Tests\Boot;
use Trilobit\Tests\WebServer;

/**
 * Tracy's error page, searched for the debug gate's secret wherever the page
 * could show it: the variable in $_SERVER, the raw Cookie header in $_SERVER
 * as HTTP_COOKIE, and the cookie in $_COOKIE.
 *
 * Nothing hides any of the three by being told about it. They are hidden
 * because both names were chosen to carry the word "secret" and the header is
 * called a cookie, which is what Trilobit\Core\Config\Secrets hides values by.
 * This asks the page rather than the rule, so that it fails if either name is
 * changed to one the rule does not read as secret.
 *
 * Two pages, because neither shows all three. The copy logged from a console
 * has no HTTP request section, so $_COOKIE is never on it; the page a browser
 * is shown has it, and is rendered by the same error page with the same rule.
 *
 * As in TracyKeepsTheSecretsTest, every name is first found on the page - a
 * page that never carried the secret would otherwise pass as one that hid it -
 * and the page itself is never handed to PHPUnit as a haystack, because it
 * lists the environment of the machine the suite runs on.
 */
#[CoversClass(Secrets::class)]
final class TracyHidesTheDebugGateSecretTest extends TestCase
{
    /** Made up for this test and long enough to count; no deployment has it. */
    private const string VALUE = 'made-up-' . 'made-up-' . 'made-up-' . 'made-up-' . 'made-up-';

    private const string HEADER = 'HTTP_COOKIE';

    public function testTheLoggedErrorPageShowsTheVariableAndTheHeaderAndNotTheSecret(): void
    {
        $before = [getenv(DebugGate::VARIABLE), $_SERVER];
        $directory = sys_get_temp_dir() . '/trilobit-tracy-' . bin2hex(random_bytes(6));
        FileSystem::createDir($directory);

        putenv(DebugGate::VARIABLE . '=' . self::VALUE);
        $_SERVER[DebugGate::VARIABLE] = self::VALUE;
        $_SERVER[self::HEADER] = DebugGate::COOKIE . '=' . self::VALUE;

        $previous = Debugger::$logDirectory;
        try {
            // Booting is what hands Tracy the rule; the page is then logged
            // the way an exception on staging without its debugger is.
            Boot::coreAlone();

            Debugger::$logDirectory = $directory;
            Debugger::log(new RuntimeException('Thrown for the test.'), ILogger::EXCEPTION);

            $written = '';
            foreach (Finder::findFiles('*.html', '*.md')->in($directory) as $file) {
                $written .= FileSystem::read($file->getPathname());
            }
        } finally {
            Debugger::$logDirectory = $previous;
            [$environment, $server] = $before;
            putenv($environment === false ? DebugGate::VARIABLE : DebugGate::VARIABLE . '=' . $environment);
            $_SERVER = $server;
            FileSystem::delete($directory);
        }

        $this->assertListedAndHidden($written, [DebugGate::VARIABLE, self::HEADER]);
    }

    /**
     * The page a developer holding the cookie is shown on staging when
     * something throws, which is the one page that has the request's cookies
     * on it.
     */
    public function testThePageShownOnStagingShowsTheCookieAndTheHeaderAndNotTheSecret(): void
    {
        $response = WebServer::request(
            ['TRILOBIT_ENV' => 'staging', DebugGate::VARIABLE => self::VALUE],
            '/',
            ['Cookie: ' . DebugGate::COOKIE . '=' . self::VALUE],
            $this->bootThatThenThrows(),
        );

        self::assertSame(500, $response['status']);
        self::assertTrue(str_contains($response['body'], 'Thrown for the test.'), 'the response is not the error page');
        $this->assertListedAndHidden($response['body'], [DebugGate::COOKIE, self::HEADER]);
    }

    /** @param list<string> $names */
    private function assertListedAndHidden(string $page, array $names): void
    {
        foreach ($names as $name) {
            self::assertTrue(str_contains($page, $name), sprintf('the page does not list %s at all', $name));
        }
        self::assertFalse(str_contains($page, self::VALUE), 'the page shows the debug secret');
    }

    /**
     * A file PHP runs before www/index.php that boots the application as
     * www/index.php would and then throws, so that the error page is Tracy's
     * rather than whatever the application would have made of a request with
     * no tenant and perhaps no database behind it.
     */
    private function bootThatThenThrows(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'trilobit-boot-');
        self::assertIsString($file);

        FileSystem::write($file, sprintf(
            <<<'PHP'
                <?php

                declare(strict_types=1);

                require %s;

                \Trilobit\Core\Bootstrap::configurator();

                throw new \RuntimeException('Thrown for the test.');

                PHP,
            var_export(Bootstrap::rootDirectory() . '/vendor/autoload.php', true),
        ));

        return $file;
    }
}

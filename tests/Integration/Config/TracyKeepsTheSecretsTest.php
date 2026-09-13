<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Config;

use Doctrine\DBAL\Connection;
use Nette\Bridges\DITracy\ContainerPanel;
use Nette\Utils\FileSystem;
use Nette\Utils\Finder;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tracy\Debugger;
use Tracy\Dumper;
use Tracy\Dumper\Value;
use Tracy\ILogger;
use Trilobit\Core\Config\Environment;
use Trilobit\Tests\Boot;

/**
 * What Tracy writes out, read back and searched for a secret the environment
 * was given: the error page as it is logged in every mode - the same page the
 * browser gets in debug mode - and the container panel of the debug bar.
 *
 * It asserts on output rather than on configuration, because configuration is
 * where this went wrong before: Tracy's own list of names to hide was in place
 * all along, and it hides a value only under a name that matches one of its
 * entries exactly, which TRILOBIT_DB_PASSWORD does not.
 *
 * The values are made up and set by the test, overriding whatever the machine
 * has, so that nothing real is rendered. Even so no assertion here hands the
 * rendered output to PHPUnit as a haystack: a failing string assertion prints
 * the haystack whole, and the output is a page listing the environment of the
 * machine the suite runs on.
 */
#[CoversNothing]
final class TracyKeepsTheSecretsTest extends TestCase
{
    private const string DATABASE = 'TRILOBIT_DB_PASSWORD';

    /**
     * A name nobody has written yet, standing in for the next one somebody
     * adds. Hiding it is the rule's job; a list of names would let it through.
     */
    private const string FUTURE = 'TRILOBIT_SOMETHING_TOKEN';

    private const array VALUES = [
        self::DATABASE => 'not-a-real-secret-4f1c9e27',
        self::FUTURE => 'made-up-value-8b27d05a',
    ];

    /** @var array<string, array{string|false, mixed, bool}> */
    private array $before = [];

    private string $directory = '';

    /** @var array{0?: array<Value>, 1?: array<mixed>} */
    private array $liveSnapshot = [];

    /** @return iterable<string, array{string}> */
    public static function secrets(): iterable
    {
        yield 'the database password' => [self::DATABASE];
        yield 'a secret under a name nobody listed' => [self::FUTURE];
    }

    protected function setUp(): void
    {
        foreach (self::VALUES as $name => $value) {
            $this->before[$name] = [getenv($name), $_SERVER[$name] ?? null, array_key_exists($name, $_SERVER)];
            // Both places a container puts its environment: the process
            // environment, which is what getenv() and so the application read,
            // and $_SERVER, which is what the error page lists.
            putenv($name . '=' . $value);
            $_SERVER[$name] = $value;
        }

        $this->directory = sys_get_temp_dir() . '/trilobit-tracy-' . bin2hex(random_bytes(6));
        FileSystem::createDir($this->directory);
        $this->liveSnapshot = Dumper::$liveSnapshot;
    }

    protected function tearDown(): void
    {
        foreach ($this->before as $name => [$environment, $server, $wasSet]) {
            putenv($environment === false ? $name : $name . '=' . $environment);
            if ($wasSet) {
                $_SERVER[$name] = $server;
            } else {
                unset($_SERVER[$name]);
            }
        }

        FileSystem::delete($this->directory);
        Dumper::$liveSnapshot = $this->liveSnapshot;
    }

    /**
     * The page Tracy logs for an exception, which is the page a browser is
     * shown in debug mode. The exception is thrown from a frame holding the
     * environment and the database connection, so the page dumps both, beside
     * $_SERVER.
     */
    #[DataProvider('secrets')]
    public function testTheLoggedErrorPageCarriesNoSecret(string $name): void
    {
        $container = Boot::container();
        $exception = $this->caught($container->getByType(Environment::class), $container->getByType(Connection::class));

        $previous = Debugger::$logDirectory;
        Debugger::$logDirectory = $this->directory;
        try {
            Debugger::log($exception, ILogger::EXCEPTION);
        } finally {
            Debugger::$logDirectory = $previous;
        }

        $written = '';
        foreach (Finder::findFiles('*.html', '*.md')->in($this->directory) as $file) {
            $written .= FileSystem::read($file->getPathname());
        }

        // That the name is there is what says the value was rendered at all:
        // without it an empty file, or a page that never listed the
        // environment, would pass as a page that hid it.
        self::assertTrue(str_contains($written, $name), sprintf('the logged page does not list %s at all', $name));
        self::assertFalse(str_contains($written, self::VALUES[$name]), sprintf('the logged page shows the value of %s', $name));
    }

    /**
     * The debug bar's container panel: the parameters, printed inline, and
     * every service the request created, dumped lazily - the values of those
     * go to the browser as the bar's live data rather than in the panel's own
     * HTML, so both are searched.
     */
    #[DataProvider('secrets')]
    public function testTheContainerPanelCarriesNoSecret(string $name): void
    {
        $container = Boot::container();
        $container->getByType(Environment::class);
        $container->getByType(Connection::class);

        Dumper::$liveSnapshot = [];
        $shown = new ContainerPanel($container)->getPanel() . json_encode($this->liveData(), JSON_THROW_ON_ERROR);

        // The environment service lists the name, so its absence would mean
        // the panel never dumped the one object that holds every value.
        self::assertTrue(str_contains($shown, $name), sprintf('the container panel does not list %s at all', $name));
        self::assertFalse(str_contains($shown, self::VALUES[$name]), sprintf('the container panel shows the value of %s', $name));
    }

    /**
     * What the bar would send to the browser for the lazy dumps rendered so
     * far - read the way Tracy's own bar template reads it.
     *
     * @return array<Value>
     */
    private function liveData(): array
    {
        return Dumper::$liveSnapshot[0] ?? [];
    }

    private function caught(Environment $environment, Connection $connection): RuntimeException
    {
        try {
            $this->throwWhileHolding($environment, $connection);
        } catch (RuntimeException $exception) {
            return $exception;
        }
    }

    private function throwWhileHolding(Environment $environment, Connection $connection): never
    {
        throw new RuntimeException(sprintf('Thrown with %s and %s in hand.', $environment::class, $connection::class));
    }
}

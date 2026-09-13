<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Config;

use Doctrine\DBAL\Connection;
use Nette\Bridges\DITracy\ContainerPanel;
use Nette\DI\Container;
use Nette\DI\Definitions\Statement;
use Nette\Utils\FileSystem;
use Nette\Utils\Finder;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Tracy\Debugger;
use Tracy\Dumper;
use Tracy\Dumper\Value;
use Tracy\ILogger;
use Trilobit\Core\Config\Environment;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Double\Config\HoldsASecret;

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
 * Every test first establishes that the secret really reached what Tracy
 * printed - otherwise a page that never carried it would pass as a page that
 * hid it. That is established from something of a known size: the machine's
 * environment is not, and Tracy shows a hundred entries of an array and cuts
 * the rest, so on a build server with more variables than that a name added at
 * the end never reaches the page at all.
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
     * $_SERVER - which the page lists row by row and never cuts short, so the
     * name being there is a fair sign the value was rendered.
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

        self::assertTrue(str_contains($written, $name), sprintf('the logged page does not list %s at all', $name));
        self::assertFalse(str_contains($written, self::VALUES[$name]), sprintf('the logged page shows the value of %s', $name));
    }

    /**
     * The debug bar's container panel and the database password, which the
     * application keeps in the connection once it has it.
     *
     * The connection is built by a factory, and Nette makes lazy only a
     * service it constructs itself, so what the panel dumps is always the
     * built connection. The environment service is dumped beside it; that it
     * shows no value at all is Trilobit\Tests\Unit\Core\Config\EnvironmentTest's
     * claim, and here it is only searched with the rest of the panel.
     */
    public function testTheContainerPanelDoesNotShowTheDatabasePassword(): void
    {
        $container = Boot::container();
        $container->getByType(Environment::class);
        $connection = $container->getByType(Connection::class);

        self::assertTrue(
            ($connection->getParams()['password'] ?? null) === self::VALUES[self::DATABASE],
            'the connection should hold the made-up password, or the panel is not given it to show',
        );

        $shown = $this->containerPanel($container);

        self::assertTrue($this->mentions($shown, $connection::class), 'the container panel does not dump the connection');
        self::assertTrue($this->mentions($shown, Environment::class), 'the container panel does not dump the environment');
        self::assertFalse(str_contains($shown, self::VALUES[self::DATABASE]), 'the container panel shows the database password');
    }

    /**
     * The debug bar's container panel and a secret a service is handed by
     * configuration, in both states it is in while a request runs: handed out
     * and not yet used - which, with lazy services, is an object whose
     * constructor has not run - and used.
     *
     * Dumping the unbuilt service must not build it either: that would run
     * its constructor from inside the debug bar and put the secret into the
     * very object the panel is printing.
     */
    public function testTheContainerPanelDoesNotShowASecretAServiceHoldsBuiltOrNot(): void
    {
        $container = Boot::container(config: [
            'services' => [
                'holdsASecret' => [
                    'create' => HoldsASecret::class,
                    'arguments' => [new Statement(['@core.environment', 'value'], [self::FUTURE])],
                ],
            ],
        ]);
        $service = $container->getByType(HoldsASecret::class);
        $reflection = new ReflectionClass($service);
        $secret = self::VALUES[self::FUTURE];

        self::assertTrue($reflection->isUninitializedLazyObject($service), 'the container should hand the service out unbuilt');

        $shown = $this->containerPanel($container);

        self::assertTrue($this->mentions($shown, HoldsASecret::class . ' (lazy)'), 'the container panel does not dump the unbuilt service');
        self::assertTrue($reflection->isUninitializedLazyObject($service), 'dumping the unbuilt service built it');
        self::assertFalse(str_contains($shown, $secret), 'the container panel shows the secret of an unbuilt service');

        self::assertTrue($service->apiToken() === $secret, 'the built service should hold the made-up secret');

        $shown = $this->containerPanel($container);

        self::assertTrue(str_contains($shown, 'apiToken'), 'the container panel does not list what the built service holds');
        self::assertFalse(str_contains($shown, $secret), 'the container panel shows the secret of a built service');
    }

    /**
     * The panel's own HTML followed by what the bar would send the browser
     * for its lazy dumps - read the way Tracy's own bar template reads it.
     */
    private function containerPanel(Container $container): string
    {
        Dumper::$liveSnapshot = [];
        $html = new ContainerPanel($container)->getPanel();

        return $html . json_encode($this->liveData(), JSON_THROW_ON_ERROR);
    }

    /** @return array<Value> */
    private function liveData(): array
    {
        return Dumper::$liveSnapshot[0] ?? [];
    }

    /** Whether $text is in the panel, as HTML prints it or as JSON escapes it. */
    private function mentions(string $shown, string $text): bool
    {
        return str_contains($shown, $text) || str_contains($shown, substr(json_encode($text, JSON_THROW_ON_ERROR), 1, -1));
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

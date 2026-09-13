<?php

declare(strict_types=1);

namespace Trilobit\Tests\Tooling;

use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * bin/check-floor, up to the point where it would need Docker.
 *
 * The part worth testing without a container is the one that decides which PHP
 * the gate runs on. A misread floor would not show: the gate would run, go
 * green on some other version, and be taken for the floor. So the reading is
 * tried from outside, on the script itself, copied next to a composer.json of
 * each shape - and the shapes it does not understand have to be refused out
 * loud rather than read as something.
 *
 * The rest of what the script does is building an image and starting a
 * container, which is what running it proves.
 */
#[CoversNothing]
final class CheckFloorTest extends TestCase
{
    private ?string $sandbox = null;

    protected function tearDown(): void
    {
        if ($this->sandbox !== null) {
            FileSystem::delete($this->sandbox);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function floors(): iterable
    {
        yield 'at least' => ['>=8.4', '8.4'];
        yield 'at least, with a patch' => ['>=8.4.1', '8.4'];
        yield 'caret' => ['^8.5', '8.5'];
        yield 'tilde, with a patch' => ['~8.4.2', '8.4'];
        yield 'a later major' => ['>=9.0', '9.0'];
    }

    #[DataProvider('floors')]
    public function testTheFloorIsTheLowerBoundOfRequirePhp(string $constraint, string $floor): void
    {
        [$code, $out, $err] = $this->printFloor(['require' => ['php' => $constraint]]);

        self::assertSame(0, $code, $err);
        self::assertSame($floor . "\n", $out);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shapesWithoutASingleFloor(): iterable
    {
        yield 'exact version' => ['8.4'];
        yield 'wildcard' => ['8.4.*'];
        yield 'bounded range' => ['>=8.4 <9.0'];
        yield 'alternatives' => ['^8.4 || ^9.0'];
        yield 'no minor' => ['>=8'];
    }

    #[DataProvider('shapesWithoutASingleFloor')]
    public function testAShapeItDoesNotUnderstandIsRefusedOutLoud(string $constraint): void
    {
        [$code, $out, $err] = $this->printFloor(['require' => ['php' => $constraint]]);

        self::assertSame(125, $code);
        self::assertSame('', $out);
        self::assertStringContainsString("require.php '{$constraint}'", $err);
    }

    public function testAComposerJsonWithoutRequirePhpIsRefused(): void
    {
        [$code, $out, $err] = $this->printFloor(['require' => ['nette/utils' => '^4.1']]);

        self::assertSame(125, $code);
        self::assertSame('', $out);
        self::assertStringContainsString('has no require.php', $err);
    }

    /**
     * The floor the script finds in this repository is the one phpstan.neon
     * analyses for. PhpVersionMatchesComposerTest ties that number to
     * require.php already, so this closes the triangle: the gate runs on the
     * version the analysis assumes.
     */
    public function testThisRepositorysFloorIsTheOnePhpstanAnalysesFor(): void
    {
        [$code, $out, $err] = $this->execute([PHP_BINARY, $this->root() . '/bin/check-floor', '--print-floor']);
        self::assertSame(0, $code, $err);

        if (preg_match('/^\s*phpVersion:\s*(\d+)\s*$/m', FileSystem::read($this->root() . '/phpstan.neon'), $pin) !== 1) {
            self::fail('phpstan.neon has no phpVersion; PhpVersionMatchesComposerTest says why it must.');
        }
        $pinned = intdiv((int) $pin[1], 10000) . '.' . intdiv((int) $pin[1] % 10000, 100);

        self::assertSame($pinned . "\n", $out);
    }

    /**
     * The floor's container reaches the database compose.yaml starts, with the
     * account that database was created with. The two files spell that account
     * with the same expressions - the same variable, the same default - and if
     * one of them changes alone, the gate on the floor stops at a refused login.
     */
    public function testTheFloorConnectsAsTheAccountTheDatabaseWasCreatedWith(): void
    {
        $database = $this->environmentOf('compose.yaml', 'database');
        $floor = $this->environmentOf('docker/php-floor/compose.yaml', 'floor');

        self::assertSame($database['MARIADB_DATABASE'] ?? null, $floor['TRILOBIT_DB_NAME'] ?? null);
        self::assertSame($database['MARIADB_USER'] ?? null, $floor['TRILOBIT_DB_USER'] ?? null);
        self::assertSame($database['MARIADB_PASSWORD'] ?? null, $floor['TRILOBIT_DB_PASSWORD'] ?? null);
    }

    /**
     * @param array<string, mixed> $composer
     * @return array{0: int, 1: string, 2: string}
     */
    private function printFloor(array $composer): array
    {
        $this->sandbox = sys_get_temp_dir() . '/trilobit-check-floor-' . bin2hex(random_bytes(6));
        FileSystem::copy($this->root() . '/bin/check-floor', $this->sandbox . '/bin/check-floor');
        FileSystem::write($this->sandbox . '/composer.json', Json::encode($composer));

        return $this->execute([PHP_BINARY, $this->sandbox . '/bin/check-floor', '--print-floor']);
    }

    /**
     * @return array<string, string>
     */
    private function environmentOf(string $file, string $service): array
    {
        $compose = Yaml::parseFile($this->root() . '/' . $file);
        self::assertIsArray($compose);
        self::assertIsArray($compose['services'] ?? null);
        self::assertIsArray($compose['services'][$service] ?? null);
        $environment = $compose['services'][$service]['environment'] ?? null;
        self::assertIsArray($environment, "{$file} gives {$service} no environment map.");

        $strings = [];
        foreach ($environment as $name => $value) {
            self::assertIsString($name);
            self::assertIsString($value);
            $strings[$name] = $value;
        }

        return $strings;
    }

    /**
     * @param list<string> $command
     * @return array{0: int, 1: string, 2: string}
     */
    private function execute(array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertIsString($out);
        self::assertIsString($err);

        return [proc_close($process), $out, $err];
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}

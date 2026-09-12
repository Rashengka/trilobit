<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use PHPUnit\Framework\TestCase;

/**
 * `phpstan.neon` pins `phpVersion` to the floor of `composer.json`'s
 * `require.php` on purpose, so that analysis reports what the lowest
 * supported PHP cannot do - not what the container the analyser happens to
 * run on cannot do. That guarantee only holds as long as the two numbers
 * move together, so this test fails the moment somebody raises the floor in
 * one file and forgets the other.
 */
final class PhpVersionMatchesComposerTest extends TestCase
{
    public function testPhpstanIsPinnedToTheFloorOfRequirePhp(): void
    {
        $composer = Json::decode(FileSystem::read($this->root() . '/composer.json'), forceArrays: true);
        self::assertIsArray($composer);
        self::assertArrayHasKey('require', $composer);
        self::assertIsArray($composer['require']);
        self::assertArrayHasKey('php', $composer['require']);
        $constraint = $composer['require']['php'];
        self::assertIsString($constraint);

        self::assertMatchesRegularExpression(
            '/^>=\d+\.\d+$/',
            $constraint,
            "This test only knows how to read a floor of the exact shape '>=X.Y'. "
                . "composer.json's require.php is now '{$constraint}' - update this test's "
                . 'parsing (and phpstan.neon\'s comment) to match, rather than deleting the check.',
        );

        if (preg_match('/^>=(\d+)\.(\d+)$/', $constraint, $floor) !== 1) {
            self::fail("Could not parse the floor out of require.php '{$constraint}'.");
        }
        $expectedPhpVersion = ((int) $floor[1]) * 10000 + ((int) $floor[2]) * 100;

        $configuration = FileSystem::read($this->root() . '/phpstan.neon');
        self::assertMatchesRegularExpression(
            '/^\s*phpVersion:\s*\d+\s*$/m',
            $configuration,
            'phpstan.neon has no phpVersion parameter any more - without it, analysis silently '
                . 'follows whatever PHP the analyser runs on instead of the floor this project '
                . 'promises to support.',
        );
        if (preg_match('/^\s*phpVersion:\s*(\d+)\s*$/m', $configuration, $match) !== 1) {
            self::fail('phpVersion matched the assertion above but not this identical parse - that should be impossible.');
        }
        $actualPhpVersion = (int) $match[1];

        self::assertSame(
            $expectedPhpVersion,
            $actualPhpVersion,
            "phpstan.neon's phpVersion ({$actualPhpVersion}) no longer matches the floor of "
                . "composer.json's require.php ('{$constraint}', which is {$expectedPhpVersion}). "
                . 'Change both together.',
        );
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}

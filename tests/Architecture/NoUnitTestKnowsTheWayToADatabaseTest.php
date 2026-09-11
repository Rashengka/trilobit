<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use FilesystemIterator;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Trilobit\Core\Bootstrap;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * Nothing under tests/Unit may so much as name a way to a database.
 *
 * The runner already turns a unit test away at the two doors the suite has -
 * see Trilobit\Tests\Runner\KeepingUnitTestsAwayFromTheDatabase - and that
 * guard is the one that fires immediately and names the test. This is the other
 * half, and the halves cover different things on purpose. A guard on a door
 * cannot see a road that goes around it: a unit test calling
 * Doctrine\DBAL\DriverManager itself, or opening a PDO of its own, would reach
 * a real server without passing either door. Reading the files catches that,
 * and catches it before the test is ever run.
 *
 * **What is looked for is the way in, never Doctrine.** A unit test stands in
 * for what it does not have, and standing in for an entity manager means naming
 * Doctrine\ORM\EntityManagerInterface in a stub - three unit tests do, and they
 * are exactly right to. A stub connects to nothing. So the list below is
 * things that open connections and the two test helpers that hand out
 * connected things, and it deliberately does not contain the word "Doctrine".
 *
 * **The list is the loud side of the split**, which is the shape
 * Trilobit\Tests\Database argues for: a name missing from it costs a road this
 * test cannot see, and the door guard is what stands behind that. A name
 * wrongly in it fails at once and is noticed in a minute.
 */
final class NoUnitTestKnowsTheWayToADatabaseTest extends TestCase
{
    /**
     * Every spelling that gets a test to a real server, as it appears in PHP
     * source - the class names in a use statement or written out in full, and
     * the two helpers of this suite that hand out something already connected.
     *
     * @var list<string>
     */
    private const array WAYS_TO_A_DATABASE = [
        DriverManager::class,
        Connection::class,
        Boot::class,
        Database::class,
        Migrations::class,
        Tenants::class,
        'PDO',
        'mysqli',
    ];

    public function testNoUnitTestNamesOne(): void
    {
        self::assertSame([], $this->waysNamedUnder(Bootstrap::rootDirectory() . '/tests/Unit'));
    }

    /**
     * The detector against files that do name a way and against one that only
     * mocks an interface, so that a detector matching nothing cannot report
     * agreement it never checked - and so that the difference this test is
     * built on is the difference it actually measures.
     */
    public function testTheDetectorTellsAConnectionFromAStub(): void
    {
        $directory = sys_get_temp_dir() . '/trilobit-unitways-' . bin2hex(random_bytes(6));
        FileSystem::write($directory . '/Stub.php', "<?php\nuse Doctrine\\ORM\\EntityManagerInterface;\n");
        FileSystem::write($directory . '/Helper.php', "<?php\nuse Trilobit\\Tests\\Database;\n");
        FileSystem::write($directory . '/Raw.php', "<?php\n\$pdo = new PDO('mysql:host=nowhere');\n");

        try {
            self::assertSame(['Helper.php', 'Raw.php'], $this->waysNamedUnder($directory));
        } finally {
            FileSystem::delete($directory);
        }
    }

    /**
     * @return list<string> paths relative to $directory, sorted
     */
    private function waysNamedUnder(string $directory): array
    {
        $spellings = [];
        foreach (self::WAYS_TO_A_DATABASE as $way) {
            // Written as it is in source, where a namespace separator is
            // escaped in a double-quoted string and is not in a use statement.
            $spellings[] = preg_quote($way, '#');
            $spellings[] = preg_quote(str_replace('\\', '\\\\', $way), '#');
        }

        $pattern = '#\b(?:' . implode('|', $spellings) . ')\b#';
        $found = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            self::assertInstanceOf(SplFileInfo::class, $file);

            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (preg_match($pattern, FileSystem::read($file->getPathname())) === 1) {
                $found[] = substr($file->getPathname(), strlen($directory) + 1);
            }
        }

        sort($found);

        return $found;
    }
}

<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Config;

use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Tests\WebServer;

/**
 * The web entry point, asked for a page while the environment still carries
 * the retired debug flag and names no mode.
 *
 * The boot refuses that before Tracy is switched on, so nothing but the entry
 * point itself can put the refusal in front of whoever opened the page - and
 * the first person to meet it is somebody who has just pulled the change and
 * opened the application in a browser, not a console.
 *
 * The other half matters as much. Printing whatever the boot threw would be a
 * hole in production, where nothing else is there to decide what a visitor
 * may read, so the second case makes the boot fail with something else - worded
 * like the refusal on purpose - and asks that none of it reaches the body.
 *
 * Both run a real server over the real www/index.php with display_errors off,
 * as a production PHP has it, so whatever is in the body was put there by the
 * entry point and not by PHP.
 */
#[CoversNothing]
final class TheRefusalReachesTheBrowserTest extends TestCase
{
    /** Worded like the refusal, so that only the type of what was thrown can tell the two apart. */
    private const string OTHER_FAILURE = 'TRILOBIT_DEBUG is set and TRILOBIT_ENV is not - said by something that is not the refusal';

    public function testTheRetiredFlagWithoutAModeIsAnsweredAsPlainText(): void
    {
        $response = WebServer::request(['TRILOBIT_DEBUG' => '1', 'TRILOBIT_ENV' => '']);

        self::assertSame(500, $response['status']);
        self::assertSame('text/plain; charset=utf-8', $response['type']);
        self::assertStringStartsWith('TRILOBIT_DEBUG is set and TRILOBIT_ENV is not.', $response['body']);
        self::assertStringContainsString('TRILOBIT_ENV=dev', $response['body']);
        self::assertStringNotContainsString('<', $response['body'], 'the answer is plain text, not a page');
        self::assertStringNotContainsString('.php', $response['body'], 'a stack trace has no business in it');
    }

    public function testAnyOtherFailureOfTheBootStaysOutOfTheBody(): void
    {
        $response = WebServer::request(['TRILOBIT_ENV' => 'dev'], prepend: $this->bootThatFailsOtherwise());

        self::assertSame(500, $response['status']);
        self::assertStringNotContainsString('TRILOBIT', $response['body']);
        self::assertStringNotContainsString('not the refusal', $response['body']);
    }

    /**
     * A file PHP runs before www/index.php that declares the boot class
     * itself, so that the autoloader never loads the real one and the entry
     * point meets a boot that fails for another reason.
     *
     * Written at run time rather than kept among the fixtures, because a
     * second declaration of an application class in the repository would be a
     * second declaration to every analyser that reads it.
     */
    private function bootThatFailsOtherwise(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'trilobit-boot-');
        self::assertIsString($file);

        FileSystem::write($file, sprintf(
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Trilobit\Core;

                final class Bootstrap
                {
                    public static function boot(): never
                    {
                        throw new \RuntimeException(%s);
                    }
                }

                PHP,
            var_export(self::OTHER_FAILURE, true),
        ));

        return $file;
    }
}

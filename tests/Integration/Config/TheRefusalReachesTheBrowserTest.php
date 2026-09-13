<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Config;

use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;

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
        $response = $this->request(['TRILOBIT_DEBUG' => '1', 'TRILOBIT_ENV' => '']);

        self::assertSame(500, $response['status']);
        self::assertSame('text/plain; charset=utf-8', $response['type']);
        self::assertStringStartsWith('TRILOBIT_DEBUG is set and TRILOBIT_ENV is not.', $response['body']);
        self::assertStringContainsString('TRILOBIT_ENV=dev', $response['body']);
        self::assertStringNotContainsString('<', $response['body'], 'the answer is plain text, not a page');
        self::assertStringNotContainsString('.php', $response['body'], 'a stack trace has no business in it');
    }

    public function testAnyOtherFailureOfTheBootStaysOutOfTheBody(): void
    {
        $response = $this->request(['TRILOBIT_ENV' => 'dev'], $this->bootThatFailsOtherwise());

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

    /**
     * One request for / to a server started for it, with $environment laid
     * over the process's own.
     *
     * The values are handed over by env(1) rather than by proc_open()'s own
     * environment argument, because proc_open() drops a variable whose value
     * is empty - and an empty TRILOBIT_ENV is exactly what has to reach the
     * server here, to stand over whatever mode the checkout's .env names.
     *
     * @param array<string, string> $environment
     *
     * @return array{status: int, type: string, body: string}
     */
    private function request(array $environment, ?string $prepend = null): array
    {
        $port = $this->freePort();

        $command = [
            'env',
            ...array_map(
                static fn(string $name, string $value): string => $name . '=' . $value,
                array_keys($environment),
                $environment,
            ),
            PHP_BINARY,
            '-d', 'display_errors=0',
            '-d', 'log_errors=0',
            ...($prepend === null ? [] : ['-d', 'auto_prepend_file=' . $prepend]),
            '-S', '127.0.0.1:' . $port,
            '-t', Bootstrap::rootDirectory() . '/www',
        ];

        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            Bootstrap::rootDirectory(),
        );
        self::assertIsResource($process);

        try {
            $this->waitUntilItListens($port);

            $body = file_get_contents(
                'http://127.0.0.1:' . $port . '/',
                false,
                stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 30]]),
            );
            self::assertIsString($body, 'the server gave no answer at all');

            return ['body' => $body, ...$this->statusAndType(http_get_last_response_headers() ?? [])];
        } finally {
            proc_terminate($process);
            proc_close($process);
            if ($prepend !== null) {
                FileSystem::delete($prepend);
            }
        }
    }

    /**
     * @param list<string> $headers
     *
     * @return array{status: int, type: string}
     */
    private function statusAndType(array $headers): array
    {
        $status = 0;
        $type = '';
        foreach ($headers as $header) {
            if (preg_match('~^HTTP/\S+ (\d{3})~', $header, $match) === 1) {
                $status = (int) $match[1];
            } elseif (stripos($header, 'Content-Type:') === 0) {
                $type = trim(substr($header, strlen('Content-Type:')));
            }
        }

        return ['status' => $status, 'type' => $type];
    }

    private function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);
        $name = stream_socket_get_name($server, false);
        fclose($server);
        self::assertIsString($name);

        $port = parse_url('tcp://' . $name, PHP_URL_PORT);
        self::assertIsInt($port, sprintf('%s names no port', $name));

        return $port;
    }

    private function waitUntilItListens(int $port): void
    {
        $deadline = microtime(true) + 10;

        // A refused connection is a warning, and here it is the expected
        // answer until the server is up, so it is not left to reach the runner.
        set_error_handler(static fn(): bool => true);
        try {
            do {
                $socket = stream_socket_client('tcp://127.0.0.1:' . $port, timeout: 0.2);
                if ($socket !== false) {
                    fclose($socket);

                    return;
                }
                usleep(50_000);
            } while (microtime(true) < $deadline);
        } finally {
            restore_error_handler();
        }

        self::fail(sprintf('the server on port %d did not start listening within ten seconds', $port));
    }
}

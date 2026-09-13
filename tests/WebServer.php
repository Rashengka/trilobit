<?php

declare(strict_types=1);

namespace Trilobit\Tests;

use Nette\Utils\FileSystem;
use PHPUnit\Framework\Assert;
use Trilobit\Core\Bootstrap;

/**
 * One request to the real www/index.php, served by PHP's own server started
 * for it and stopped after it.
 *
 * It is for the questions only the entry point can answer - what reaches the
 * browser before, or instead of, anything the application renders - and it
 * runs with display_errors off, as a production PHP has it, so whatever is in
 * the body was put there by the entry point and not by PHP.
 */
final class WebServer
{
    /**
     * @param array<string, string> $environment laid over the process's own.
     *     It is handed over by env(1) rather than by proc_open()'s own
     *     environment argument, because proc_open() drops a variable whose
     *     value is empty - and an empty value is sometimes exactly what has to
     *     reach the server, to stand over whatever the checkout's .env names.
     * @param list<string> $headers request headers, each as "Name: value"
     * @param string|null $prepend a file PHP runs before www/index.php, which
     *     is deleted once the request is answered
     *
     * @return array{status: int, type: string, body: string}
     */
    public static function request(
        array $environment,
        string $path = '/',
        array $headers = [],
        ?string $prepend = null,
    ): array {
        $port = self::freePort();

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
        Assert::assertIsResource($process);

        try {
            self::waitUntilItListens($port);

            $body = file_get_contents(
                'http://127.0.0.1:' . $port . $path,
                false,
                stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 30, 'header' => $headers]]),
            );
            Assert::assertIsString($body, 'the server gave no answer at all');

            return ['body' => $body, ...self::statusAndType(http_get_last_response_headers() ?? [])];
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
    private static function statusAndType(array $headers): array
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

    private static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        Assert::assertIsResource($server);
        $name = stream_socket_get_name($server, false);
        fclose($server);
        Assert::assertIsString($name);

        $port = parse_url('tcp://' . $name, PHP_URL_PORT);
        Assert::assertIsInt($port, sprintf('%s names no port', $name));

        return $port;
    }

    private static function waitUntilItListens(int $port): void
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

        Assert::fail(sprintf('the server on port %d did not start listening within ten seconds', $port));
    }
}

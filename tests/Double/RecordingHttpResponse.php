<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double;

use DateTimeInterface;
use Nette\Http\IResponse;

/**
 * The response, as something a suite can read back.
 *
 * Nette's own writes a cookie by calling header(), which under a command-line
 * runner goes nowhere and is remembered by nothing - so a claim about what a
 * visitor's browser was told to keep could not be made at all. Everything here
 * is held in the object instead.
 *
 * It replaces the framework's http.response service, which is why it carries
 * the three public properties Nette\Http\Session binds by reference and why
 * setCookie() accepts a sameSite argument the interface does not declare:
 * Nette\Http\Helpers::initCookie() passes one by name. Both are the framework
 * reaching past IResponse to the class it usually finds there.
 */
final class RecordingHttpResponse implements IResponse
{
    public string $cookieDomain = '';

    public string $cookiePath = '/';

    public bool $cookieSecure = false;

    private int $code = self::S200_OK;

    /** @var array<string, string> */
    private array $headers = [];

    /** @var list<array{name: string, value: string, expire: string|int|DateTimeInterface|null, httpOnly: bool}> */
    private array $cookies = [];

    public function setCode(int $code, ?string $reason = null): static
    {
        $this->code = $code;

        return $this;
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public function setHeader(string $name, string $value): static
    {
        $this->headers[strtolower($name)] = $value;

        return $this;
    }

    public function addHeader(string $name, string $value): static
    {
        return $this->setHeader($name, $value);
    }

    public function setContentType(string $type, ?string $charset = null): static
    {
        return $this->setHeader('Content-Type', $charset === null ? $type : $type . '; charset=' . $charset);
    }

    public function redirect(string $url, int $code = self::S302_Found): void
    {
        $this->setCode($code);
        $this->setHeader('Location', $url);
    }

    public function setExpiration(?string $expire): static
    {
        return $this;
    }

    public function isSent(): bool
    {
        return false;
    }

    public function getHeader(string $header): ?string
    {
        return $this->headers[strtolower($header)] ?? null;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function setCookie(
        string $name,
        string $value,
        string|int|DateTimeInterface|null $expire,
        ?string $path = null,
        ?string $domain = null,
        ?bool $secure = null,
        ?bool $httpOnly = null,
        mixed $sameSite = null,
    ): static {
        $this->cookies[] = [
            'name' => $name,
            'value' => $value,
            'expire' => $expire,
            'httpOnly' => $httpOnly ?? true,
        ];

        return $this;
    }

    public function deleteCookie(string $name, ?string $path = null, ?string $domain = null, ?bool $secure = null): void
    {
        // The same shape Nette's own gives it, so that a suite asking whether a
        // cookie was touched sees a deletion as the write it is.
        $this->setCookie($name, '', -1, $path, $domain, $secure);
    }

    /** How many times a cookie of this name was written, deletions included. */
    public function timesWritten(string $name): int
    {
        return count(array_filter($this->cookies, static fn(array $cookie): bool => $cookie['name'] === $name));
    }

    /** What the browser was last told to keep under this name, or null if it was never told. */
    public function cookie(string $name): ?string
    {
        for ($index = count($this->cookies) - 1; $index >= 0; $index--) {
            if ($this->cookies[$index]['name'] === $name) {
                return $this->cookies[$index]['value'];
            }
        }

        return null;
    }

    /** Whether the last write of this cookie was one no browser is meant to keep. */
    public function wasDeleted(string $name): bool
    {
        for ($index = count($this->cookies) - 1; $index >= 0; $index--) {
            if ($this->cookies[$index]['name'] !== $name) {
                continue;
            }

            $expire = $this->cookies[$index]['expire'];

            return $this->cookies[$index]['value'] === '' || (is_int($expire) && $expire < 0);
        }

        return false;
    }

    /** Whether the browser was told to keep this one away from scripts. */
    public function isHttpOnly(string $name): bool
    {
        for ($index = count($this->cookies) - 1; $index >= 0; $index--) {
            if ($this->cookies[$index]['name'] === $name) {
                return $this->cookies[$index]['httpOnly'];
            }
        }

        return false;
    }
}

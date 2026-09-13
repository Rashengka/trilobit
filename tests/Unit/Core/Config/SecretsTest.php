<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use stdClass;
use Trilobit\Core\Config\Secrets;

/**
 * The rule deciding what Tracy hides. The names on the secret side are mostly
 * ones nobody has written into this application, because that is the claim:
 * the next setting is covered without being listed.
 */
#[CoversClass(Secrets::class)]
final class SecretsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function secretNames(): iterable
    {
        $names = [
            'TRILOBIT_DB_PASSWORD',
            'TRILOBIT_MAILER_PASSWORD',
            'TRILOBIT_SOMETHING_TOKEN',
            'TRILOBIT_PAYMENT_SECRET',
            'TRILOBIT_API_KEY',
            'APP_KEY',
            'apiToken',
            'privateKey',
            'newPassword',
            'password2',
            'password_confirmation',
            '_token_',
            'client-secret',
            'credentials',
            'PHP_AUTH_PW',
            'HTTP_AUTHORIZATION',
            'HTTP_COOKIE',
            'cookies',
            'PHPSESSID',
            'sessionId',
            'SENTRY_DSN',
            // Tracy's own defaults, which the rule has to keep hiding.
            'pass',
            'pwd',
            'credit card',
            'cc',
            'pin',
        ];

        foreach ($names as $name) {
            yield $name => [$name];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function ordinaryNames(): iterable
    {
        $names = [
            'TRILOBIT_DB_HOST',
            'TRILOBIT_DB_USER',
            'TRILOBIT_EDITOR_ROOT',
            'HTTP_HOST',
            'REQUEST_URI',
            'PATH',
            // A word containing a secret word is not that word.
            'author',
            'authorId',
            'keyword',
            'monkey',
            'passenger',
            'compass',
            'tokenizer',
            // On its own the commonest argument name there is.
            'key',
            'keys',
        ];

        foreach ($names as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('secretNames')]
    public function testASecretNameIsSecret(string $name): void
    {
        self::assertTrue(Secrets::isSecretName($name));
        self::assertTrue(Secrets::isSecret($name, 'anything'));
    }

    #[DataProvider('ordinaryNames')]
    public function testAnOrdinaryNameIsNot(string $name): void
    {
        self::assertFalse(Secrets::isSecretName($name));
        self::assertFalse(Secrets::isSecret($name, 'anything'));
    }

    /**
     * Its properties are asked one by one instead; hiding the object would
     * hide everything in it that is not secret.
     */
    public function testAnObjectIsNeverHiddenByItsName(): void
    {
        self::assertFalse(Secrets::isSecret('token', new stdClass()));
    }

    public function testAnArrayUnderASecretNameIsHiddenWhole(): void
    {
        self::assertTrue(Secrets::isSecret('credentials', ['user' => 'someone']));
    }

    public function testAUrlCarryingAPasswordIsSecretUnderAnyName(): void
    {
        // Put together from its parts, so that no line of this file is itself
        // a URL carrying a password for a leak check to stop at.
        $url = 'mysql' . '://' . 'someone' . ':' . 'made-up' . '@' . 'db.example.com/shop';

        self::assertTrue(Secrets::isSecretValue($url));
        self::assertTrue(Secrets::isSecret('TRILOBIT_DB_URL', $url));
    }

    public function testAUrlWithoutAPasswordIsNot(): void
    {
        self::assertFalse(Secrets::isSecretValue('https://www.example.com/path?query=1'));
        self::assertFalse(Secrets::isSecretValue('mailto:someone@example.com'));
        self::assertFalse(Secrets::isSecret('HTTP_REFERER', 'https://www.example.com/'));
    }

    /**
     * The session cookie can be called anything; its value is the session's
     * identifier whatever it is called. A separate process, because the
     * identifier is the process's own and would outlive the test otherwise.
     */
    #[RunInSeparateProcess]
    public function testTheSessionIdentifierIsSecretUnderAnyName(): void
    {
        session_id('madeupsessionidentifier1234');

        self::assertTrue(Secrets::isSecret('a_name_nobody_chose', 'madeupsessionidentifier1234'));
        self::assertFalse(Secrets::isSecret('a_name_nobody_chose', 'another-value'));
    }

    public function testWithoutASessionNoValueIsTakenForItsIdentifier(): void
    {
        self::assertSame('', session_id(), 'the suite should run with no session');
        self::assertFalse(Secrets::isSecretValue(''));
        self::assertFalse(Secrets::isSecretValue('an-ordinary-value'));
    }
}

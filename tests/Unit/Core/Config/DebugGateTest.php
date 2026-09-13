<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Trilobit\Core\Config\DebugGate;
use Trilobit\Core\Config\Environment;

#[CoversClass(DebugGate::class)]
final class DebugGateTest extends TestCase
{
    /**
     * Made up for this test and long enough to count; no deployment has it.
     * Put together rather than written out, so that it reads as what it is
     * and not as a key somebody pasted in.
     */
    private static function invented(): string
    {
        return str_repeat('made-up-', 5);
    }

    public function testTheRightCookieOpensTheGate(): void
    {
        self::assertTrue($this->gate(self::invented(), [DebugGate::COOKIE => self::invented()])->isOpen());
    }

    public function testWithoutTheCookieTheGateStaysShut(): void
    {
        self::assertFalse($this->gate(self::invented(), [])->isOpen());
    }

    /**
     * Without a secret there is nothing a cookie could match, and least of all
     * an empty cookie matching an empty secret - which is what comparing the
     * two as they come would say.
     */
    public function testWithoutASecretTheGateStaysShut(): void
    {
        self::assertFalse(DebugGate::check(Environment::fromValues([]), [DebugGate::COOKIE => ''])->isOpen());
        self::assertFalse($this->gate('', [DebugGate::COOKIE => ''])->isOpen());
        self::assertFalse($this->gate('', [DebugGate::COOKIE => self::invented()])->isOpen());
    }

    /** A secret too short to be one is not taken, even by a cookie that matches it. */
    public function testASecretShorterThanTheShortestKeepsTheGateShut(): void
    {
        $short = str_repeat('s', DebugGate::SHORTEST_SECRET - 1);

        self::assertFalse($this->gate($short, [DebugGate::COOKIE => $short])->isOpen());
    }

    public function testASecretOfExactlyTheShortestLengthIsTaken(): void
    {
        $shortest = str_repeat('s', DebugGate::SHORTEST_SECRET);

        self::assertTrue($this->gate($shortest, [DebugGate::COOKIE => $shortest])->isOpen());
    }

    public function testTheShortestSecretIsThirtyTwoCharacters(): void
    {
        self::assertSame(32, DebugGate::SHORTEST_SECRET);
    }

    #[DataProvider('wrongCookies')]
    public function testAWrongCookieKeepsTheGateShut(string $cookie): void
    {
        self::assertFalse($this->gate(self::invented(), [DebugGate::COOKIE => $cookie])->isOpen());
    }

    /** @return iterable<string, array{string}> */
    public static function wrongCookies(): iterable
    {
        yield 'empty' => [''];
        yield 'the same length, the last character different' => [substr(self::invented(), 0, -1) . 'X'];
        yield 'all of it but the last character' => [substr(self::invented(), 0, -1)];
        yield 'all of it and one more' => [self::invented() . 'e'];
        yield 'another case' => [strtoupper(self::invented())];
        yield 'with a space in front' => [' ' . self::invented()];
    }

    /** A browser may send name[]=value, which PHP reads as an array. */
    public function testACookieThatIsNotAStringKeepsTheGateShut(): void
    {
        self::assertFalse($this->gate(self::invented(), [DebugGate::COOKIE => [self::invented()]])->isOpen());
    }

    /**
     * The cookies the framework reads for its own detection are not this one,
     * so a value under their names opens nothing - whatever else somebody
     * guessing at a Nette application might send.
     */
    public function testTheFrameworksCookiesDoNotOpenTheGate(): void
    {
        $gate = $this->gate(self::invented(), ['nette-debug' => self::invented(), 'tracy-debug' => self::invented()]);

        self::assertFalse($gate->isOpen());
    }

    /**
     * Both names carry the word "secret", which is what a rule hiding values
     * from error pages by the words in their names catches; see the README,
     * under Installation.
     */
    public function testBothNamesSayTheyHoldASecret(): void
    {
        self::assertMatchesRegularExpression('~(^|[-_])secret($|[-_])~i', DebugGate::VARIABLE);
        self::assertMatchesRegularExpression('~(^|[-_])secret($|[-_])~i', DebugGate::COOKIE);
    }

    /**
     * The comparison takes as long for a cookie that is wrong in its first
     * character as for one wrong in its last, so how long an answer took says
     * nothing about how much of a guess was right.
     *
     * That cannot be measured in a unit test with any reliability, so what is
     * pinned instead is the one way of getting it: the class compares with
     * hash_equals() and with no operator that could compare the two strings
     * itself.
     */
    public function testTheCookieIsComparedInConstantTime(): void
    {
        $file = new ReflectionClass(DebugGate::class)->getFileName();
        self::assertIsString($file);
        $source = file_get_contents($file);
        self::assertIsString($source);

        $calls = [];
        $comparisons = [];
        foreach (\PhpToken::tokenize($source) as $token) {
            if ($token->is(T_STRING)) {
                $calls[] = strtolower($token->text);
            }
            if ($token->is([T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_EQUAL, T_IS_NOT_IDENTICAL, T_SPACESHIP])) {
                $comparisons[] = $token->text;
            }
        }

        self::assertContains('hash_equals', $calls);
        self::assertSame([], array_values(array_intersect($calls, ['strcmp', 'strcasecmp', 'strncmp', 'in_array', 'array_search'])));
        self::assertSame([], $comparisons);
    }

    /** @param array<mixed> $cookies */
    private function gate(string $secret, array $cookies): DebugGate
    {
        return DebugGate::check(Environment::fromValues([DebugGate::VARIABLE => $secret]), $cookies);
    }
}

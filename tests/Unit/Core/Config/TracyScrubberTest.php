<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Config;

use ArrayObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tracy\Debugger;
use Tracy\Dumper;
use Trilobit\Core\Config\Secrets;
use Trilobit\Core\Config\TracyScrubber;

/**
 * A dump made with no options at all, which is how the debug bar's panels dump
 * - so neither Tracy's list of names nor the error page's scrubber is there to
 * hide anything, and whatever is hidden was hidden by the exporter.
 *
 * The error page itself is covered from the outside, by
 * Trilobit\Tests\Integration\Config\TracyKeepsTheSecretsTest.
 */
#[CoversClass(TracyScrubber::class)]
#[CoversClass(Secrets::class)]
final class TracyScrubberTest extends TestCase
{
    /** Made up; kept under a secret name by the objects below. */
    private const string FIRST_HIDDEN = 'made-up-value-5e1a';

    /** Made up; kept under a secret name by the objects below. */
    private const string SECOND_HIDDEN = 'made-up-value-77c3';

    /** @var array<class-string, array{class-string, string}> */
    private array $exporters = [];

    /** @var (callable(string, mixed, ?string): bool)|null */
    private $scrubber;

    protected function setUp(): void
    {
        $this->exporters = Dumper::$objectExporters;
        $this->scrubber = Debugger::getBlueScreen()->scrubber;

        TracyScrubber::install();
    }

    protected function tearDown(): void
    {
        Dumper::$objectExporters = $this->exporters;
        Debugger::getBlueScreen()->scrubber = $this->scrubber;
    }

    public function testADumpWithNoOptionsHidesWhatAnObjectKeepsUnderASecretName(): void
    {
        $holder = new class (self::FIRST_HIDDEN, self::SECOND_HIDDEN) {
            public string $author = 'Someone Made Up';

            /** @var array<string, string> */
            public array $params;

            public function __construct(public readonly string $apiToken, string $second)
            {
                $this->params = ['host' => 'db.example.com', 'password' => $second];
            }
        };

        $dump = Dumper::toText($holder);

        self::assertStringContainsString('Someone Made Up', $dump);
        self::assertStringContainsString('db.example.com', $dump);
        self::assertStringContainsString('apiToken', $dump, 'a hidden value should still be listed by name');
        self::assertStringNotContainsString(self::FIRST_HIDDEN, $dump);
        self::assertStringNotContainsString(self::SECOND_HIDDEN, $dump);
    }

    /** What the object itself says it holds is asked the same question. */
    public function testWhatAnObjectReportsAboutItselfIsJudgedToo(): void
    {
        $object = new readonly class (self::SECOND_HIDDEN) {
            public function __construct(private string $kept) {}

            /** @return array<string, string> */
            public function __debugInfo(): array
            {
                return ['shown' => 'plainly', 'password' => $this->kept];
            }
        };

        $dump = Dumper::toText($object, [Dumper::DEBUGINFO => true]);

        self::assertStringContainsString('plainly', $dump);
        self::assertStringNotContainsString(self::SECOND_HIDDEN, $dump);
    }

    /**
     * The fallback claims only what no other exporter does, so a type Tracy
     * has its own way of showing is still shown that way.
     */
    public function testTracysOwnExportersStillComeFirst(): void
    {
        self::assertSame('', array_key_last(Dumper::$objectExporters));
        self::assertStringContainsString('storage', Dumper::toText(new ArrayObject(['a' => 1])));
    }
}
